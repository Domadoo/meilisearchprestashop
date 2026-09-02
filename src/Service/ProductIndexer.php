<?php

/**
 * 2007-2026 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 * @author    Doudeau Adam, Johan Vivien
 * @copyright 2007-2026 Domadoo
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\MeiliSearch\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Service partagé d'indexation produits vers Meilisearch.
 *
 * Point unique de la logique d'indexation (SQL, typeMap, feature_values, ids_category
 * récursif, sales 3 mois, push Meili), utilisé par :
 * - controllers/front/cron.php (indexation HTTP)
 * - src/Command/IndexProductsCommand.php (CLI)
 * - src/Controller/Admin/MeiliSearchIndexController.php (admin)
 * - meilisearchprestashop.php (hooks de réindexation produit unique)
 */
class ProductIndexer
{
    /** @var \Meilisearchprestashop */
    private $module;

    /** @var string URL de base Meilisearch (avec `/` final) */
    private $meiliUrl;

    /** @var string Préfixe des index */
    private $prefix;

    /**
     * @param \Meilisearchprestashop|null $module si null, résolu via Module::getInstanceByName (contexte CLI)
     */
    public function __construct($module = null)
    {
        /** @var \Meilisearchprestashop $resolved */
        $resolved = $module ?: \Module::getInstanceByName('meilisearchprestashop');
        $this->module = $resolved;
        $this->meiliUrl = (string) \Configuration::get('MEILISEARCHPRESTASHOP_URL');
        $this->prefix = (string) \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX');
    }

    /**
     * Réindexation complète (toutes les langues, ou un sous-ensemble d'iso), settings inclus.
     *
     * @param string[]|null $isoFilter iso_code à indexer (null = toutes les langues)
     */
    public function indexAllProducts(?array $isoFilter = null, int $batchSize = 100): void
    {
        foreach (\Language::getLanguages() as $language) {
            if ($isoFilter !== null && !in_array($language['iso_code'], $isoFilter, true)) {
                continue;
            }
            $this->indexLanguage($language, null, true, $batchSize);
        }
    }

    /**
     * Réindexe un produit unique dans toutes les langues.
     * Si le produit n'est plus actif/existant, son document est retiré de l'index.
     */
    public function indexProduct(int $idProduct): void
    {
        foreach (\Language::getLanguages() as $language) {
            $this->indexLanguage($language, [$idProduct], false);
        }
    }

    /**
     * Supprime un produit de tous les index (une langue = un index).
     */
    public function deleteProduct(int $idProduct): void
    {
        foreach (\Language::getLanguages() as $language) {
            $uid = $this->indexUid($language['iso_code']);
            $this->module->requestCurlIndex(
                $this->meiliUrl . 'indexes/' . $uid . '/documents/' . $idProduct,
                null,
                'DELETE'
            );
        }
    }

