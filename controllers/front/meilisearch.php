<?php

use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;
use PrestaShop\PrestaShop\Core\Product\Search\Facet;
use PrestaShop\PrestaShop\Core\Product\Search\Filter;
use PrestaShop\PrestaShop\Core\Search\Filters;
use PrestaShop\Module\MeiliSearch\Search\MeiliSearchProductSearchProvider;

class Meilisearch_prestashopMeilisearchModuleFrontController extends ProductListingFrontController
{

    public function initContent()
    {
        parent::initContent();

        $this->doProductSearch('../../../modules/meilisearch_prestashop/views/templates/front/search.tpl');
    }

    public function getProductSearchQuery()
    {
        
        $query = new ProductSearchQuery();
        $query->setQueryType('meilisearch');
        $query->setSearchString(Tools::getValue('s'));
        $query->setPage(max(1, (int)Tools::getValue('page', 1)));
        $query->setResultsPerPage(48);
        $_GET['resultsPerPage'] = 48;

        if ($encodedSortOrder = Tools::getValue('order')) {
            $query->setSortOrder(SortOrder::newFromString($encodedSortOrder));
        } else {
            $query->setSortOrder(new SortOrder('meilisearch', 'relevance', 'ASC'));
        }

        // echo '<pre>';
        // exit(print_r($query));
        return $query;
    }

    public function getProductSearchContext()
    {
        return new ProductSearchContext($this->context);
    }

    public function getDefaultProductSearchProvider()
    {
        return new MeiliSearchProductSearchProvider($this->getTranslator());
    }

    public function getListingLabel()
    {
        return $this->trans('Search results', [], 'Modules.Meilisearchprestashop.Meilisearch');
    }

}
