<?php

/**
 * 2007-2026 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 *
 * @author    Doudeau Adam, Johan Vivien
 * @copyright 2007-2026 Domadoo
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\MeiliSearch\Listing;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\MeiliSearch\Search\MeiliSearchProductSearchProvider;

trait MeilisearchListingControllerTrait
{
    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);

        return trim($text, '_');
    }

    private function getFacetLabels(): array
    {
        $idLang = (int) $this->context->language->id;

        $cache_id = 'meilisearchprestashop::getFacetLabels_' . $idLang;
        if (\Cache::isStored($cache_id)) {
            return \Cache::retrieve($cache_id);
        }

        $labels = [];

        $manufacturers = \Db::getInstance((bool) _PS_USE_SQL_SLAVE_)->executeS('
            SELECT id_manufacturer, name
            FROM ' . _DB_PREFIX_ . 'manufacturer
        ');
        foreach ($manufacturers as $row) {
            $labels['id_manufacturer'][$row['id_manufacturer']] = $row['name'];
        }

        $categories = \Db::getInstance((bool) _PS_USE_SQL_SLAVE_)->executeS('
            SELECT id_category, name
            FROM ' . _DB_PREFIX_ . 'category_lang
            WHERE id_lang = ' . $idLang
        );
        foreach ($categories as $row) {
            $labels['ids_category'][$row['id_category']] = $row['name'];
        }

        $rows = \Db::getInstance((bool) _PS_USE_SQL_SLAVE_)->executeS('
            SELECT fv.id_feature, fv.id_feature_value, fvl.value, fl.name AS feature_name
            FROM ' . _DB_PREFIX_ . 'feature_value fv
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl
                ON fv.id_feature_value = fvl.id_feature_value
                AND fvl.id_lang = ' . $idLang . '
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_lang fl
                ON fv.id_feature = fl.id_feature
                AND fl.id_lang = ' . $idLang
        );

        foreach ($rows as $row) {
            $key = $row['id_feature'] . '-' . $row['id_feature_value'];
            $labels['feature_values'][$key] = $row['value'];
            $labels['feature_names'][$row['id_feature']] = $row['feature_name'];
        }

        \Cache::store($cache_id, $labels);

        return $labels;
    }

    private function buildFacetsJsConfig(array $facets, array $facetLabels): array
    {
        $config = [];

        foreach ($facets as $groupKey => $values) {
            switch ($groupKey) {
                case 'id_manufacturer':
                    $config[$groupKey] = ['prefix' => 'manu', 'type' => 'direct'];
                    break;
                case 'available_for_order':
                    break;
                case 'availability':
                    $config[$groupKey] = [
                        'prefix' => 'avail',
                        'type' => 'map',
                        'map' => ['in_stock' => 'stock'],
                    ];
                    break;
                case 'ids_category':
                    $config[$groupKey] = ['prefix' => 'cat', 'type' => 'direct'];
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
                        'prefix' => null,
                        'type' => 'feature',
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

    /**
     * Compte les produits en stock (quantity >= 1) pour la facette synthétique
     * "availability", à partir de la distribution de facette `quantity` déjà
     * renvoyée par Meilisearch — et NON via un filtre `quantity >= 1`.
     *
     * Raison : sur les index où `quantity` est stocké en chaîne, Meilisearch
     * ignore les valeurs non numériques lors d'une comparaison `>=`, donc le
     * filtre renvoie 0 alors que des produits sont bien en stock. Sommer les
     * tranches de la distribution (clé >= 1) est insensible au type stocké.
     * "availability" étant absente de la facetDistribution native, on la
     * reconstruit ici, sinon le compteur retombe à 0.
     *
     * @param array $facets facetDistribution pouvant contenir la clé `quantity`
     */
    private function computeInStockCount(array $facets): int
    {
        if (!isset($facets['quantity']) || !is_array($facets['quantity'])) {
            return 0;
        }

        $total = 0;
        foreach ($facets['quantity'] as $qty => $count) {
            if ((int) $qty >= 1) {
                $total += (int) $count;
            }
        }

        return $total;
    }

    private function getDisjunctiveFacets(array $currentFacets, string $encodedFacets): array
    {
        $module = \Module::getInstanceByName('meilisearchprestashop');
        if (!$module instanceof \Meilisearchprestashop) {
            return $currentFacets;
        }
        $iso_lang = $this->context->language->iso_code;
        $search = \Tools::getValue('s', '');

        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL')
            . 'indexes/' . \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX')
            . 'products_' . $iso_lang . '/search';

        $filtersArray = array_filter(explode('|', $encodedFacets));

        $idLang = (int) $this->context->language->id;
        $rows = \Db::getInstance()->executeS('
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
            if ($dashPos === false) {
                continue;
            }
            $prefix = substr($filterString, 0, $dashPos);
            $value = substr($filterString, $dashPos + 1);

            switch ($prefix) {
                case 'manu':
                    $groupedFilters['manu'][] = 'id_manufacturer = ' . (int) $value;
                    break;
                case 'avail':
                    if ($value === 'stock') {
                        $groupedFilters['avail'][] = 'quantity >= 1';
                    }
                    break;
                case 'cat':
                    $groupedFilters['cat'][] = 'ids_category = ' . (int) $value;
                    break;
                case 'cond':
                    $groupedFilters['cond'][] = 'condition = "' . pSQL($value) . '"';
                    break;
                default:
                    if (isset($featureMapFlipped[$prefix])) {
                        $featureId = $featureMapFlipped[$prefix];
                        $groupedFilters[$prefix][] = '"feature_values" = "' . $featureId . '-' . (int) $value . '"';
                    }
                    break;
            }
        }

        // Base filter includes context filters (e.g. ids_category for category pages)
        $baseFilter = [['visibility = both'], ['available_for_order = true']];
        foreach (MeiliSearchProductSearchProvider::$contextFilters as $cf) {
            $baseFilter[] = $cf;
        }

        // Requête sans filtre utilisateur → toutes les valeurs possibles
        $dataAll = [
            'q' => $search,
            'limit' => 0,
            'filter' => $baseFilter,
            'facets' => ['*'],
        ];
        $responseAll = $module->requestCurlSearch($meiliUrl, json_encode($dataAll));
        $allFacets = $responseAll && isset($responseAll->facetDistribution)
            ? json_decode(json_encode($responseAll->facetDistribution), true)
            : [];

        // Initialise toutes les valeurs à 0
        $mergedFacets = [];
        foreach ($allFacets as $groupKey => $values) {
            $mergedFacets[$groupKey] = array_fill_keys(array_keys($values), 0);
        }

        // Stock : somme des tranches quantity >= 1 de la requête principale, qui
        // reflète déjà tous les filtres utilisateurs actifs (comportement disjunctif
        // correct tant que le filtre stock lui-même n'est pas coché — auquel cas le
        // bloc 'avail' plus bas le recalcule en excluant son propre filtre).
        $mergedFacets['availability'] = [
            'in_stock' => $this->computeInStockCount($currentFacets),
        ];

        // Écrase avec les compteurs de la requête filtrée principale
        foreach ($currentFacets as $groupKey => $values) {
            if ($groupKey === 'availability') {
                continue;
            }
            if (is_array($values)) {
                foreach ($values as $valueKey => $count) {
                    $mergedFacets[$groupKey][$valueKey] = $count;
                }
            }
        }

        // Requêtes disjunctives : une par groupe actif
        foreach ($groupedFilters as $groupKey => $groupFilterLines) {
            $filtersWithoutGroup = array_diff_key($groupedFilters, [$groupKey => null]);

            $filter = $baseFilter;
            foreach ($filtersWithoutGroup as $lines) {
                $filter[] = count($lines) === 1 ? $lines[0] : $lines;
            }

            if ($groupKey === 'avail') {
                // Distribution quantity avec les AUTRES filtres actifs, puis somme des tranches >= 1
                $dataCount = [
                    'q' => $search,
                    'limit' => 0,
                    'filter' => $filter,
                    'facets' => ['quantity'],
                ];
                $respCount = $module->requestCurlSearch($meiliUrl, json_encode($dataCount));
                $qtyFacets = $respCount && isset($respCount->facetDistribution)
                    ? json_decode(json_encode($respCount->facetDistribution), true)
                    : [];
                $mergedFacets['availability'] = [
                    'in_stock' => $this->computeInStockCount($qtyFacets),
                ];
                continue;
            }

            $data = [
                'q' => $search,
                'limit' => 0,
                'filter' => $filter,
                'facets' => ['*'],
            ];

            $resp = $module->requestCurlSearch($meiliUrl, json_encode($data));
            if (!$resp || !isset($resp->facetDistribution)) {
                continue;
            }

            $respFacets = json_decode(json_encode($resp->facetDistribution), true);

            switch ($groupKey) {
                case 'manu':
                    if (isset($allFacets['id_manufacturer'])) {
                        $mergedFacets['id_manufacturer'] = array_fill_keys(
                            array_keys($allFacets['id_manufacturer']), 0
                        );
                        if (isset($respFacets['id_manufacturer'])) {
                            foreach ($respFacets['id_manufacturer'] as $k => $v) {
                                $mergedFacets['id_manufacturer'][$k] = $v;
                            }
                        }
                    }
                    break;

                case 'cat':
                    if (isset($allFacets['ids_category'])) {
                        $mergedFacets['ids_category'] = array_fill_keys(
                            array_keys($allFacets['ids_category']), 0
                        );
                        if (isset($respFacets['ids_category'])) {
                            foreach ($respFacets['ids_category'] as $k => $v) {
                                $mergedFacets['ids_category'][$k] = $v;
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
                        if (isset($allFacets['feature_values'])) {
                            foreach ($allFacets['feature_values'] as $fvKey => $fvCount) {
                                if (strpos($fvKey, $featureId . '-') === 0) {
                                    $mergedFacets['feature_values'][$fvKey] = 0;
                                }
                            }
                        }
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
}
