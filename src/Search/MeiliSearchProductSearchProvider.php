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
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

use PrestaShop\Module\Classes\MeilisearchStatssearch;
use Configuration;
use Context;

class MeiliSearchProductSearchProvider implements ProductSearchProviderInterface
{
    private $translator;
    private $module;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
        $this->module = \Module::getInstanceByName('meilisearchprestashop');
    }

    public function runQuery(ProductSearchContext $context, ProductSearchQuery $query)
    {
        // Appelle ton index Meilisearch ici et récupère les produits :
        $result = $this->searchInMeili($query);

        $allProducts = $result['allProducts'];
        $products = $result['products']; // tableau de produits format PrestaShop
        $total = $result['total'];
        $allProductsWithoutFilters = $result['allProductsWithoutFilters'];

        $resultObject = new ProductSearchResult();
        $resultObject->setProducts($products);
        $resultObject->setTotalProductsCount($total);
        $resultObject->setAvailableSortOrders($this->getAvailableSortOrders($query));
        $resultObject->setCurrentSortOrder($query->getSortOrder());

        $activeFilters = explode('|', $query->getEncodedFacets());

        $categoryfilters = $this->module->getSearchProductsFacets($allProductsWithoutFilters, $activeFilters);

        if (sizeof($categoryfilters->getFacets())) {
            $resultObject->setFacetCollection(
                $categoryfilters //C'est ici qu'on assigne les filtres de notre fonction
            );
        }
        // echo '<pre>';
        // exit(print_r($categoryfilters, true));
        $resultObject->setEncodedFacets(
            $query->getEncodedFacets()
        );

        return $resultObject;
    }

    private function searchInMeili($query)
    {
        $context = Context::getContext();
        $iso_lang = $context->language->iso_code;

        $search = $query->getSearchString();
        $page = $query->getPage();
        $perPage = $query->getResultsPerPage();

        $sortOrder = $query->getSortOrder();
        $field = $sortOrder->getField();


        $meiliUrl = Configuration::get('MEILISEARCHPRESTASHOP_URL') . 'indexes/'. Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') .'products_'.$iso_lang.'/search';

        $data = [
            'q' => $search,
            'limit' => 9999,
            'attributesToRetrieve' => ["*"],
            'filter' => []
        ];

        if ($field != 'relevance') {
            $direction = strtolower($sortOrder->getDirection()); // 'asc' ou 'desc'
            $meiliSort = ["{$field}:{$direction}"]; // Exemple: ['price:asc']

            $data['sort'] = $meiliSort;
        }

        $filters = $query->getEncodedFacets();
        $filtersArray = explode('|', $filters);
        $data['filter'] = [];

        
        $groupedFilters = []; // Regrouper par type de facet

        foreach ($filtersArray as $filterString) {
            $filter = explode('-', $filterString);

            if (count($filter) !== 2) {
                continue; // Format inattendu
            }

            list($facetType, $value) = $filter;

            switch ($facetType) {
                case 'manu':
                    $groupedFilters['manu'][] = 'id_manufacturer = ' . (int)$value;
                    break;

                case 'avail':
                    if ($value === 'stock') {
                        $groupedFilters['avail'][] = 'quantity >= 1';
                    } elseif ($value === 'available') {
                        $groupedFilters['avail'][] = 'available_for_order = 1';
                    }
                    break;

                case 'technology':
                    $groupedFilters['technology'][] = '"feature_values" = "7-' . (int)$value . '"';
                    break;

                case 'compatibility':
                    $groupedFilters['compatibility'][] = '"feature_values" = "31-' . (int)$value . '"';
                    break;

                case 'type':
                    $groupedFilters['type'][] = '"feature_values" = "6-' . (int)$value . '"';
                    break;

                case 'function':
                    $groupedFilters['function'][] = '"feature_values" = "12-' . (int)$value . '"';
                    break;

                default:
                    break;
            }
        }

        // Construction du tableau final
        $data['filter'] = [['visibility = both'], ['available_for_order = true']];
        $dataNoFilters = $data;
        foreach ($groupedFilters as $filtersOfType) {
            if (count($filtersOfType) === 1) {
                // Juste un filtre → pas besoin de sous-tableau
                $data['filter'][] = $filtersOfType[0];
            } else {
                // Plusieurs filtres du même type → OU implicite
                $data['filter'][] = $filtersOfType;
            }
        }

        $response = $this->module->requestCurl($meiliUrl, json_encode($data));
        if(!$response || !isset($response->hits) || !is_array($response->hits)) {
            return [
                'products' => [],
                'allProducts' => [],
                'total' => 0,
                'allProductsWithoutFilters' => []
            ];
        }

        
        $productsChunk = array_chunk($response->hits, 48);

        if(!key_exists(0, $productsChunk)){
            $productsChunk[0] = [];
        }

        $cookie = $context->cookie;

        if((!isset($cookie->meilisearch_id) || !isset($cookie->meilisearch_query) || $cookie->meilisearch_query != $search) && !empty($search)) {
            $newSearch = new MeilisearchStatssearch();
            $newSearch->query = mb_strtolower($search);
            $newSearch->nb_results = $response->estimatedTotalHits;
            $newSearch->id_customer = isset($context->customer) ? $context->customer->id : null;
            $newSearch->id_lang = $context->language->id; 
            $newSearch->save();

            $cookie->meilisearch_id = $newSearch->id;
            $cookie->meilisearch_query = $search;
            unset($cookie->meilisearch_product_id);
        }

        return [
            'products' => $this->formatProducts($productsChunk[$page - 1]),
            'allProducts' => $this->formatProducts($response->hits),
            'total' => $response->estimatedTotalHits,
            'allProductsWithoutFilters' => $this->formatProducts($this->module->requestCurl($meiliUrl, json_encode($dataNoFilters))->hits)
        ];
    }

    private function formatProducts($products)
    {
        // Formatte les produits comme attendus par PrestaShop
        return json_decode(json_encode($products), true);
    }

    public function getAvailableSortOrders(ProductSearchQuery $query)
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
