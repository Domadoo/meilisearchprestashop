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

use PrestaShop\Module\Classes\MeilisearchStatssearch;

class MeilisearchprestashopAjaxModuleFrontController extends ModuleFrontController
{
    public function __construct()
	{
		parent::__construct();
	}
    public function postProcess()
    {

        $token = Tools::getValue('token');
        if(!$token == 1)
        {
            die($this->module->l('Access denied','ajax'));
        }
        $action = Tools::getValue('action');
        $cookie = $this->context->cookie;


        switch ($action) {
            case 'productClick':
                // @phpstan-ignore-next-line
                if(isset($cookie->meilisearch_id)) {
                    // @phpstan-ignore-next-line
                    $newSearch = new MeilisearchStatssearch($cookie->meilisearch_id);
                    $newSearch->id_product = Tools::getValue('id_product');
                    $newSearch->position = Tools::getValue('position');
                    $newSearch->save();

                    // @phpstan-ignore-next-line
                    $cookie->meilisearch_product_id = Tools::getValue('id_product');
                    // unset($cookie->meilisearch_id);
                    // unset($cookie->meilisearch_query);
                }
                break;
            
            default:
                # code...
                break;
        }
    }
}