<?php
namespace PrestaShop\Module\MeiliSearch\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use PrestaShopBundle\Security\Annotation\AdminSecurity;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Configuration;
use Context;
use Db;
use Shop;

class MeiliSearchConfigurationController extends FrameworkBundleAdminController
{
    private $module;

    public function __construct()
    {
        parent::__construct();

        // Récupère une instance fonctionnelle du module
        $this->module = \Module::getInstanceByName('meilisearch_prestashop');
    }


    public function indexAction()
    {
        $id_lang = (int) Context::getContext()->language->id;
        $meiliUrl = Configuration::get('MEILISEARCH_PRESTASHOP_URL');


        // $sql = '
        //     SELECT p.*, product_shop.*, pl.*, 
        //         m.`name` AS manufacturer_name, 
        //         s.`name` AS supplier_name
        //     FROM `' . _DB_PREFIX_ . 'product` p
        //     ' . Shop::addSqlAssociation('product', 'p') . '
        //     LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl 
        //         ON (p.`id_product` = pl.`id_product` ' . Shop::addSqlRestrictionOnLang('pl') . ')
        //     LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m 
        //         ON (m.`id_manufacturer` = p.`id_manufacturer`)
        //     LEFT JOIN `' . _DB_PREFIX_ . 'supplier` s 
        //         ON (s.`id_supplier` = p.`id_supplier`)
        //     WHERE pl.`id_lang` = ' . (int) $id_lang . '
        //     AND product_shop.`active` = 1
        //     AND product_shop.`visibility` = "both"
        // ';

        // $products = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        // $payloadIndex = json_encode([
        //     'uid' => 'products',
        //     'primaryKey' => 'id_product'
        // ]);
        // $this->module->requestCurl($meiliUrl . 'indexes', $payloadIndex);

        // $arrayProductsChunk = array_chunk($products, 200);

        // // Envoi à Meilisearch
        // foreach ($arrayProductsChunk as $arrayProducts) {
        //     $this->module->requestCurl($meiliUrl . 'indexes/products/documents', json_encode($arrayProducts));
        // }

        // $this->module->requestCurl($meiliUrl . 'indexes/products/settings/sortable-attributes', json_encode(['name','price']), 'PUT');
        // $this->module->requestCurl($meiliUrl . 'indexes/products/settings/ranking-rules', json_encode(["sort","words","typo","proximity","attribute","exactness"]), 'PUT');
        $this->module->requestCurl($meiliUrl . 'indexes/products/settings/filterable-attributes', json_encode(['id_manufacturer', 'out_of_stock', 'condition']), 'PUT');


        $this->addFlash('success', 'Produits indexés avec succès dans Meilisearch.');

        return $this->render('@Modules/meilisearch_prestashop/views/templates/admin/index.html.twig');
    }
}
