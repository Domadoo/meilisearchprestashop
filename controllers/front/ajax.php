<?php

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
                if(isset($cookie->meilisearch_id)) {

                    $newSearch = new MeilisearchStatssearch($cookie->meilisearch_id);
                    $newSearch->id_product = Tools::getValue('id_product');
                    $newSearch->position = Tools::getValue('position');
                    $newSearch->save();

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