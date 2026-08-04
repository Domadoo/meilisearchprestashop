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

class MeiliSearchIndexController extends FrameworkBundleAdminController
{
    private $module;

    public function __construct()
    {
        parent::__construct();
        $this->module = \Module::getInstanceByName('meilisearchprestashop');
    }

    public function indexAction()
    {
        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');
        $meiliPrefix = \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX');
        $indexes = [];

        if ($meiliUrl) {
            $response = $this->module->requestCurlSearch($meiliUrl . 'indexes?limit=100');
            $stats = $this->module->requestCurlSearch($meiliUrl . 'stats');
            $indexStats = ($stats && isset($stats->indexes)) ? (array) $stats->indexes : [];

            if ($response && isset($response->results)) {
                foreach ($response->results as $index) {
                    if ($meiliPrefix && strpos($index->uid, $meiliPrefix) !== 0) {
                        continue;
                    }
                    $index->numberOfDocuments = isset($indexStats[$index->uid]) ? $indexStats[$index->uid]->numberOfDocuments : null;
                    $indexes[] = $index;
                }
            }
        }

        return $this->render('@Modules/meilisearchprestashop/views/templates/admin/index.html.twig', array_merge(
            $this->getTranslatedText(),
            [
                'indexes' => $indexes,
                'languages' => \Language::getLanguages(),
            ]
        ));
    }

    public function getTranslatedText()
    {
        $ctx = 'meilisearchindexcontroller';

        return [
            'indexingMeilisearchText' => $this->module->l('Meilisearch indexing', $ctx),
            'indexingMeillisearchProductsText' => $this->module->l('Index products in Meilisearch', $ctx),
            'indexationTitle' => $this->module->l('Indexation', $ctx),
            'settingsTitle' => $this->module->l('Settings', $ctx),
            'chooseLanguage' => $this->module->l('-- Choose a language --', $ctx),
            'reindexLanguageBtn' => $this->module->l('Reindex this language', $ctx),
            'noIndexFound' => $this->module->l('No index found.', $ctx),
            'bulkActionsPlaceholder' => $this->module->l('-- Bulk actions --', $ctx),
            'bulkReindexLabel' => $this->module->l('Reindex selection', $ctx),
            'bulkFlushLabel' => $this->module->l('Flush selection', $ctx),
            'bulkDeleteLabel' => $this->module->l('Delete selection', $ctx),
            'applyLabel' => $this->module->l('Apply', $ctx),
            'colCreatedAt' => $this->module->l('Created at', $ctx),
            'colUpdatedAt' => $this->module->l('Updated at', $ctx),
            'colActions' => $this->module->l('Actions', $ctx),
            'btnEdit' => $this->module->l('Edit', $ctx),
            'btnReindex' => $this->module->l('Reindex', $ctx),
            'btnFlush' => $this->module->l('Flush', $ctx),
            'btnDelete' => $this->module->l('Delete', $ctx),
            'confirmReindexMsg' => $this->module->l('Reindex index "%s" (replaces all documents)?', $ctx),
            'confirmFlushMsg' => $this->module->l('Flush index "%s" (delete all documents)?', $ctx),
            'confirmDeleteMsg' => $this->module->l('Delete index "%s"?', $ctx),
            'confirmBulkReindex' => $this->module->l('Reindex the %d selected index(es)?', $ctx),
            'confirmBulkFlush' => $this->module->l('Flush the %d selected index(es) (delete all documents)?', $ctx),
            'confirmBulkDelete' => $this->module->l('Delete the %d selected index(es)?', $ctx),
            'pleaseChooseLang' => $this->module->l('Please choose a language.', $ctx),
            'confirmReindexLang' => $this->module->l('Reindex language "%s"?', $ctx),
            'backToList' => $this->module->l('Back to list', $ctx),
            'pageUnderConstruction' => $this->module->l('Page under construction.', $ctx),
        ];
    }

