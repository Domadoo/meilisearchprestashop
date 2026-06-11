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

namespace PrestaShop\Module\MeiliSearch\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;

class MeiliSearchIndexController extends FrameworkBundleAdminController
{
    private $module;

    public function __construct()
    {
        parent::__construct();
        $this->module = \Module::getInstanceByName('meilisearchprestashop');
    }

    public function indexAction()
    {
        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');
        $meiliPrefix = \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX');
        $indexes = [];

        if ($meiliUrl) {
            $response = $this->module->requestCurl($meiliUrl . 'indexes?limit=100');
            $stats = $this->module->requestCurl($meiliUrl . 'stats');
            $indexStats = ($stats && isset($stats->indexes)) ? (array) $stats->indexes : [];

            if ($response && isset($response->results)) {
                foreach ($response->results as $index) {
                    if ($meiliPrefix && strpos($index->uid, $meiliPrefix) !== 0) {
                        continue;
                    }
                    $index->numberOfDocuments = isset($indexStats[$index->uid]) ? $indexStats[$index->uid]->numberOfDocuments : null;
                    $indexes[] = $index;
                }
            }
        }

        return $this->render('@Modules/meilisearchprestashop/views/templates/admin/index.html.twig', array_merge(
            $this->getTranslatedText(),
            [
                'indexes' => $indexes,
                'languages' => \Language::getLanguages(),
            ]
        ));
    }

    public function getTranslatedText()
    {
        $ctx = 'meilisearchindexcontroller';

        return [
            'indexingMeilisearchText' => $this->module->l('Meilisearch indexing', $ctx),
            'indexingMeillisearchProductsText' => $this->module->l('Index products in Meilisearch', $ctx),
            'indexationTitle' => $this->module->l('Indexation', $ctx),
            'settingsTitle' => $this->module->l('Settings', $ctx),
            'chooseLanguage' => $this->module->l('-- Choose a language --', $ctx),
            'reindexLanguageBtn' => $this->module->l('Reindex this language', $ctx),
            'noIndexFound' => $this->module->l('No index found.', $ctx),
            'bulkActionsPlaceholder' => $this->module->l('-- Bulk actions --', $ctx),
            'bulkReindexLabel' => $this->module->l('Reindex selection', $ctx),
            'bulkFlushLabel' => $this->module->l('Flush selection', $ctx),
            'bulkDeleteLabel' => $this->module->l('Delete selection', $ctx),
            'applyLabel' => $this->module->l('Apply', $ctx),
            'colCreatedAt' => $this->module->l('Created at', $ctx),
            'colUpdatedAt' => $this->module->l('Updated at', $ctx),
            'colActions' => $this->module->l('Actions', $ctx),
            'btnEdit' => $this->module->l('Edit', $ctx),
            'btnReindex' => $this->module->l('Reindex', $ctx),
            'btnFlush' => $this->module->l('Flush', $ctx),
            'btnDelete' => $this->module->l('Delete', $ctx),
            'confirmReindexMsg' => $this->module->l('Reindex index "%s" (replaces all documents)?', $ctx),
            'confirmFlushMsg' => $this->module->l('Flush index "%s" (delete all documents)?', $ctx),
            'confirmDeleteMsg' => $this->module->l('Delete index "%s"?', $ctx),
            'confirmBulkReindex' => $this->module->l('Reindex the %d selected index(es)?', $ctx),
            'confirmBulkFlush' => $this->module->l('Flush the %d selected index(es) (delete all documents)?', $ctx),
            'confirmBulkDelete' => $this->module->l('Delete the %d selected index(es)?', $ctx),
            'pleaseChooseLang' => $this->module->l('Please choose a language.', $ctx),
            'confirmReindexLang' => $this->module->l('Reindex language "%s"?', $ctx),
            'backToList' => $this->module->l('Back to list', $ctx),
            'pageUnderConstruction' => $this->module->l('Page under construction.', $ctx),
        ];
    }

