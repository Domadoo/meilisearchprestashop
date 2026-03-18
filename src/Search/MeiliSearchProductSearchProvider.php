<?php
/**
 * 2007-2025 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 * @author    Doudeau Adam, Johan Vivien
 * @copyright 2007-2025 Domadoo
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\MeiliSearch\Search;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;
use Symfony\Component\Translation\TranslatorInterface;
use PrestaShop\Module\Classes\MeilisearchStatssearch;
use Configuration;
use Context;
use Db;
use PrestaShopLogger;

class MeiliSearchProductSearchProvider implements ProductSearchProviderInterface
{
    private $translator;
    private $module;

    public static $lastFacetDistribution = null;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
        $this->module     = \Module::getInstanceByName('meilisearchprestashop');
    }

    public function runQuery(ProductSearchContext $context, ProductSearchQuery $query)
    {
        $result = $this->searchInMeili($query);

        self::$lastFacetDistribution = $result['facets'] ?? null;

        $resultObject = new ProductSearchResult();
        $resultObject->setProducts($result['products']);
        $resultObject->setTotalProductsCount($result['total']);
        $resultObject->setAvailableSortOrders($this->getAvailableSortOrders($query));
        $resultObject->setCurrentSortOrder($query->getSortOrder());
        $resultObject->setEncodedFacets($query->getEncodedFacets());

        return $resultObject;
    }

    private function getFeatureMap(): array
    {
        $idLang = (int) Context::getContext()->language->id;
        $rows   = Db::getInstance()->executeS('
            SELECT id_feature, name
            FROM ' . _DB_PREFIX_ . 'feature_lang
            WHERE id_lang = ' . $idLang
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row['id_feature']] = $this->slugify($row['name']);
        }
        return $map;
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        return trim($text, '_');
    }

    private function searchInMeili($query)
{
    $context  = Context::getContext();
    $iso_lang = $context->language->iso_code;

    $search    = \Tools::getValue('encodedFacets', '');
    $filters   = \Tools::getValue('encodedFacets', '');
    $page      = $query->getPage();
    $perPage   = $query->getResultsPerPage();
    $sortOrder = $query->getSortOrder();
    $field     = $sortOrder->getField();
    $search    = $query->getSearchString();

    $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL')
        . 'indexes/' . \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX')
        . 'products_' . $iso_lang . '/search';

    $baseData = [
        'q'                    => $search,
        'limit'                => 9999,
        'attributesToRetrieve' => ['*'],
        'filter'               => [],
        'facets'               => ['*'],
    ];

    if ($field !== 'relevance') {
        $baseData['sort'] = [$field . ':' . strtolower($sortOrder->getDirection())];
    }

    // ── Parse les filtres en groupes ─────────────────────────────────────────
    $filtersArray      = array_filter(explode('|', $filters));
    $featureMap        = $this->getFeatureMap();
    $featureMapFlipped = array_flip($featureMap);

    // $groupedFilters['manu']        = ['id_manufacturer = 374', ...]
    // $groupedFilters['functions']   = ['"feature_values" = "12-61"', ...]
    $groupedFilters = $this->parseFilters($filtersArray, $featureMapFlipped);

    // ── Requête principale (avec tous les filtres actifs) ────────────────────
    $data = $baseData;
    $data['filter'] = $this->buildFilterArray($groupedFilters);
    $response = $this->module->requestCurl($meiliUrl, json_encode($data));

    if (!$response || !isset($response->hits) || !is_array($response->hits)) {
        return [
            'products'        => [],
            'allProducts'     => [],
            'total'           => 0,
            'facets'          => null,
            'facets_per_group'=> [],
        ];
    }

    // ── Requêtes disjunctives : une par groupe actif ─────────────────────────
    // Pour chaque groupe, on retire ses filtres et on récupère les compteurs
    $facetsPerGroup = [];

    foreach ($groupedFilters as $groupKey => $groupFilterLines) {
        $filtersWithoutGroup = array_diff_key($groupedFilters, [$groupKey => null]);
        $dataForGroup = $baseData;
        $dataForGroup['limit'] = 0; // on veut juste les facettes, pas les hits
        $dataForGroup['filter'] = $this->buildFilterArray($filtersWithoutGroup);

        $resp = $this->module->requestCurl($meiliUrl, json_encode($dataForGroup));
        if ($resp && isset($resp->facetDistribution)) {
            $facetsPerGroup[$groupKey] = json_decode(json_encode($resp->facetDistribution), true);
        }
    }

    // ── Requête sans aucun filtre (pour les groupes non actifs) ─────────────
    $dataNoFilters = $baseData;
    $dataNoFilters['limit'] = 0;
    $dataNoFilters['filter'] = [['visibility = both'], ['available_for_order = true']];
    $responseNoFilters = $this->module->requestCurl($meiliUrl, json_encode($dataNoFilters));
    $facetsNoFilters = $responseNoFilters
        ? json_decode(json_encode($responseNoFilters->facetDistribution), true)
        : [];

    // ── Fusion : construit la facetDistribution finale ───────────────────────
    // Pour chaque groupe :
    //   - s'il est actif → on prend ses compteurs depuis la requête sans ce groupe
    //   - s'il n'est pas actif → on prend ses compteurs depuis la requête principale
    $mergedFacets = json_decode(json_encode($response->facetDistribution), true) ?? [];

    foreach ($facetsPerGroup as $groupKey => $groupFacets) {
        // Trouve le vrai nom de facette Meilisearch correspondant à ce groupe
        $meiliGroupKey = $this->getMeiliGroupKey($groupKey, $featureMap);
        if ($meiliGroupKey && isset($groupFacets[$meiliGroupKey])) {
            $mergedFacets[$meiliGroupKey] = $groupFacets[$meiliGroupKey];
        } elseif ($meiliGroupKey === 'feature_values' && isset($groupFacets['feature_values'])) {
            // Pour feature_values, on fusionne les sous-clés du groupe concerné
            $featureId = $this->getFeatureIdFromGroupKey($groupKey, $featureMap);
            if ($featureId) {
                foreach ($groupFacets['feature_values'] as $fvKey => $count) {
                    if (strpos($fvKey, $featureId . '-') === 0) {
                        $mergedFacets['feature_values'][$fvKey] = $count;
                    }
                }
            }
        }
    }

    $productsChunk = array_chunk($response->hits, $perPage);
    if (!isset($productsChunk[0])) {
        $productsChunk[0] = [];
    }

    // Stats
    $cookie = $context->cookie;
    if ((!isset($cookie->meilisearch_id) || !isset($cookie->meilisearch_query) || $cookie->meilisearch_query != $search) && !empty($search)) {
        $newSearch              = new MeilisearchStatssearch();
        $newSearch->query       = mb_strtolower($search);
        $newSearch->nb_results  = $response->estimatedTotalHits;
        $newSearch->id_customer = isset($context->customer) ? $context->customer->id : null;
        $newSearch->id_lang     = $context->language->id;
        $newSearch->save();
        $cookie->meilisearch_id    = $newSearch->id;
        $cookie->meilisearch_query = $search;
        unset($cookie->meilisearch_product_id);
    }

    return [
        'products'    => $this->formatProducts($productsChunk[$page - 1]),
        'allProducts' => $this->formatProducts($response->hits),
        'total'       => $response->estimatedTotalHits,
        'facets'      => (object)$mergedFacets,
    ];
}


    private function formatProducts($products): array
    {
        return json_decode(json_encode($products), true);
    }

    private function parseFilters(array $filtersArray, array $featureMapFlipped): array
{
    $groupedFilters = [];

    foreach ($filtersArray as $filterString) {
        $dashPos = strpos($filterString, '-');
        if ($dashPos === false) continue;

        $prefix = substr($filterString, 0, $dashPos);
        $value  = substr($filterString, $dashPos + 1);

        switch ($prefix) {
            case 'manu':
                $groupedFilters['manu'][] = 'id_manufacturer = ' . (int)$value;
                break;
            case 'avail':
                if ($value === 'stock') {
                    $groupedFilters['avail'][] = 'quantity >= 1';
                } elseif ($value === 'available') {
                    $groupedFilters['avail'][] = 'available_for_order = true';
                }
                break;
            case 'cond':
                $groupedFilters['cond'][] = 'condition = "' . pSQL($value) . '"';
                break;
            default:
                if (isset($featureMapFlipped[$prefix])) {
                    $featureId = $featureMapFlipped[$prefix];
                    $groupedFilters[$prefix][] = '"feature_values" = "' . $featureId . '-' . (int)$value . '"';
                }
                break;
        }
    }

    return $groupedFilters;
}

/**
 * Construit le tableau filter Meilisearch depuis les groupes parsés.
 * Filtres de base toujours inclus + ET entre groupes + OU dans un groupe.
 */
