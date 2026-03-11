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

namespace PrestaShop\Module\MeiliSearch\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use PrestaShopBundle\Security\Annotation\AdminSecurity;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Configuration;
use Context;
use Db;
use Shop;
use Language;

class MeiliSearchConfigurationController extends FrameworkBundleAdminController
{
    private $module;

    public function __construct()
    {
        parent::__construct();
        // Récupère une instance fonctionnelle du module
        $this->module = \Module::getInstanceByName('meilisearchprestashop');
    }


    public function indexAction()
    {
        // Juste la vue avec le bouton
        return $this->render('@Modules/meilisearchprestashop/views/templates/admin/index.html.twig', $this->getTranslatedText());
    }

    public function getTranslatedText(){
        return array(
            'indexingMeilisearchText' => $this->module->l('Meilisearch indexing', 'meilisearchconfigurationcontroller'),
            'indexingMeillisearchProductsText'=> $this->module->l('Index products in Meilisearch', 'meilisearchconfigurationcontroller')
        );
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
            }
            unset($product);
    
    
            $payloadIndex = json_encode([
                'uid' => Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') . 'products_' . $iso_code,
                'primaryKey' => 'id_product'
            ]);
            $this->module->requestCurl($meiliUrl . 'indexes', $payloadIndex);
    
            $arrayProductsChunk = array_chunk($products, 200);
    
            // Envoi à Meilisearch
            foreach ($arrayProductsChunk as $arrayProducts) {
                $this->module->requestCurl($meiliUrl . 'indexes/'. Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') .'products_'.$iso_code.'/documents', json_encode($arrayProducts));
            }
    
            $this->module->requestCurl($meiliUrl . 'indexes/'. Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') .'products_'.$iso_code.'/settings/sortable-attributes', json_encode(['name','price']), 'PUT');
            $this->module->requestCurl($meiliUrl . 'indexes/'. Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') .'products_'.$iso_code.'/settings/ranking-rules', json_encode(["sort","words","typo","proximity","attribute","exactness"]), 'PUT');
            $this->module->requestCurl($meiliUrl . 'indexes/'. Configuration::get('MEILISEARCHPRESTASHOP_PREFIX') .'products_'.$iso_code.'/settings/filterable-attributes', json_encode(['id_manufacturer', 'out_of_stock', 'condition', 'id_category_default
    ', 'quantity', 'feature_values','visibility', 'available_for_order']), 'PUT');
        }


        $this->addFlash('success', 'Produits indexés avec succès dans Meilisearch.');

        return $this->redirectToRoute('admin_meilisearchconfiguration_index');
    }
}
