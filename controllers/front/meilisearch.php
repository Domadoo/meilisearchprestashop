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
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\MeiliSearch\Listing\MeilisearchListingControllerTrait;
use PrestaShop\Module\MeiliSearch\Search\MeiliSearchProductSearchProvider;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;

class MeilisearchprestashopMeilisearchModuleFrontController extends ProductListingFrontController
{
    use MeilisearchListingControllerTrait;

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
        $query->setPage(max(1, (int) Tools::getValue('page', 1)));
        $query->setResultsPerPage(48);
        $_GET['resultsPerPage'] = 48;

        if ($encodedSortOrder = Tools::getValue('order')) {
            $query->setSortOrder(SortOrder::newFromString($encodedSortOrder));
        } else {
            $query->setSortOrder(new SortOrder('meilisearch', 'relevance', 'ASC'));
        }

        $encodedFacets = Tools::getValue('encodedFacets', '');
        if ($encodedFacets) {
            $query->setEncodedFacets($encodedFacets);
        }

        return $query;
    }

    public function getProductSearchContext()
    {
        return new ProductSearchContext($this->context);
    }

    public function getDefaultProductSearchProvider()
    {
        return new MeiliSearchProductSearchProvider($this->getTranslator(), $this->context);
    }

    public function getListingLabel()
    {
        /** @var Module|null $module */
        $module = Module::getInstanceByName('meilisearchprestashop') ?: null;
        $this->module = $module;

        return $this->module->l('Search results', 'meilisearch');
    }

    protected function getAjaxProductSearchVariables()
    {
        $variables = parent::getAjaxProductSearchVariables();

        $facetDistribution = MeiliSearchProductSearchProvider::$lastFacetDistribution;
        $facets = json_decode(json_encode($facetDistribution), true) ?? [];

        $variables['meilisearch_facets'] = $facets;

        return $variables;
    }

    protected function doProductSearch($template, $params = [], $locale = null)
    {
        $isXhr = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($isXhr) {
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            $this->ajaxRender(json_encode($this->getAjaxProductSearchVariables()));

            return;
        }

        $variables = $this->getProductSearchVariables();
        $encodedFacets = Tools::getValue('encodedFacets', '');

        $facetDistribution = MeiliSearchProductSearchProvider::$lastFacetDistribution;
        $facets = json_decode(json_encode($facetDistribution), true) ?? [];
        $facetLabels = $this->getFacetLabels();

        if ($encodedFacets) {
            $facets = $this->getDisjunctiveFacets($facets, $encodedFacets);
        }

        // Pré-groupe feature_values par id_feature pour le tpl
        $groupedFeatureValues = [];
        if (isset($facets['feature_values'])) {
            foreach ($facets['feature_values'] as $key => $count) {
                $parts = explode('-', $key, 2);
                $featureId = $parts[0];
                $featureName = $facetLabels['feature_names'][$featureId] ?? 'Feature';
                $groupedFeatureValues[$featureId]['label'] = $featureName;
                $groupedFeatureValues[$featureId]['values'][$key] = $count;
            }
        }

        $facetsConfig = $this->buildFacetsJsConfig($facets, $facetLabels);

        $hiddenFacets = ['out_of_stock', 'visibility', 'quantity', 'available_for_order'];

        $this->context->smarty->assign([
            'listing' => $variables,
            'meilisearch_facets' => $facets,
            'meilisearch_facet_labels' => $facetLabels,
            'meilisearch_grouped_features' => $groupedFeatureValues,
            'open_facets' => ['condition', 'availability', 'id_manufacturer'],
            'current_facets_encoded' => $encodedFacets,
            'meilisearch_hidden_facets' => $hiddenFacets,
        ]);

        Media::addJsDef([
            'meilisearch_facets_config' => $facetsConfig,
            'meilisearch_encoded_facets' => $encodedFacets,
        ]);

        $this->setTemplate($template, $params, $locale);

        $cookie = $this->context->cookie;
        Media::addJsDef([
            // @phpstan-ignore-next-line
            'id_statssearch' => (int) $cookie->meilisearch_id,
        ]);
    }

    public function setMedia()
    {
        parent::setMedia();

        /** @var Module|null $module */
        $module = Module::getInstanceByName('meilisearchprestashop') ?: null;
        $this->module = $module;
        $page = Tools::getValue('page') ?: 1;

        Media::addJsDef([
            'base_url' => $this->context->link->getModuleLink($this->module->name, 'ajax', [], true),
            'page' => $page,
            'meilisearch_search_string' => Tools::getValue('s', ''),
            'meilisearch_encoded_facets' => Tools::getValue('encodedFacets', ''),
        ]);

        $this->registerJavascript(
            'meilisearch_search_js',
            'modules/' . $this->module->name . '/views/js/front/search.js'
        );

        $this->registerJavascript(
            'meilisearch_facets_js',
            'modules/' . $this->module->name . '/views/js/front/meilisearch_facets.js'
        );

        $this->registerStylesheet(
            'meilisearch_facets_css',
            'modules/' . $this->module->name . '/views/css/front/meilisearch_facets.css'
        );

        return true;
    }
}