    /**
     * Cœur de l'indexation pour une langue.
     *
     * @param array $language ligne Language::getLanguages()
     * @param int[]|null $productIds sous-ensemble de produits (null = tous). En mode
     *                               sous-ensemble, un produit absent (inactif/supprimé)
     *                               est retiré de l'index.
     * @param bool $applySettings appliquer les settings Meili (uniquement en full reindex)
     */
    public function indexLanguage(array $language, ?array $productIds = null, bool $applySettings = true, int $batchSize = 100): void
    {
        $idLang = (int) $language['id_lang'];
        $isoCode = $language['iso_code'];
        $uid = $this->indexUid($isoCode);

        $idFilter = '';
        if ($productIds !== null) {
            $ids = array_map('intval', $productIds);
            $idFilter = ' AND p.`id_product` IN (' . implode(',', $ids) . ')';
        }

        $sql = '
            SELECT p.*, product_shop.*, pl.*,
                m.`name` AS manufacturer_name,
                s.`name` AS supplier_name
            FROM `' . _DB_PREFIX_ . 'product` p
            ' . \Shop::addSqlAssociation('product', 'p') . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON (p.`id_product` = pl.`id_product` ' . \Shop::addSqlRestrictionOnLang('pl') . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
                ON (m.`id_manufacturer` = p.`id_manufacturer`)
            LEFT JOIN `' . _DB_PREFIX_ . 'supplier` s
                ON (s.`id_supplier` = p.`id_supplier`)
            WHERE pl.`id_lang` = ' . $idLang . '
            AND product_shop.`active` = 1' . $idFilter . '
        ';

        $products = \Db::getInstance(true)->executeS($sql);

        // Mode sous-ensemble : les produits demandés absents du résultat (inactifs/supprimés/
        // hors contexte boutique) doivent être retirés de l'index.
        if ($productIds !== null) {
            $found = array_map('intval', array_column($products ?: [], 'id_product'));
            foreach (array_map('intval', $productIds) as $requestedId) {
                if (!in_array($requestedId, $found, true)) {
                    $this->module->requestCurlIndex(
                        $this->meiliUrl . 'indexes/' . $uid . '/documents/' . $requestedId,
                        null,
                        'DELETE'
                    );
                }
            }
        }

        // L'index doit exister avant tout POST (idempotent).
        $this->ensureIndex($isoCode);

        if (!empty($products)) {
            $productIdsStr = implode(',', array_map('intval', array_column($products, 'id_product')));

            $productFeatureValues = $this->buildFeatureValues($productIdsStr);
            $productCategoryIds = $this->buildCategoryIds($productIdsStr);
            $productSales = $this->buildSales($productIdsStr);

            $typeMap = $this->typeMap();
            foreach ($products as &$product) {
                foreach ($typeMap as $field => $type) {
                    if (array_key_exists($field, $product) && $product[$field] !== null) {
                        switch ($type) {
                            case 'int':
                                $product[$field] = (int) $product[$field];
                                break;
                            case 'float':
                                $product[$field] = (float) $product[$field];
                                break;
                            case 'bool':
                                $product[$field] = (bool) $product[$field];
                                break;
                        }
                    }
                }
                $id = $product['id_product'];
                $product['feature_values'] = $productFeatureValues[$id] ?? [];
                $product['ids_category'] = $productCategoryIds[$id] ?? [];
                $product['sales'] = $productSales[$id] ?? 0;
            }

            unset($product);

            foreach (array_chunk($products, $batchSize) as $chunk) {
                $this->module->requestCurlIndex(
                    $this->meiliUrl . 'indexes/' . $uid . '/documents',
                    json_encode($chunk)
                );
            }
        }

        if ($applySettings) {
            $this->applySettings($isoCode);
        }
    }

    private function indexUid(string $isoCode): string
    {
        return $this->prefix . 'products_' . $isoCode;
    }

    private function ensureIndex(string $isoCode): void
    {
        $this->module->requestCurlIndex($this->meiliUrl . 'indexes', json_encode([
            'uid' => $this->indexUid($isoCode),
            'primaryKey' => 'id_product',
        ]));
    }

    private function applySettings(string $isoCode): void
    {
        $base = $this->meiliUrl . 'indexes/' . $this->indexUid($isoCode) . '/settings/';

        $this->module->requestCurlIndex($base . 'pagination', json_encode(['maxTotalHits' => 9999]), 'PATCH');
        $this->module->requestCurlIndex($base . 'sortable-attributes', json_encode(['name', 'price', 'date_add', 'quantity', 'sales']), 'PUT');
        $this->module->requestCurlIndex($base . 'ranking-rules', json_encode(['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness']), 'PUT');
        $this->module->requestCurlIndex($base . 'filterable-attributes', json_encode(['id_manufacturer', 'out_of_stock', 'condition', 'ids_category', 'quantity', 'feature_values', 'visibility', 'available_for_order']), 'PUT');
    }

    /**
     * feature_values à plat : [id_product => ["2-36", "2-60", ...]]
     *
     * @return array<int, string[]>
     */
    private function buildFeatureValues(string $productIdsStr): array
    {
        if ($productIdsStr === '') {
            return [];
        }

        $rows = \Db::getInstance()->executeS('
            SELECT id_product, id_feature, id_feature_value
            FROM `' . _DB_PREFIX_ . 'feature_product`
            WHERE id_product IN (' . $productIdsStr . ')
        ');

        $map = [];
        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            $map[$idProduct][] = (int) $row['id_feature'] . '-' . (int) $row['id_feature_value'];
        }

        return $map;
    }

