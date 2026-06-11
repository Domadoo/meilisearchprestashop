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

/**
 * Restructuration des tabs admin :
 *  - Suppression de l'ancien tab MeiliSearchConfigurationController
 *  - Ajout du parent AdminMeiliSearchParentConfiguration (Configuration)
 *  - Ajout de MeiliSearchIndexController (Index)
 *  - Ajout de AdminMeiliSearchConfiguration (Settings)
 */
function upgrade_module_1_1_5($module)
{
    $module = Module::getInstanceByName('meilisearchprestashop');
    if (!$module instanceof Meilisearchprestashop) {
        return false;
    }
    $module->uninstallTab();
    $module->callInstallTab();

    return true;
}
