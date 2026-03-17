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

        $search    = $query->getSearchString();
        $page      = $query->getPage();
        $perPage   = $query->getResultsPerPage();
        $sortOrder = $query->getSortOrder();
        $field     = $sortOrder->getField();

        $meiliUrl = Configuration::get('MEILISEARCHPRESTASHOP_URL')
            . 'indexes/' . Configuration::get('MEILISEARCHPRESTASHOP_PREFIX')
            . 'products_' . $iso_lang . '/search';

        $data = [
            'q'                    => $search,
            'limit'                => 9999,
            'attributesToRetrieve' => ['*'],
            'filter'               => [],
            'facets'               => ['*'],
        ];

        if ($field !== 'relevance') {
            $data['sort'] = [$field . ':' . strtolower($sortOrder->getDirection())];
        }

        // ── Parsing des filtres ──────────────────────────────────────────────
        $filters           = \Tools::getValue('encodedFacets', '');
        $filtersArray      = array_filter(explode('|', $filters));
        $featureMap        = $this->getFeatureMap();
        $featureMapFlipped = array_flip($featureMap);
        $groupedFilters    = [];

        foreach ($filtersArray as $filterString) {
            $dashPos = strpos($filterString, '-');
            if ($dashPos === false) {
                continue;
            }
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
                    // Feature dynamique : technology-45, compatibility-4228, etc.
                    if (isset($featureMapFlipped[$prefix])) {
                        $featureId = $featureMapFlipped[$prefix];
                        $groupedFilters[$prefix][] = '"feature_values" = "' . $featureId . '-' . (int)$value . '"';
                    }
                    break;
            }
        }

        // Filtres de base
        $data['filter'] = [['visibility = both'], ['available_for_order = true']];
        $dataNoFilters  = $data;

        foreach ($groupedFilters as $filtersOfType) {
            if (count($filtersOfType) === 1) {
                $data['filter'][] = $filtersOfType[0];
            } else {
                $data['filter'][] = $filtersOfType; // OU entre valeurs du même groupe
            }
        }

        // ── Requêtes ─────────────────────────────────────────────────────────
        $response = $this->module->requestCurl($meiliUrl, json_encode($data));

        if (!$response || !isset($response->hits) || !is_array($response->hits)) {
            return [
                'products'                  => [],
                'allProducts'               => [],
                'total'                     => 0,
                'allProductsWithoutFilters' => [],
                'facets'                    => null,
            ];
        }

        $productsChunk = array_chunk($response->hits, $perPage);
        if (!isset($productsChunk[0])) {
            $productsChunk[0] = [];
        }

        // Stats de recherche
        $cookie = $context->cookie;
        if (
            (!isset($cookie->meilisearch_id)
            || !isset($cookie->meilisearch_query)
            || $cookie->meilisearch_query != $search)
            && !empty($search)
        ) {
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

        // Requête sans filtres pour avoir les compteurs complets dans facetDistribution
        $responseNoFilters = $this->module->requestCurl($meiliUrl, json_encode($dataNoFilters));

        return [
            'products'                  => $this->formatProducts($productsChunk[$page - 1]),
            'allProducts'               => $this->formatProducts($response->hits),
            'total'                     => $response->estimatedTotalHits,
            'allProductsWithoutFilters' => $this->formatProducts($responseNoFilters ? $responseNoFilters->hits : []),
            'facets'                    => $response->facetDistribution,
        ];
    }

    private function formatProducts($products): array
    {
        return json_decode(json_encode($products), true);
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