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

declare(strict_types=1);

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
        $expectedToken = Configuration::get('MEILISEARCHPRESTASHOP_TOKEN_CRON');
        if (empty($token) || empty($expectedToken) || $token !== $expectedToken) {
            PrestaShopLogger::addLog($this->l('Illegal access to CRON Meilisearch'), 3);
            http_response_code(403);
            exit;
        }

        $success = false;

        if (Tools::getValue('action') == 'indexProducts') {
            $success = $this->indexProductsAction();
        }

        if ($success) {
            echo 'success';
            exit(http_response_code(200));
        }
        echo 'error';
        PrestaShopLogger::addLog($this->l('CRON error for action: ', 'cron') . Tools::getValue('action'), 3);
        exit(http_response_code(400));
    }

    public function indexProductsAction()
    {
        // @phpstan-ignore-next-line
        (new \PrestaShop\Module\MeiliSearch\Service\ProductIndexer($this->module))->indexAllProducts();

        return true;
    }
}
