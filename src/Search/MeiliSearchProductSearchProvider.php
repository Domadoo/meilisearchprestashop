<?php
namespace PrestaShop\Module\MeiliSearch\Search;

use PrestaShop\PrestaShop\Core\Product\Search\FacetsRendererInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;
use Symfony\Component\Translation\TranslatorInterface;
use Tools;


use PrestaShop\PrestaShop\Core\Product\Search\FacetCollection; #Collection de facettes 
use PrestaShop\PrestaShop\Core\Product\Search\Facet; #Classe de la facette 
use PrestaShop\PrestaShop\Core\Product\Search\Filter; #Classe des filtres 
use PrestaShop\PrestaShop\Core\Product\Search\URLFragmentSerializer; #Pour transformer l'url


use Context;
use Configuration;

class MeiliSearchProductSearchProvider implements ProductSearchProviderInterface
{
    private $translator;
    private $module;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
        $this->module = \Module::getInstanceByName('meilisearch_prestashop');;
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

        $activeFilters = explode('|',$query->getEncodedFacets());

        $categoryfilters = $this->module->getSearchProductsFacets($allProducts,$activeFilters);

        if ( sizeof($categoryfilters->getFacets())){
            $resultObject->setFacetCollection(
                $categoryfilters //C'est ici qu'on assigne les filtres de notre fonction
            );
        }
        $resultObject->setEncodedFacets(
            $query->getEncodedFacets()
        );

        // echo '<pre>';
        // exit(print_r($resultObject->getFacetCollection()));
        return $resultObject;
    }

    private function searchInMeili($query)
    {
        $search = $query->getSearchString();
        $page = $query->getPage();
        $perPage = $query->getResultsPerPage();

        $sortOrder = $query->getSortOrder(); 
        $field = $sortOrder->getField();
        

        $meiliUrl = Configuration::get('MEILISEARCH_PRESTASHOP_URL') . 'indexes/products/search';

        $data = [
            'q' => $search,
            'limit' => 9999,
            // 'hitsPerPage' => $perPage,
            // 'page' => (int)$page,
            'attributesToRetrieve' => ["*"],
            'filter' => []
        ];

        if($field != 'relevance'){
            $direction = strtolower($sortOrder->getDirection()); // 'asc' ou 'desc'
            $meiliSort = ["{$field}:{$direction}"]; // Exemple: ['price:asc']

            $data['sort'] = $meiliSort;
        }

        $filters = $query->getEncodedFacets();
        $filtersArray = explode('|', $filters);
        foreach ($filtersArray as $filterString) {
            $filter = explode('-', $filterString);
            
            switch ($filter[0]) {
                case 'manu':
                    $data['filter'][] = 'id_manufacturer = ' . $filter[1];
                    break;
                
                case 'avail':
                    if($filter[1] == 'stock'){
                        $data['filter'][] = 'quantity >= 1';
                    }
                    break;

                case 'technology':
                    $data['filter'][] = '"feature_values" = "7-' . (int)$filter[1] . '"';
                    break;
        
                case 'compatibility':
                    $data['filter'][] = '"feature_values" = "31-' . (int)$filter[1] . '"';
                    break;
            
                default:
                    # code...
                    break;
            }
        }

        $response = $this->module->requestCurl($meiliUrl, json_encode($data));
        

        // echo '<pre>';
        // print_r($response);
        // exit(print_r($query));

        $productsChunk = array_chunk($response->hits, 48);


        return [
            'products' => $this->formatProducts($productsChunk[$page-1]),
            'allProducts' => $this->formatProducts($response->hits),
            'total' => $response->estimatedTotalHits,
        ];
    }

    private function formatProducts($products)
    {
        // Formatte les produits comme attendus par PrestaShop
        return json_decode(json_encode($products),true);
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