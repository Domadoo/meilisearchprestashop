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

if (!defined('_PS_VERSION_')) {
    exit;
}

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
        }

        $variables = $this->getProductSearchVariables();
        // getProductSearchVariables() appelle runQuery() → la propriété statique est maintenant remplie

        $facetDistribution = MeiliSearchProductSearchProvider::$lastFacetDistribution;

        // Convertit stdClass → tableau associatif récursif
        $facets = json_decode(json_encode($facetDistribution), true) ?? [];

// Pré-groupe les feature_values par id_feature
$facetLabels = $this->getFacetLabels();
$groupedFeatureValues = [];

if (isset($facets['feature_values'])) {
    foreach ($facets['feature_values'] as $key => $count) {
        $parts = explode('-', $key); // "6-26" → ['6', '26']
        $featureId = $parts[0];
        $featureName = $facetLabels['feature_names'][$featureId] ?? 'Caractéristique';

        $groupedFeatureValues[$featureId]['label']        = $featureName;
        $groupedFeatureValues[$featureId]['values'][$key] = $count;
    }
}

$this->context->smarty->assign([
    'listing'                        => $variables,
    'meilisearch_facets'             => $facets,
    'meilisearch_facet_labels'       => $facetLabels,
    'meilisearch_grouped_features'   => $groupedFeatureValues, // ← nouveau
    'open_facets'                    => ['condition', 'available_for_order', 'id_manufacturer'],
    'current_facets_encoded'         => Tools::getValue('encodedFacets', ''),
]);

        $this->setTemplate($template, $params, $locale);

        $cookie = Context::getContext()->cookie;
        Media::addJsDef([
            'id_statssearch' => (int)$cookie->meilisearch_id,
        ]);
    }

    private function getFacetLabels(): array
    {
        $idLang = (int) $this->context->language->id;
        $labels = [];

        // Fabricants
        $manufacturers = Db::getInstance()->executeS('
            SELECT id_manufacturer, name
            FROM ' . _DB_PREFIX_ . 'manufacturer
        ');
        foreach ($manufacturers as $row) {
            $labels['id_manufacturer'][$row['id_manufacturer']] = $row['name'];
        }

        // Feature values + nom des features (pour regrouper)
        $rows = Db::getInstance()->executeS('
            SELECT fv.id_feature, fv.id_feature_value, fvl.value, fl.name AS feature_name
            FROM '   . _DB_PREFIX_ . 'feature_value fv
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                ON fv.id_feature_value = fvl.id_feature_value
                AND fvl.id_lang = ' . $idLang . '
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_lang fl
                ON fv.id_feature = fl.id_feature
                AND fl.id_lang = ' . $idLang . '
        ');
        foreach ($rows as $row) {
            $key = $row['id_feature'] . '-' . $row['id_feature_value'];
            $labels['feature_values'][$key]              = $row['value'];
            $labels['feature_names'][$row['id_feature']] = $row['feature_name'];
        }

        return $labels;
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
        
        $this->registerJavascript(
            'meilisearch_facets_js',
            'modules/'.$this->module->name.'/views/js/front/meilisearch_facets.js'
        );

        $this->registerStylesheet(
            'meilisearch_facets_css',
            'modules/' . $this->module->name . '/views/css/front/meilisearch_facets.css'
        );

        return true;
    }

}
