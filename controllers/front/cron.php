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

class MeilisearchprestashopCronModuleFrontController extends ModuleFrontController
{
    public function __construct()
	{
		parent::__construct();
	}
    public function postProcess()
    {

        $token = Tools::getValue('token');
        if(!$token == Configuration::get('MEILISEARCHPRESTASHOP_TOKEN_CRON'))
        {
            PrestaShopLogger::addLog($this->l('Illegal access to CRON Meilisearch'), 3);
            Tools::redirect('404');
            exit;
        }
        
        $success = false;

        if(Tools::getValue('action') == 'indexProducts'){
            $success = $this->indexProductsAction();
        }

        if($success){
            echo 'success';
            exit(http_response_code(200));
        }else {
            echo 'error';
            PrestaShopLogger::addLog($this->l('CRON error for action: ', 'cron') . Tools::getValue('action'), 3);
            exit(http_response_code(400));
        }

    }

    public function indexProductsAction()
    {

        $languages = Language::getLanguages();
        
        foreach ($languages as $language) {
            $id_lang = $language['id_lang'];
            $iso_code = $language['iso_code'];
            $meiliUrl = Configuration::get('MEILISEARCHPRESTASHOP_URL');
    
    
            $sql = '
                SELECT p.*, product_shop.*, pl.*, 
                    m.`name` AS manufacturer_name, 
                    s.`name` AS supplier_name
                FROM `' . _DB_PREFIX_ . 'product` p
                ' . Shop::addSqlAssociation('product', 'p') . '
                LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl 
                    ON (p.`id_product` = pl.`id_product` ' . Shop::addSqlRestrictionOnLang('pl') . ')
                LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m 
                    ON (m.`id_manufacturer` = p.`id_manufacturer`)
                LEFT JOIN `' . _DB_PREFIX_ . 'supplier` s 
                    ON (s.`id_supplier` = p.`id_supplier`)
                WHERE pl.`id_lang` = ' . (int) $id_lang . '
                AND product_shop.`active` = 1
            ';
    
            $products = Db::getInstance(true)->executeS($sql);
    
            
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
    
            // Récupère les IDs produits
            $productIds = array_column($products, 'id_product');
            $productIdsStr = implode(',', array_map('intval', $productIds));

            // Récupération des features
            $featuresSql = '
                SELECT id_product, id_feature, id_feature_value
                FROM `'._DB_PREFIX_.'feature_product`
                WHERE id_product IN (' . $productIdsStr . ')
            ';

            $featureResults = Db::getInstance()->executeS($featuresSql);

            // Structure à plat : [id_product => [ "2-36", "2-60", ... ]]
            $productFeatureValues = [];
            foreach ($featureResults as $row) {
                $idProduct = (int)$row['id_product'];
                $featureKey = (int)$row['id_feature'] . '-' . (int)$row['id_feature_value'];

                if (!isset($productFeatureValues[$idProduct])) {
                    $productFeatureValues[$idProduct] = [];
                }

                $productFeatureValues[$idProduct][] = $featureKey;
            }

            // Récupération de toutes les catégories de chaque produit
            $categoriesSql = '
                SELECT id_product, id_category
                FROM `'._DB_PREFIX_.'category_product`
                WHERE id_product IN (' . $productIdsStr . ')
            ';
            $categoryResults = Db::getInstance()->executeS($categoriesSql);

            $productCategoryIds = [];
            foreach ($categoryResults as $row) {
                $productCategoryIds[(int)$row['id_product']][] = (int)$row['id_category'];
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
    
    
            $payloadIndex = json_encode([
                'uid' => Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') . 'products_' . $iso_code,
                'primaryKey' => 'id_product'
            ]);
            // @phpstan-ignore-next-line
            $this->module->requestCurl($meiliUrl . 'indexes', $payloadIndex);

            $arrayProductsChunk = array_chunk($products, 200);

            // Envoi à Meilisearch
            foreach ($arrayProductsChunk as $arrayProducts) {
                // @phpstan-ignore-next-line
                $this->module->requestCurl($meiliUrl . 'indexes/'. Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') .'products_'.$iso_code.'/documents', json_encode($arrayProducts));
            }

            // @phpstan-ignore-next-line
            $this->module->requestCurl($meiliUrl . 'indexes/'. Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') .'products_'.$iso_code.'/settings/pagination', json_encode(['maxTotalHits' => 9999]), 'PATCH');
            // @phpstan-ignore-next-line
            $this->module->requestCurl($meiliUrl . 'indexes/'. Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') .'products_'.$iso_code.'/settings/sortable-attributes', json_encode(['name','price', 'date_add', 'quantity']), 'PUT');
            // @phpstan-ignore-next-line
            $this->module->requestCurl($meiliUrl . 'indexes/'. Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') .'products_'.$iso_code.'/settings/ranking-rules', json_encode(["sort","words","typo","proximity","attribute","exactness"]), 'PUT');
            // @phpstan-ignore-next-line
            $this->module->requestCurl($meiliUrl . 'indexes/'. Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') .'products_'.$iso_code.'/settings/filterable-attributes', json_encode(['id_manufacturer', 'out_of_stock', 'condition', 'ids_category', 'quantity', 'feature_values', 'visibility', 'available_for_order']), 'PUT');
        }



        return true;
    }
}