    private function isValidIndexUid(string $uid): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_\-]+$/', $uid);
    }

    public function deleteAction($uid)
    {
        if (!$this->isValidIndexUid($uid)) {
            $this->addFlash('error', $this->module->l('Invalid index uid.', 'meilisearchindexcontroller'));

            return $this->redirectToRoute('admin_meilisearch_index_index');
        }
        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');
        $this->module->requestCurl($meiliUrl . 'indexes/' . $uid, null, 'DELETE');
        $this->addFlash('success', sprintf($this->module->l('Index "%s" deleted.', 'meilisearchindexcontroller'), $uid));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    public function flushAction($uid)
    {
        if (!$this->isValidIndexUid($uid)) {
            $this->addFlash('error', $this->module->l('Invalid index uid.', 'meilisearchindexcontroller'));

            return $this->redirectToRoute('admin_meilisearch_index_index');
        }
        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');
        $this->module->requestCurl($meiliUrl . 'indexes/' . $uid . '/documents', null, 'DELETE');
        $this->addFlash('success', sprintf($this->module->l('Documents of index "%s" deleted.', 'meilisearchindexcontroller'), $uid));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    public function bulkFlushAction(Request $request)
    {
        $uids = array_filter((array) $request->request->get('uids', []), [$this, 'isValidIndexUid']);
        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');

        foreach ($uids as $uid) {
            $this->module->requestCurl($meiliUrl . 'indexes/' . $uid . '/documents', null, 'DELETE');
        }

        $this->addFlash('success', sprintf($this->module->l('%d index(es) flushed.', 'meilisearchindexcontroller'), count($uids)));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    public function bulkDeleteAction(Request $request)
    {
        $uids = array_filter((array) $request->request->get('uids', []), [$this, 'isValidIndexUid']);
        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');

        foreach ($uids as $uid) {
            $this->module->requestCurl($meiliUrl . 'indexes/' . $uid, null, 'DELETE');
        }

        $this->addFlash('success', sprintf($this->module->l('%d index(es) deleted.', 'meilisearchindexcontroller'), count($uids)));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    public function indexProductsAction()
    {
        foreach (\Language::getLanguages() as $language) {
            $this->indexLanguage($language);
        }

        $this->addFlash('success', $this->module->l('Products successfully indexed in Meilisearch.', 'meilisearchindexcontroller'));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    public function reindexLanguageAction($uid)
    {
        $prefix = \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX');
        $needle = $prefix . 'products_';

        // uid peut être un nom d'index complet (shop1_products_fr) ou un iso_code seul (fr)
        $iso_code = strpos($uid, $needle) === 0
            ? substr($uid, strlen($needle))
            : $uid;

        foreach (\Language::getLanguages() as $language) {
            if ($language['iso_code'] === $iso_code) {
                $this->indexLanguage($language);
                $this->addFlash('success', sprintf($this->module->l('Index "%s" successfully reindexed.', 'meilisearchindexcontroller'), $uid));

                return $this->redirectToRoute('admin_meilisearch_index_index');
            }
        }

        $this->addFlash('error', sprintf($this->module->l('Unable to reindex index "%s": language not found.', 'meilisearchindexcontroller'), $uid));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    public function bulkReindexAction(Request $request)
    {
        $uids = (array) $request->request->get('uids', []);
        $prefix = \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX');
        $needle = $prefix . 'products_';
        $langByIso = array_column(\Language::getLanguages(), null, 'iso_code');
        $count = 0;

        foreach ($uids as $uid) {
            if (strpos($uid, $needle) === 0) {
                $iso_code = substr($uid, strlen($needle));
                if (isset($langByIso[$iso_code])) {
                    $this->indexLanguage($langByIso[$iso_code]);
                    ++$count;
                }
            }
        }

        $this->addFlash('success', sprintf($this->module->l('%d index(es) successfully reindexed.', 'meilisearchindexcontroller'), $count));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    private function indexLanguage(array $language)
    {
        $id_lang = $language['id_lang'];
        $iso_code = $language['iso_code'];
        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');

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
            WHERE pl.`id_lang` = ' . (int) $id_lang . '
            AND product_shop.`active` = 1
        ';

        $products = \Db::getInstance(true)->executeS($sql);

        $typeMap = [
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

        $productIds = array_column($products, 'id_product');
        $productIdsStr = implode(',', array_map('intval', $productIds));

        $featuresSql = '
            SELECT id_product, id_feature, id_feature_value
            FROM `' . _DB_PREFIX_ . 'feature_product`
            WHERE id_product IN (' . $productIdsStr . ')
        ';

        $featureResults = \Db::getInstance()->executeS($featuresSql);

        $productFeatureValues = [];
        foreach ($featureResults as $row) {
            $idProduct = (int) $row['id_product'];
            $featureKey = (int) $row['id_feature'] . '-' . (int) $row['id_feature_value'];

            if (!isset($productFeatureValues[$idProduct])) {
                $productFeatureValues[$idProduct] = [];
            }

            $productFeatureValues[$idProduct][] = $featureKey;
        }

        $categoriesSql = '
            SELECT id_product, id_category
            FROM `' . _DB_PREFIX_ . 'category_product`
            WHERE id_product IN (' . $productIdsStr . ')
        ';
        $categoryResults = \Db::getInstance()->executeS($categoriesSql);

        $productCategoryIds = [];
        foreach ($categoryResults as $row) {
            $productCategoryIds[(int) $row['id_product']][] = (int) $row['id_category'];
        }

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
        }
        unset($product);

        $indexUid = \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') . 'products_' . $iso_code;

        $payloadIndex = json_encode([
            'uid' => $indexUid,
            'primaryKey' => 'id_product',
        ]);
        $this->module->requestCurl($meiliUrl . 'indexes', $payloadIndex);

        foreach (array_chunk($products, 200) as $chunk) {
            $this->module->requestCurl($meiliUrl . 'indexes/' . $indexUid . '/documents', json_encode($chunk));
        }

        $this->module->requestCurl($meiliUrl . 'indexes/' . $indexUid . '/settings/pagination', json_encode(['maxTotalHits' => 9999]), 'PATCH');
        $this->module->requestCurl($meiliUrl . 'indexes/' . $indexUid . '/settings/sortable-attributes', json_encode(['name', 'price', 'date_add', 'quantity']), 'PUT');
        $this->module->requestCurl($meiliUrl . 'indexes/' . $indexUid . '/settings/ranking-rules', json_encode(['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness']), 'PUT');
        $this->module->requestCurl($meiliUrl . 'indexes/' . $indexUid . '/settings/filterable-attributes', json_encode(['id_manufacturer', 'out_of_stock', 'condition', 'ids_category', 'quantity', 'feature_values', 'visibility', 'available_for_order']), 'PUT');
    }
}