private function buildFilterArray(array $groupedFilters): array
{
    $filter = [['visibility = both'], ['available_for_order = true']];

    foreach ($groupedFilters as $filtersOfType) {
        if (count($filtersOfType) === 1) {
            $filter[] = $filtersOfType[0];
        } else {
            $filter[] = $filtersOfType; // OU implicite dans le groupe
        }
    }

    return $filter;
}

/**
 * Retourne la clé Meilisearch (facetDistribution) correspondant à un groupe encodedFacets.
 */
private function getMeiliGroupKey(string $groupKey, array $featureMap): ?string
{
    switch ($groupKey) {
        case 'manu':  return 'id_manufacturer';
        case 'avail': return 'available_for_order';
        case 'cond':  return 'condition';
        default:
            // C'est un groupe feature → toujours 'feature_values' dans Meilisearch
            if (in_array($groupKey, $featureMap)) {
                return 'feature_values';
            }
            return null;
    }
}

/**
 * Retourne l'id_feature correspondant à un slug de groupe (ex: 'functions' → '12').
 */
private function getFeatureIdFromGroupKey(string $groupKey, array $featureMap): ?string
{
    $flipped = array_flip($featureMap);
    return $flipped[$groupKey] ?? null;
}


    public function getAvailableSortOrders(ProductSearchQuery $query): array
    {
        return [
            (new SortOrder('meilisearch', 'relevance', 'asc'))
                ->setLabel($this->translator->trans('Relevance', [], 'Shop.Theme.Catalog')),
            (new SortOrder('meilisearch', 'name', 'asc'))
                ->setLabel($this->translator->trans('Name, A to Z', [], 'Shop.Theme.Catalog')),
            (new SortOrder('meilisearch', 'name', 'desc'))
                ->setLabel($this->translator->trans('Name, Z to A', [], 'Shop.Theme.Catalog')),
            (new SortOrder('meilisearch', 'price', 'asc'))
                ->setLabel($this->translator->trans('Price, low to high', [], 'Shop.Theme.Catalog')),
            (new SortOrder('meilisearch', 'price', 'desc'))
                ->setLabel($this->translator->trans('Price, high to low', [], 'Shop.Theme.Catalog')),
        ];
    }
}