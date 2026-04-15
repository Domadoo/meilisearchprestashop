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
use Cache;

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
        $encodedFacets = \Tools::getValue('encodedFacets', '');
        if ($encodedFacets) {
            $query->setEncodedFacets($encodedFacets);
        }

        $result = $this->searchInMeili($query);

        self::$lastFacetDistribution = $result['facets'] ?? null;

        $resultObject = new ProductSearchResult();
        $resultObject->setProducts($result['products']);
        $resultObject->setTotalProductsCount($result['total']);
        $resultObject->setAvailableSortOrders($this->getAvailableSortOrders($query));
        $resultObject->setCurrentSortOrder($query->getSortOrder());
        $resultObject->setEncodedFacets($encodedFacets ?: $query->getEncodedFacets());

        return $resultObject;
    }

    private function getFeatureMap(): array
    {
        $idLang = (int) Context::getContext()->language->id;

        $cache_id = 'meilisearchprestashop::getFeatureMap_' . $idLang;
        if(Cache::isStored($cache_id)){
            return Cache::retrieve($cache_id);
        }

        $rows   = Db::getInstance((bool)_PS_USE_SQL_SLAVE_)->executeS('
            SELECT id_feature, name
            FROM ' . _DB_PREFIX_ . 'feature_lang
            WHERE id_lang = ' . $idLang
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['id_feature']] = $this->slugify($row['name']);
        }

        Cache::store($cache_id, $map);
        return $map;
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        return trim($text, '_');
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
                    }
                    break;
                case 'cat':
                    $groupedFilters['cat'][] = 'id_category_default = ' . (int)$value;
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

    private function buildFilterArray(array $groupedFilters): array
    {
        $filter = [['visibility = both'], ['available_for_order = true']];

        foreach ($groupedFilters as $filtersOfType) {
            if (count($filtersOfType) === 1) {
                $filter[] = $filtersOfType[0];
            } else {
                $filter[] = $filtersOfType;
            }
        }

        return $filter;
    }

    private function getMeiliGroupKey(string $groupKey, array $featureMap): ?string
    {
        switch ($groupKey) {
            case 'manu':  return 'id_manufacturer';
            case 'avail': return 'availability';
            case 'cat':   return 'id_category_default';
            case 'cond':  return 'condition';
            default:
                if (in_array($groupKey, $featureMap)) {
                    return 'feature_values';
                }
                return null;
        }
    }

    private function getFeatureIdFromGroupKey(string $groupKey, array $featureMap): ?string
    {
        $flipped = array_flip($featureMap);
        return $flipped[$groupKey] ?? null;
    }

    private function countInStock(array $hits): int
    {
        $count = 0;
        foreach ($hits as $hit) {
            $qty = is_object($hit) ? ($hit->quantity ?? 0) : ($hit['quantity'] ?? 0);
            if ($qty > 0) {
                $count++;
            }
        }
        return $count;
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

        $filters           = \Tools::getValue('encodedFacets', '');
        $filtersArray      = array_filter(explode('|', $filters));
        $featureMap        = $this->getFeatureMap();
        $featureMapFlipped = array_flip($featureMap);
        $groupedFilters    = $this->parseFilters($filtersArray, $featureMapFlipped);

        // Requête principale avec tous les filtres
        $data           = $baseData;
        $data['filter'] = $this->buildFilterArray($groupedFilters);
        $response       = $this->module->requestCurl($meiliUrl, json_encode($data));

        if (!$response || !isset($response->hits) || !is_array($response->hits)) {
            return [
                'products' => [],
                'allProducts' => [],
                'total'    => 0,
                'facets'   => null,
            ];
        }

        // Calcul manuel du stock (quantity > 0)
        $mergedFacets = json_decode(json_encode($response->facetDistribution), true) ?? [];
        $mergedFacets['availability'] = [
            'in_stock' => $this->countInStock($response->hits),
        ];

        // Requêtes disjunctives : une par groupe actif
        foreach ($groupedFilters as $groupKey => $groupFilterLines) {
            $filtersWithoutGroup    = array_diff_key($groupedFilters, [$groupKey => null]);
            $dataForGroup           = $baseData;
            $dataForGroup['filter'] = $this->buildFilterArray($filtersWithoutGroup);

            if ($groupKey === 'avail') {
                // Pour availability on a besoin des hits pour compter la quantité
                $dataForGroup['limit']                = 9999;
                $dataForGroup['attributesToRetrieve'] = ['quantity'];
                $resp = $this->module->requestCurl($meiliUrl, json_encode($dataForGroup));
                if ($resp && isset($resp->hits)) {
                    $mergedFacets['availability'] = [
                        'in_stock' => $this->countInStock($resp->hits),
                    ];
                }
            } else {
                $dataForGroup['limit']                = 0;
                $dataForGroup['attributesToRetrieve'] = [];
                $resp = $this->module->requestCurl($meiliUrl, json_encode($dataForGroup));
                if (!$resp || !isset($resp->facetDistribution)) continue;

                $respFacets = json_decode(json_encode($resp->facetDistribution), true);
                $meiliKey   = $this->getMeiliGroupKey($groupKey, $featureMap);

                if ($meiliKey === 'feature_values') {
                    $featureId = $this->getFeatureIdFromGroupKey($groupKey, $featureMap);
                    if ($featureId && isset($respFacets['feature_values'])) {
                        foreach ($respFacets['feature_values'] as $fvKey => $count) {
                            if (strpos($fvKey, $featureId . '-') === 0) {
                                $mergedFacets['feature_values'][$fvKey] = $count;
                            }
                        }
                    }
                } elseif ($meiliKey && isset($respFacets[$meiliKey])) {
                    $mergedFacets[$meiliKey] = $respFacets[$meiliKey];
                }
            }
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
            (new SortOrder('meilisearch', 'date_add', 'desc'))
                ->setLabel($this->translator->trans('Newest first', [], 'Shop.Theme.Catalog')),
            (new SortOrder('meilisearch', 'quantity', 'desc'))
                ->setLabel($this->translator->trans('Quantity', [], 'Shop.Theme.Catalog')),
        ];
    }
}