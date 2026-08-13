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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MeiliSearchConfigurationController extends FrameworkBundleAdminController
{
    private $module;

    public function __construct()
    {
        $parent = get_parent_class($this);
        if (method_exists($parent, '__construct')) {
            $parent::__construct();
        }
        $this->module = \Module::getInstanceByName('meilisearchprestashop');
    }

    /** Keys that should never be overwritten with an empty submission */
    private const SECRET_KEYS = [
        'MEILISEARCHPRESTASHOP_KEY',
        'MEILISEARCHPRESTASHOP_TOKEN_CRON',
    ];

    public function indexAction(Request $request): Response
    {
        if ($request->isMethod('POST') && $request->request->get('submitMeilisearchprestashopSettings')) {
            if (!$this->isCsrfTokenValid('save_meilisearch_config', $request->request->get('_token'))) {
                $this->addFlash('error', $this->module->l('Invalid security token.', 'meilisearchconfigurationcontroller'));

                return $this->redirectToRoute('admin_meilisearch_configuration_index');
            }

            foreach (array_keys($this->getConfigValues()) as $key) {
                $value = $request->request->get($key);

                // Never overwrite secrets with an empty value
                if (in_array($key, self::SECRET_KEYS, true) && empty($value)) {
                    continue;
                }

                // Validate URL format before saving
                if ($key === 'MEILISEARCHPRESTASHOP_URL') {
                    $parsed = parse_url($value);
                    if (!$value || !filter_var($value, FILTER_VALIDATE_URL)
                        || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
                        $this->addFlash('error', $this->module->l('Invalid Meilisearch URL (must be http:// or https://).', 'meilisearchconfigurationcontroller'));
                        continue;
                    }
                }

                \Configuration::updateValue($key, $value);
            }
            $this->addFlash('success', $this->module->l('Settings saved successfully.', 'meilisearchconfigurationcontroller'));
        }

        $ctx = 'meilisearchconfigurationcontroller';

        return $this->render('@Modules/meilisearchprestashop/views/templates/admin/configuration.html.twig', [
            'config' => $this->getConfigValues(),
            'cronTokenExample' => \Tools::passwdGen(32),
            'settingsTitle' => $this->module->l('Settings', $ctx),
            'indexationTitle' => $this->module->l('Indexation', $ctx),
            'urlLabel' => $this->module->l('URL', $ctx),
            'urlHelp' => $this->module->l('Meilisearch instance URL (with trailing /)', $ctx),
            'apiKeyLabel' => $this->module->l('API Key', $ctx),
            'prefixLabel' => $this->module->l('Index prefix', $ctx),
            'prefixHelp' => $this->module->l('Index prefix (e.g. shop1_)', $ctx),
            'cronTokenLabel' => $this->module->l('Cron token', $ctx),
            'cronTokenHelp' => $this->module->l('Enter a private key, for example:', $ctx),
            'saveLabel' => $this->module->l('Save', $ctx),
        ]);
    }

    private function getConfigValues(): array
    {
        return [
            'MEILISEARCHPRESTASHOP_URL' => \Configuration::get('MEILISEARCHPRESTASHOP_URL', null),
            'MEILISEARCHPRESTASHOP_KEY' => \Configuration::get('MEILISEARCHPRESTASHOP_KEY', null),
            'MEILISEARCHPRESTASHOP_PREFIX' => \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX', null),
            'MEILISEARCHPRESTASHOP_TOKEN_CRON' => \Configuration::get('MEILISEARCHPRESTASHOP_TOKEN_CRON', null),
        ];
    }
}
