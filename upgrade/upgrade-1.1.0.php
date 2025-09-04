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
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2025 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * This function updates your module from previous versions to the version 1.1,
 * usefull when you modify your database, or register a new hook ...
 * Don't forget to create one file per version.
 */
function upgrade_module_1_1_0($module)
{
    $module = \Module::getInstanceByName('meilisearchprestashop');
    $module->registerHook('actionCartUpdateQuantityBefore');
    $module->registerHook('actionPresentProduct');
    $module->registerHook('actionValidateOrder');
    
    updateDb();

    return true;
}

function updateDb()
{
    $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'meilisearch_statssearch` (
        `id_statssearch` int(11) NOT NULL AUTO_INCREMENT,
        `query` varchar(255) NOT NULL,
        `nb_results` int(11) NOT NULL,
        `id_product` int(11) DEFAULT NULL,
        `position` int(11) DEFAULT NULL,
        `id_customer` int(11) DEFAULT NULL,
        `id_cart` int(11) NOT NULL,
        `is_ordered` tinyint(1) DEFAULT NULL,
        `id_lang` int(11) NOT NULL,
        `date_add` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY  (`id_statssearch`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

    return Db::getInstance()->execute($sql);
}