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

        $variables     = $this->getProductSearchVariables();
        $encodedFacets = Tools::getValue('encodedFacets', '');

        $facetDistribution = MeiliSearchProductSearchProvider::$lastFacetDistribution;
        $facets            = json_decode(json_encode($facetDistribution), true) ?? [];
        $facetLabels       = $this->getFacetLabels();

        // Si des filtres sont actifs, récupère les facettes disjunctives
        if ($encodedFacets) {
            $facets = $this->getDisjunctiveFacets($facets, $encodedFacets, $facetLabels);
        }

        // Pré-groupe feature_values par id_feature pour le tpl
        $groupedFeatureValues = [];
        if (isset($facets['feature_values'])) {
            foreach ($facets['feature_values'] as $key => $count) {
                $parts       = explode('-', $key, 2);
                $featureId   = $parts[0];
                $featureName = $facetLabels['feature_names'][$featureId] ?? 'Caractéristique';
                $groupedFeatureValues[$featureId]['label']        = $featureName;
                $groupedFeatureValues[$featureId]['values'][$key] = $count;
            }
        }

        $facetsConfig = $this->buildFacetsJsConfig($facets, $facetLabels);

        $this->context->smarty->assign([
            'listing'                      => $variables,
            'meilisearch_facets'           => $facets,
            'meilisearch_facet_labels'     => $facetLabels,
            'meilisearch_grouped_features' => $groupedFeatureValues,
            'open_facets'                  => ['condition', 'available_for_order', 'id_manufacturer'],
            'current_facets_encoded'       => $encodedFacets,
        ]);

        Media::addJsDef([
            'meilisearch_facets_config'  => $facetsConfig,
            'meilisearch_encoded_facets' => $encodedFacets,
        ]);

        $this->setTemplate($template, $params, $locale);

        $cookie = Context::getContext()->cookie;
        Media::addJsDef([
            'id_statssearch' => (int)$cookie->meilisearch_id,
        ]);
    }

    /**
     * Reconstruit les facettes en mode disjunctif pour le rendu initial de la page.
     * Pour chaque groupe actif, fait une requête Meilisearch sans ce groupe
     * pour obtenir les vrais compteurs de toutes ses valeurs.
     */
    private function getDisjunctiveFacets(array $currentFacets, string $encodedFacets, array $facetLabels): array
{
    $iso_lang = $this->context->language->iso_code;
    $search   = Tools::getValue('s', '');

    $meiliUrl = Configuration::get('MEILISEARCHPRESTASHOP_URL')
        . 'indexes/' . Configuration::get('MEILISEARCHPRESTASHOP_PREFIX')
        . 'products_' . $iso_lang . '/search';

    $filtersArray = array_filter(explode('|', $encodedFacets));

    $idLang = (int) $this->context->language->id;
    $rows   = Db::getInstance()->executeS('
        SELECT id_feature, name
        FROM ' . _DB_PREFIX_ . 'feature_lang
        WHERE id_lang = ' . $idLang
    );
    $featureMap = [];
    foreach ($rows as $row) {
        $featureMap[$row['id_feature']] = $this->slugify($row['name']);
    }
    $featureMapFlipped = array_flip($featureMap);

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
                $groupedFilters['avail'][] = $value === 'stock' ? 'quantity >= 1' : 'available_for_order = true';
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

    // ── Requête sans aucun filtre → toutes les valeurs possibles ────────────
    $dataAll = [
        'q'      => $search,
        'limit'  => 0,
        'filter' => [['visibility = both'], ['available_for_order = true']],
        'facets' => ['*'],
    ];
    $responseAll  = $this->module->requestCurl($meiliUrl, json_encode($dataAll));
    $allFacets    = $responseAll && isset($responseAll->facetDistribution)
        ? json_decode(json_encode($responseAll->facetDistribution), true)
        : [];

    // On part des facettes complètes (toutes valeurs) avec compteurs à 0 par défaut
    // puis on écrase avec les vrais compteurs disjunctifs
    $mergedFacets = [];
    foreach ($allFacets as $groupKey => $values) {
        // Initialise toutes les valeurs à 0
        $mergedFacets[$groupKey] = array_fill_keys(array_keys($values), 0);
    }

    // Écrase avec les compteurs de la requête filtrée principale
    foreach ($currentFacets as $groupKey => $values) {
        foreach ($values as $valueKey => $count) {
            $mergedFacets[$groupKey][$valueKey] = $count;
        }
    }

    // ── Requêtes disjunctives : une par groupe actif ─────────────────────────
    foreach ($groupedFilters as $groupKey => $groupFilterLines) {
        $filtersWithoutGroup = array_diff_key($groupedFilters, [$groupKey => null]);

        $filter = [['visibility = both'], ['available_for_order = true']];
        foreach ($filtersWithoutGroup as $lines) {
            $filter[] = count($lines) === 1 ? $lines[0] : $lines;
        }

        $data = [
            'q'      => $search,
            'limit'  => 0,
            'filter' => $filter,
            'facets' => ['*'],
        ];

        $resp = $this->module->requestCurl($meiliUrl, json_encode($data));
        if (!$resp || !isset($resp->facetDistribution)) continue;

        $respFacets = json_decode(json_encode($resp->facetDistribution), true);

        switch ($groupKey) {
            case 'manu':
                if (isset($allFacets['id_manufacturer'])) {
                    // Repart de toutes les valeurs à 0
                    $mergedFacets['id_manufacturer'] = array_fill_keys(
                        array_keys($allFacets['id_manufacturer']), 0
                    );
                    // Écrase avec les vrais compteurs
                    if (isset($respFacets['id_manufacturer'])) {
                        foreach ($respFacets['id_manufacturer'] as $k => $v) {
                            $mergedFacets['id_manufacturer'][$k] = $v;
                        }
                    }
                }
                break;

            case 'avail':
                if (isset($allFacets['available_for_order'])) {
                    $mergedFacets['available_for_order'] = array_fill_keys(
                        array_keys($allFacets['available_for_order']), 0
                    );
                    if (isset($respFacets['available_for_order'])) {
                        foreach ($respFacets['available_for_order'] as $k => $v) {
                            $mergedFacets['available_for_order'][$k] = $v;
                        }
                    }
                }
                break;

            case 'cond':
                if (isset($allFacets['condition'])) {
                    $mergedFacets['condition'] = array_fill_keys(
                        array_keys($allFacets['condition']), 0
                    );
                    if (isset($respFacets['condition'])) {
                        foreach ($respFacets['condition'] as $k => $v) {
                            $mergedFacets['condition'][$k] = $v;
                        }
                    }
                }
                break;

            default:
                if (isset($featureMapFlipped[$groupKey])) {
                    $featureId = $featureMapFlipped[$groupKey];
                    // Repart de toutes les valeurs de ce feature à 0
                    if (isset($allFacets['feature_values'])) {
                        foreach ($allFacets['feature_values'] as $fvKey => $fvCount) {
                            if (strpos($fvKey, $featureId . '-') === 0) {
                                $mergedFacets['feature_values'][$fvKey] = 0;
                            }
                        }
                    }
                    // Écrase avec les vrais compteurs
                    if (isset($respFacets['feature_values'])) {
                        foreach ($respFacets['feature_values'] as $fvKey => $count) {
                            if (strpos($fvKey, $featureId . '-') === 0) {
                                $mergedFacets['feature_values'][$fvKey] = $count;
                            }
                        }
                    }
                }
                break;
        }
    }

    return $mergedFacets;
}


    /**
     * Construit la config JS des facettes entièrement dynamique.
     */
    private function buildFacetsJsConfig(array $facets, array $facetLabels): array
    {
        $config = [];

        foreach ($facets as $groupKey => $values) {
            switch ($groupKey) {
                case 'id_manufacturer':
                    $config[$groupKey] = ['prefix' => 'manu', 'type' => 'direct'];
                    break;
                case 'available_for_order':
                    $config[$groupKey] = [
                        'prefix' => 'avail',
                        'type'   => 'map',
                        'map'    => ['true' => 'stock', '1' => 'stock', 'false' => 'unavailable'],
                    ];
                    break;
                case 'condition':
                    $config[$groupKey] = ['prefix' => 'cond', 'type' => 'direct'];
                    break;
                case 'feature_values':
                    $featureMap = [];
                    foreach ($values as $key => $count) {
                        $featureId = explode('-', $key, 2)[0];
                        if (!isset($featureMap[$featureId])) {
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
                    $config[$groupKey] = ['prefix' => $groupKey, 'type' => 'direct'];
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