    /**
     * ids_category avec expansion récursive des ancêtres (un produit d'une sous-catégorie
     * apparaît dans ses catégories parentes) : [id_product => [id_cat, ...ancêtres]]
     *
     * @return array<int, int[]>
     */
    private function buildCategoryIds(string $productIdsStr): array
    {
        if ($productIdsStr === '') {
            return [];
        }

        $rows = \Db::getInstance()->executeS('
            SELECT id_product, id_category
            FROM `' . _DB_PREFIX_ . 'category_product`
            WHERE id_product IN (' . $productIdsStr . ')
        ');

        $productCategoryIds = [];
        foreach ($rows as $row) {
            $productCategoryIds[(int) $row['id_product']][] = (int) $row['id_category'];
        }

        $catParent = [];
        foreach (\Db::getInstance()->executeS('
            SELECT id_category, id_parent FROM `' . _DB_PREFIX_ . 'category`
        ') as $row) {
            $catParent[(int) $row['id_category']] = (int) $row['id_parent'];
        }

        $catChainCache = [];
        foreach ($productCategoryIds as $idProduct => $cats) {
            $expanded = [];
            foreach ($cats as $catId) {
                if (!isset($catChainCache[$catId])) {
                    $chain = [];
                    $current = $catId;
                    $guard = 0;
                    while ($current > 0 && !in_array($current, $chain, true) && $guard < 1000) {
                        $chain[] = $current;
                        $current = $catParent[$current] ?? 0;
                        ++$guard;
                    }
                    $catChainCache[$catId] = $chain;
                }
                foreach ($catChainCache[$catId] as $ancestor) {
                    $expanded[$ancestor] = true;
                }
            }
            $productCategoryIds[$idProduct] = array_keys($expanded);
        }

        return $productCategoryIds;
    }

    /**
     * Ventes totales (tout temps) depuis l'agrégat natif PrestaShop `product_sale`
     * (maintenu par PS à partir des commandes valides) : [id_product => quantité vendue]
     *
     * @return array<int, int>
     */
    private function buildSales(string $productIdsStr): array
    {
        if ($productIdsStr === '') {
            return [];
        }

        $rows = \Db::getInstance()->executeS('
            SELECT `id_product`, `quantity` AS sales
            FROM `' . _DB_PREFIX_ . 'product_sale`
            WHERE `id_product` IN (' . $productIdsStr . ')
        ');

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id_product']] = (int) $row['sales'];
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function typeMap(): array
    {
        return [
            'id_product' => 'int',
            'id_supplier' => 'int',
            'id_manufacturer' => 'int',
            'id_category_default' => 'int',
            'id_shop_default' => 'int',
            'id_tax_rules_group' => 'int',
            'on_sale' => 'bool',
            'online_only' => 'bool',
            'low_stock_alert' => 'bool',
            'quantity' => 'int',
            'minimal_quantity' => 'int',
            'price' => 'float',
            'wholesale_price' => 'float',
            'unit_price_ratio' => 'float',
            'additional_shipping_cost' => 'float',
            'width' => 'float',
            'height' => 'float',
            'depth' => 'float',
            'weight' => 'float',
            'out_of_stock' => 'int',
            'additional_delivery_times' => 'bool',
            'quantity_discount' => 'bool',
            'customizable' => 'bool',
            'uploadable_files' => 'bool',
            'text_fields' => 'bool',
            'active' => 'bool',
            'id_type_redirected' => 'int',
            'available_for_order' => 'bool',
            'show_condition' => 'bool',
            'show_price' => 'bool',
            'indexed' => 'bool',
            'cache_is_pack' => 'bool',
            'cache_has_attachments' => 'bool',
            'is_virtual' => 'bool',
            'cache_default_attribute' => 'int',
            'advanced_stock_management' => 'bool',
            'pack_stock_type' => 'int',
            'state' => 'int',
            'atoosync' => 'bool',
            'id_shop' => 'int',
            'final_price' => 'float',
            'id_lang' => 'int',
        ];
    }
}
