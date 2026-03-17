<?php

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

        // ← Transmission des filtres actifs
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
        return new MeiliSearchProductSearchProvider($this->getTranslator());
    }

    public function getListingLabel()
    {
        $this->module = \Module::getInstanceByName('meilisearchprestashop');
        return $this->module->l('Search results', 'meilisearch');
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

        // Récupère facetDistribution stocké par le provider après runQuery()
        $facetDistribution = MeiliSearchProductSearchProvider::$lastFacetDistribution;
        $facets            = json_decode(json_encode($facetDistribution), true) ?? [];
        $facetLabels       = $this->getFacetLabels();

        // Pré-groupe feature_values par id_feature pour le tpl
        $groupedFeatureValues = [];
        if (isset($facets['feature_values'])) {
            foreach ($facets['feature_values'] as $key => $count) {
                $parts     = explode('-', $key, 2);
                $featureId = $parts[0];
                $featureName = $facetLabels['feature_names'][$featureId] ?? 'Caractéristique';
                $groupedFeatureValues[$featureId]['label']        = $featureName;
                $groupedFeatureValues[$featureId]['values'][$key] = $count;
            }
        }

        // Construit la config des facettes pour le JS (entièrement dynamique)
        $facetsConfig = $this->buildFacetsJsConfig($facets, $facetLabels);

        $this->context->smarty->assign([
            'listing'                      => $variables,
            'meilisearch_facets'           => $facets,
            'meilisearch_facet_labels'     => $facetLabels,
            'meilisearch_grouped_features' => $groupedFeatureValues,
            'open_facets'                  => ['condition', 'available_for_order', 'id_manufacturer'],
            'current_facets_encoded'       => Tools::getValue('encodedFacets', ''),
        ]);

        Media::addJsDef([
            'meilisearch_facets_config' => $facetsConfig,
        ]);

        $this->setTemplate($template, $params, $locale);

        $cookie = Context::getContext()->cookie;
        Media::addJsDef([
            'id_statssearch' => (int)$cookie->meilisearch_id,
        ]);
    }

    /**
     * Construit la config JS des facettes :
     * Pour chaque groupe, indique comment encoder la valeur dans encodedFacets.
     *
     * Structure retournée :
     * {
     *   "id_manufacturer": { "prefix": "manu", "type": "direct" },
     *   "available_for_order": { "prefix": "avail", "type": "map", "map": {"true": "stock", "1": "stock"} },
     *   "feature_values": { "prefix": null, "type": "feature", "feature_map": {"7": "technology", "31": "compatibility", ...} },
     *   "condition": { "prefix": "cond", "type": "direct" }
     * }
     */
    private function buildFacetsJsConfig(array $facets, array $facetLabels): array
    {
        $config = [];

        foreach ($facets as $groupKey => $values) {
            switch ($groupKey) {
                case 'id_manufacturer':
                    $config[$groupKey] = [
                        'prefix' => 'manu',
                        'type'   => 'direct',
                    ];
                    break;

                case 'available_for_order':
                    $config[$groupKey] = [
                        'prefix' => 'avail',
                        'type'   => 'map',
                        'map'    => ['true' => 'stock', '1' => 'stock', 'false' => 'unavailable'],
                    ];
                    break;

                case 'condition':
                    $config[$groupKey] = [
                        'prefix' => 'cond',
                        'type'   => 'direct',
                    ];
                    break;

                case 'feature_values':
                    // Construit dynamiquement le feature_map depuis les clés présentes
                    $featureMap = [];
                    foreach ($values as $key => $count) {
                        $featureId = explode('-', $key, 2)[0];
                        if (!isset($featureMap[$featureId])) {
                            // Génère un slug depuis le nom de la feature
                            $featureName = $facetLabels['feature_names'][$featureId] ?? 'feature';
                            $featureMap[$featureId] = $this->slugify($featureName);
                        }
                    }
                    $config[$groupKey] = [
                        'prefix'      => null,
                        'type'        => 'feature',
                        'feature_map' => $featureMap,
                    ];
                    break;

                default:
                    // Facette inconnue : on l'encode telle quelle avec son nom comme préfixe
                    $config[$groupKey] = [
                        'prefix' => $groupKey,
                        'type'   => 'direct',
                    ];
                    break;
            }
        }

        return $config;
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        return trim($text, '_');
    }

    private function getFacetLabels(): array
    {
        $idLang = (int) $this->context->language->id;
        $labels = [];

        $manufacturers = Db::getInstance()->executeS('
            SELECT id_manufacturer, name
            FROM ' . _DB_PREFIX_ . 'manufacturer
        ');
        foreach ($manufacturers as $row) {
            $labels['id_manufacturer'][$row['id_manufacturer']] = $row['name'];
        }

        $rows = Db::getInstance()->executeS('
            SELECT fv.id_feature, fv.id_feature_value, fvl.value, fl.name AS feature_name
            FROM ' . _DB_PREFIX_ . 'feature_value fv
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

        $page = Tools::getValue('page') ?: 1;

        Media::addJsDef([
            'base_url'                  => $this->context->link->getModuleLink($this->module->name, 'ajax', [], true),
            'page'                      => $page,
            'meilisearch_search_string' => Tools::getValue('s', ''),
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