    private function isValidIndexUid(string $uid): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_\-]+$/', $uid);
    }

    public function deleteAction($uid)
    {
        if (!$this->isValidIndexUid($uid)) {
            $this->addFlash('error', $this->module->l('Invalid index uid.', 'meilisearchindexcontroller'));

            return $this->redirectToRoute('admin_meilisearch_index_index');
        }
        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');
        $this->module->requestCurlIndex($meiliUrl . 'indexes/' . $uid, null, 'DELETE');
        $this->addFlash('success', sprintf($this->module->l('Index "%s" deleted.', 'meilisearchindexcontroller'), $uid));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    public function flushAction($uid)
    {
        if (!$this->isValidIndexUid($uid)) {
            $this->addFlash('error', $this->module->l('Invalid index uid.', 'meilisearchindexcontroller'));

            return $this->redirectToRoute('admin_meilisearch_index_index');
        }
        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');
        $this->module->requestCurlIndex($meiliUrl . 'indexes/' . $uid . '/documents', null, 'DELETE');
        $this->addFlash('success', sprintf($this->module->l('Documents of index "%s" deleted.', 'meilisearchindexcontroller'), $uid));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    public function bulkFlushAction(Request $request)
    {
        $uids = array_filter((array) ($request->request->all()['uids'] ?? []), [$this, 'isValidIndexUid']);
        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');

        foreach ($uids as $uid) {
            $this->module->requestCurlIndex($meiliUrl . 'indexes/' . $uid . '/documents', null, 'DELETE');
        }

        $this->addFlash('success', sprintf($this->module->l('%d index(es) flushed.', 'meilisearchindexcontroller'), count($uids)));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    public function bulkDeleteAction(Request $request)
    {
        $uids = array_filter((array) ($request->request->all()['uids'] ?? []), [$this, 'isValidIndexUid']);
        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');

        foreach ($uids as $uid) {
            $this->module->requestCurlIndex($meiliUrl . 'indexes/' . $uid, null, 'DELETE');
        }

        $this->addFlash('success', sprintf($this->module->l('%d index(es) deleted.', 'meilisearchindexcontroller'), count($uids)));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    public function indexProductsAction()
    {
        foreach (\Language::getLanguages() as $language) {
            $this->indexLanguage($language);
        }

        $this->addFlash('success', $this->module->l('Products successfully indexed in Meilisearch.', 'meilisearchindexcontroller'));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    public function reindexLanguageAction($uid)
    {
        
        $prefix = \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX');
        $needle = $prefix . 'products_';

        // uid peut être un nom d'index complet (shop1_products_fr) ou un iso_code seul (fr)
        $iso_code = strpos($uid, $needle) === 0
            ? substr($uid, strlen($needle))
            : $uid;

        foreach (\Language::getLanguages() as $language) {
            if ($language['iso_code'] === $iso_code) {
                $this->indexLanguage($language);
                $this->addFlash('success', sprintf($this->module->l('Index "%s" successfully reindexed.', 'meilisearchindexcontroller'), $uid));

                return $this->redirectToRoute('admin_meilisearch_index_index');
            }
        }

        $this->addFlash('error', sprintf($this->module->l('Unable to reindex index "%s": language not found.', 'meilisearchindexcontroller'), $uid));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    public function bulkReindexAction(Request $request)
    {
        $uids = (array) ($request->request->all()['uids'] ?? []);
        $prefix = \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX');
        $needle = $prefix . 'products_';
        $langByIso = array_column(\Language::getLanguages(), null, 'iso_code');
        $count = 0;

        foreach ($uids as $uid) {
            if (strpos($uid, $needle) === 0) {
                $iso_code = substr($uid, strlen($needle));
                if (isset($langByIso[$iso_code])) {
                    $this->indexLanguage($langByIso[$iso_code]);
                    ++$count;
                }
            }
        }

        $this->addFlash('success', sprintf($this->module->l('%d index(es) successfully reindexed.', 'meilisearchindexcontroller'), $count));

        return $this->redirectToRoute('admin_meilisearch_index_index');
    }

    private function indexLanguage(array $language)
    {
        // @phpstan-ignore-next-line
        (new \PrestaShop\Module\MeiliSearch\Service\ProductIndexer($this->module))->indexLanguage($language);
    }
}
