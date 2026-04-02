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

use Configuration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Tools;

class MeiliSearchConfigurationController extends FrameworkBundleAdminController
{
    private $module;

    public function __construct()
    {
        parent::__construct();
        $this->module = \Module::getInstanceByName('meilisearchprestashop');
    }

    public function indexAction(Request $request): Response
    {
        if ($request->isMethod('POST') && $request->request->get('submitMeilisearchprestashopSettings')) {
            foreach (array_keys($this->getConfigValues()) as $key) {
                Configuration::updateValue($key, $request->request->get($key));
            }
            $this->addFlash('success', $this->module->l('Settings saved successfully.', 'meilisearchconfigurationcontroller'));
        }

        return $this->render('@Modules/meilisearchprestashop/views/templates/admin/configuration.html.twig', [
            'config'           => $this->getConfigValues(),
            'cronTokenExample' => Tools::passwdGen(32),
        ]);
    }

    private function getConfigValues(): array
    {
        return [
            'MEILISEARCHPRESTASHOP_URL'              => Configuration::get('MEILISEARCHPRESTASHOP_URL', null),
            'MEILISEARCHPRESTASHOP_KEY'              => Configuration::get('MEILISEARCHPRESTASHOP_KEY', null),
            'MEILISEARCHPRESTASHOP_PREFIX'           => Configuration::get('MEILISEARCHPRESTASHOP_PREFIX', null),
            'MEILISEARCHPRESTASHOP_TOKEN_CRON'       => Configuration::get('MEILISEARCHPRESTASHOP_TOKEN_CRON', null),
        ];
    }
}
