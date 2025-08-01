<?php

namespace PrestaShop\Module\MeiliSearch\Search;

use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;
use Symfony\Component\Translation\TranslatorInterface;

use PrestaShop\Module\Classes\MeilisearchStatssearch;
use PrestaShopLogger;
use Configuration;
use Context;

class MeiliSearchProductSearchProvider implements ProductSearchProviderInterface
{
    private $translator;
    private $module;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
        $this->module = \Module::getInstanceByName('meilisearch_prestashop');
    }

    public function runQuery(ProductSearchContext $context, ProductSearchQuery $query)
    {
        // Appelle ton index Meilisearch ici et récupère les produits :
        $result = $this->searchInMeili($query);

        $allProducts = $result['allProducts'];
        $products = $result['products']; // tableau de produits format PrestaShop
        $total = $result['total'];

        $resultObject = new ProductSearchResult();
        $resultObject->setProducts($products);
        $resultObject->setTotalProductsCount($total);
        $resultObject->setAvailableSortOrders($this->getAvailableSortOrders($query));
        $resultObject->setCurrentSortOrder($query->getSortOrder());

        $activeFilters = explode('|', $query->getEncodedFacets());

        $categoryfilters = $this->module->getSearchProductsFacets($allProducts, $activeFilters);

        if (sizeof($categoryfilters->getFacets())) {
            $resultObject->setFacetCollection(
                $categoryfilters //C'est ici qu'on assigne les filtres de notre fonction
            );
        }
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


        $meiliUrl = Configuration::get('MEILISEARCH_PRESTASHOP_URL') . 'indexes/'. Configuration::get('MEILISEARCH_PRESTASHOP_PREFIX') .'products_'.$iso_lang.'/search';

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

        foreach ($filtersArray as $filterString) {
            $filter = explode('-', $filterString);

            if (count($filter) !== 2) {
                continue; // Format inattendu, on skip
            }

            list($facetType, $value) = $filter;

            switch ($facetType) {
                case 'manu':
                    $data['filter'][] = 'id_manufacturer = ' . (int)$value;
                    break;

                case 'avail':
                    if ($value === 'stock') {
                        $data['filter'][] = 'quantity >= 1';
                    } elseif ($value === 'available') {
                        $data['filter'][] = 'available_for_order = 1';
                    }
                    break;

                case 'technology':
                    // Meilisearch : recherche exacte dans tableau string
                    $data['filter'][] = '"feature_values" = "7-' . (int)$value . '"';
                    break;

                case 'compatibility':
                    $data['filter'][] = '"feature_values" = "31-' . (int)$value . '"';
                    break;

                case 'type':
                    $data['filter'][] = '"feature_values" = "6-' . (int)$value . '"';
                    break;

                case 'function':
                    $data['filter'][] = '"feature_values" = "12-' . (int)$value . '"';
                    break;

                default:
                    // Si jamais tu veux gérer d'autres filtres un jour
                    break;
            }
        }

        $response = $this->module->requestCurl($meiliUrl, json_encode($data));
        if(!$response || !isset($response->hits) || !is_array($response->hits)) {
            return [
                'products' => [],
                'allProducts' => [],
                'total' => 0,
            ];
        }

        
        $productsChunk = array_chunk($response->hits, 48);

        if(!key_exists(0, $productsChunk)){
            $productsChunk[0] = [];
        }

        $newSearch = new MeilisearchStatssearch();
        $newSearch->query = $search;
        $newSearch->nb_results = $response->estimatedTotalHits;
        $newSearch->save();
        
        return [
            'products' => $this->formatProducts($productsChunk[$page - 1]),
            'allProducts' => $this->formatProducts($response->hits),
            'total' => $response->estimatedTotalHits,
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
