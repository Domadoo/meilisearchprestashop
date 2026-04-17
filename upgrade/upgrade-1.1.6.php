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
 * Ajout des hooks pour les pages listing (catégorie, fabricant, nouveaux produits, meilleures ventes) :
 *  - displayLeftColumn : injection du bloc facettes Meilisearch (rendu serveur)
 *  - displayHeader     : injection JS/CSS + globals pour le remplacement AJAX du listing
 */
function upgrade_module_1_1_6($module)
{
    $module = Module::getInstanceByName('meilisearchprestashop');

    return $module->registerHook('displayLeftColumn')
        && $module->registerHook('displayHeader');
}
