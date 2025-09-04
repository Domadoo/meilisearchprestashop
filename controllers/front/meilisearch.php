<?php

use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;
use PrestaShop\Module\MeiliSearch\Search\MeiliSearchProductSearchProvider;

class MeilisearchprestashopMeilisearchModuleFrontController extends ProductListingFrontController
{
    public $module;

    public function initContent()
    {
        parent::initContent();
        
        $this->doProductSearch('../../../modules/meilisearchprestashop/views/templates/front/search.tpl');
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
        $this->module = \Module::getInstanceByName('meilisearchprestashop');
        return $this->module->l('Search results', 'meilisearch');
    }
    
    protected function doProductSearch($template, $params = [], $locale = null)
    {
        if ($this->ajax) {
            ob_end_clean();
            header('Content-Type: application/json');
            $this->ajaxRender(json_encode($this->getAjaxProductSearchVariables()));

            return;
        } else {
            $variables = $this->getProductSearchVariables();
            $this->context->smarty->assign([
                'listing' => $variables,
            ]);
            
            $this->setTemplate($template, $params, $locale);
        }

        $cookie = Context::getContext()->cookie;
        Media::addJsDef([
            'id_statssearch' => (int)$cookie->meilisearch_id,
        ]);
    }

    public function setMedia()
    {
        parent::setMedia();

        $this->module = \Module::getInstanceByName('meilisearchprestashop');

        $page = Tools::getValue('page') ? Tools::getValue('page') : 1;
        Media::addJsDef([
            'base_url' => $this->context->link->getModuleLink($this->module->name, 'ajax', [], true),
            'page' => $page,
        ]);

        $this->registerJavascript(
            'meilisearch_search_js',
            'modules/'.$this->module->name.'/views/js/front/search.js'
        );
    }

}
