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
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * - actionFrontControllerSetMedia : neutralise côté serveur le param `order` des tris propres
 *   à Meilisearch (ex: `sales`) sur les pages listing, pour éviter que le contrôleur natif PS
 *   tente un `ORDER BY` sur une colonne inexistante au rechargement.
 * - actionObjectProduct(Add|Update|Delete)After : réindexation temps réel d'un produit dans
 *   Meilisearch dès sa création / mise à jour / suppression.
 */
function upgrade_module_1_3_0($module)
{
    $module = Module::getInstanceByName('meilisearchprestashop');
    if (!$module instanceof Meilisearchprestashop) {
        return false;
    }

    return $module->registerHook('actionFrontControllerSetMedia')
        && $module->registerHook('actionObjectProductAddAfter')
        && $module->registerHook('actionObjectProductUpdateAfter')
        && $module->registerHook('actionObjectProductDeleteAfter');
}
