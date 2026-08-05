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

namespace PrestaShop\Module\MeiliSearch\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;

class MeiliSearchIndexSettingsController extends FrameworkBundleAdminController
{
    private $module;

    public function __construct()
    {
        $parent = get_parent_class($this);
        if ($parent && method_exists($parent, '__construct')) {
            $parent::__construct();
        }
        $this->module = \Module::getInstanceByName('meilisearchprestashop');
    }

    public function indexAction($uid)
    {
        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');
        $index = null;
        $settings = null;

        if ($meiliUrl) {
            $index = $this->module->requestCurlSearch($meiliUrl . 'indexes/' . $uid);
            $settings = $this->module->requestCurlSearch($meiliUrl . 'indexes/' . $uid . '/settings');
        }

        $ctx = 'meilisearchindexsettingscontroller';

        return $this->render('@Modules/meilisearchprestashop/views/templates/admin/index_settings.html.twig', [
            'uid' => $uid,
            'index' => $index,
            'settings' => $settings,
            'settingsTitle' => $this->module->l('Settings', $ctx),
            'indexationTitle' => $this->module->l('Indexation', $ctx),
            'backToList' => $this->module->l('Back to list', $ctx),
            'pageUnderConstruction' => $this->module->l('Page under construction.', $ctx),
        ]);
    }
}
