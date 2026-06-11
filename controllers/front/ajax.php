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
        if ($token !== '1') {
            http_response_code(403);
            exit(json_encode(['error' => 'Access denied']));
        }
        $action = Tools::getValue('action');
        $cookie = $this->context->cookie;

        switch ($action) {
            case 'productClick':
                $idProduct = (int) Tools::getValue('id_product');
                $position = (int) Tools::getValue('position');
                if ($idProduct <= 0 || $position < 0) {
                    break;
                }
                // @phpstan-ignore-next-line
                if (isset($cookie->meilisearch_id)) {
                    try {
                        // @phpstan-ignore-next-line
                        $newSearch = new MeilisearchStatssearch((int) $cookie->meilisearch_id);
                        if (!Validate::isLoadedObject($newSearch)) {
                            break;
                        }
                        $newSearch->id_product = $idProduct;
                        $newSearch->position = $position;
                        $newSearch->save();

                        // @phpstan-ignore-next-line
                        $cookie->meilisearch_product_id = $idProduct;
                    } catch (Exception $e) {
                        PrestaShopLogger::addLog(
                            'Meilisearch productClick error: ' . $e->getMessage(),
                            2,
                            null,
                            'MeilisearchStatssearch'
                        );
                    }
                }
                break;

            default:
                break;
        }

        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success' => true]));
    }
}
