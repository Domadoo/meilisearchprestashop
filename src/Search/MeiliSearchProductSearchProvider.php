<?php
namespace PrestaShop\Module\MeiliSearch\Search;

use PrestaShop\PrestaShop\Core\Product\Search\FacetsRendererInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;
use Symfony\Component\Translation\TranslatorInterface;



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

        $products = $result['products']; // tableau de produits format PrestaShop
        $total = $result['total'];

        $resultObject = new ProductSearchResult();
        $resultObject->setProducts($products);
        $resultObject->setTotalProductsCount($total);
        $resultObject->setAvailableSortOrders($this->getAvailableSortOrders($query));
        $resultObject->setCurrentSortOrder($query->getSortOrder());

        $activeFilters = explode('|',$query->getEncodedFacets());
        $resultObject->setFacetCollection(
            $this->getSampleFacets($activeFilters)
        );

        $resultObject->setEncodedFacets(
            $query->getEncodedFacets()
        );

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
            'hitsPerPage' => $perPage,
            'page' => (int)$page,
        ];

        if($field != 'relevance'){
            $direction = strtolower($sortOrder->getDirection()); // 'asc' ou 'desc'
            $meiliSort = ["{$field}:{$direction}"]; // Exemple: ['price:asc']

            $data['sort'] = $meiliSort;
        }

        $response = $this->module->requestCurl($meiliUrl, json_encode($data));
        

        return [
            'products' => $this->formatProducts($response->hits),
            'total' => $response->totalHits,
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

    /**
     * Fonction d'explication sur comment afficher des facettes
     * @return FacetCollection
     */
    protected function getSampleFacets($activeFilters)
    { 
    
        //Gestion des filtres actifs
        $activeFiltersQueryString ='';
        $activeFiltersQueryString .= implode('|',$activeFilters);
        
        
        //Création d'une collection de facettes
        $collection = new FacetCollection();
        
        //Création d'une facette
        $facet = new Facet();
        $facet->setLabel('Facette 1')
        ->setType('custom')
        ->setDisplayed(true) //Flag pour afficher ou nom la facette
        ->setWidgetType('checkbox') //Type de widget
        ->setMultipleSelectionAllowed(true); //Défini si on peut cocher plusieurs variantes
        
        
        //Ajout de filtres à cette facette
        $encodedFactetsUrl1 = $activeFiltersQueryString != '' ? $activeFiltersQueryString."|test-1": "test-1";
        
        $filter1 = new Filter();
        $filter1->setLabel('filtre 1') //Libellé du filtre
        ->setDisplayed(true) //Flag pour afficher ou nom le filtre
        ->setActive(in_array("test-1",$activeFilters) ? true : false ) //Définition si le filtre est actif ou non
        ->setType('test') // Type du filtre
        ->setValue('2') //Valeur du filtre
        ->setNextEncodedFacets($encodedFactetsUrl1) //Url pour afficher la filtre
        ->setMagnitude(1); //Nombre de résultats du filtre
        
        //Ajout du filtre à la facette
        $facet->addFilter($filter1); 
        
        $encodedFactetsUrl2 = $activeFiltersQueryString != '' ? $activeFiltersQueryString."|test-2": "test-2";
        
        //Idem pour un 2ème filtre
        $filter2 = new Filter();
        $filter2->setLabel('filtre 2') //Libellé du filtre
        ->setDisplayed(true) //Flag pour afficher ou nom le filtre
        ->setActive(in_array("test-2",$activeFilters) ? true : false ) //Définition si le filtre est actif ou non
        ->setType('test') // Type du filtre
        ->setValue('2') //Valeur du filtre
        ->setNextEncodedFacets($encodedFactetsUrl2) //Url pour afficher la filtre
        ->setMagnitude(3); //Nombre de résultats du filtre
        //Ajout du filtre à la facette
        $facet->addFilter($filter2); 
        
        
        //Ajout de la facette à la collection
        $collection->addFacet($facet);
        
        //Renvoi de la collection de facette
        return $collection;
    }
}