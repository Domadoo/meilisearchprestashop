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

namespace PrestaShop\Module\MeiliSearch\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\MeiliSearch\Service\IndexRun;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Endpoints AJAX de la réindexation « pas à pas » (voir {@see IndexRun}).
 *
 * Chaque appel fait un travail borné et renvoie l'avancement : la page d'indexation
 * enchaîne les appels à step() jusqu'à la fin, en affichant une barre de progression.
 */
class MeiliSearchIndexAjaxController extends FrameworkBundleAdminController
{
    /** @var \Meilisearchprestashop */
    private $module;

    public function __construct()
    {
        $parent = get_parent_class($this);
        if (method_exists($parent, '__construct')) {
            $parent::__construct();
        }
        /** @var \Meilisearchprestashop $module */
        $module = \Module::getInstanceByName('meilisearchprestashop');
        $this->module = $module;
    }

    /** Démarre un run (langues demandées, ou toutes si aucune). */
    public function startAction(Request $request)
    {
        $params = $request->request->all();

        $isos = array_values(array_filter(
            array_map('strval', (array) ($params['isos'] ?? [])),
            function ($iso) {
                return (bool) preg_match('/^[a-zA-Z\-]{2,10}$/', $iso);
            }
        ));

        $sliceSize = isset($params['slice_size']) ? (int) $params['slice_size'] : null;
        $payload = $this->run()->start($isos, $sliceSize ?: null);

        // 409 : un run (ou un cron/CLI) occupe déjà la place — pas une erreur serveur.
        return new JsonResponse($payload, isset($payload['error']) ? 409 : 200);
    }

    /** Avance le run en cours d'un budget de travail borné. */
    public function stepAction()
    {
        return new JsonResponse($this->run()->step());
    }

    /** Avancement du run en cours, sans rien exécuter (reprise après rechargement). */
    public function statusAction()
    {
        return new JsonResponse($this->run()->progress());
    }

    /** Annule le run en cours (index temporaire supprimé, live conservé). */
    public function abortAction()
    {
        return new JsonResponse($this->run()->abort());
    }

    private function run(): IndexRun
    {
        return new IndexRun($this->module);
    }
}
