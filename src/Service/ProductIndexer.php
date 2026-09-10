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

namespace PrestaShop\Module\MeiliSearch\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Service partagé d'indexation produits vers Meilisearch.
 *
 * Point unique de la logique d'indexation (SQL, typeMap, feature_values, ids_category
 * récursif, sales 3 mois, push Meili), utilisé par :
 * - controllers/front/cron.php (indexation HTTP)
 * - src/Command/IndexProductsCommand.php (CLI)
 * - src/Controller/Admin/MeiliSearchIndexController.php (admin)
 * - meilisearchprestashop.php (hooks de réindexation produit unique)
 */
class ProductIndexer
{
    /** @var \Meilisearchprestashop */
    private $module;

    /** @var string URL de base Meilisearch (avec `/` final) */
    private $meiliUrl;

    /** @var string Préfixe des index */
    private $prefix;

    /** @var bool|null Cache de la compatibilité /swap-indexes (Meilisearch >= 0.29) */
    private static $swapSupported = null;

    /**
     * @var string|null Cause du dernier envoi de documents échoué (code HTTP, errno cURL,
     *                  message Meili). Lu par pushSlice() pour l'afficher dans l'admin.
     */
    private $lastPushError;

    /** Tentatives d'enfilement d'un batch avant abandon (échecs réseau/timeout transitoires). */
    private const PUSH_MAX_ATTEMPTS = 3;

    /**
     * Backpressure : attendre le drainage de la file Meili toutes les N batches. Empêche
     * l'accumulation de dizaines de tâches d'affilée qui finit par saturer Meili et faire
     * timeouter les POST tardifs (symptôme observé : « batch #25+ sans taskUid »).
     */
    private const BACKPRESSURE_EVERY = 10;

    /**
     * Borne d'attente (s) d'une phase de fin en mode « pas à pas » : au-delà, la tâche
     * Meili est considérée perdue (serveur injoignable) et le swap est annulé. Aligné
     * sur le timeout de waitForTask().
     */
    private const FINISH_TIMEOUT = 300;

    /**
     * @param \Meilisearchprestashop|null $module si null, résolu via Module::getInstanceByName (contexte CLI)
     */
    public function __construct($module = null)
    {
        /** @var \Meilisearchprestashop $resolved */
        $resolved = $module ?: \Module::getInstanceByName('meilisearchprestashop');
        $this->module = $resolved;
        $this->meiliUrl = (string) \Configuration::get('MEILISEARCHPRESTASHOP_URL');
        $this->prefix = (string) \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX');
    }

    /**
     * Réindexation complète (toutes les langues, ou un sous-ensemble d'iso), settings inclus.
     *
     * @param string[]|null $isoFilter iso_code à indexer (null = toutes les langues)
     */
    public function indexAllProducts(?array $isoFilter = null, int $batchSize = 100): void
    {
        foreach (\Language::getLanguages() as $language) {
            if ($isoFilter !== null && !in_array($language['iso_code'], $isoFilter, true)) {
                continue;
            }
            $this->indexLanguage($language, null, true, $batchSize);
        }
    }

    /**
     * Réindexe un produit unique dans toutes les langues.
     * Si le produit n'est plus actif/existant, son document est retiré de l'index.
     */
    public function indexProduct(int $idProduct): void
    {
        foreach (\Language::getLanguages() as $language) {
            $this->indexLanguage($language, [$idProduct], false);
        }
    }

    /**
     * Supprime un produit de tous les index (une langue = un index).
     */
    public function deleteProduct(int $idProduct): void
    {
        foreach (\Language::getLanguages() as $language) {
            $uid = $this->indexUid($language['iso_code']);
            $this->module->requestCurlIndex(
                $this->meiliUrl . 'indexes/' . $uid . '/documents/' . $idProduct,
                null,
                'DELETE'
            );
        }
    }

    /**
     * Cœur de l'indexation pour une langue.
     *
     * Deux modes :
     * - FULL (`$productIds === null`) : reconstruction atomique via index temporaire
     *   + `swap-indexes` (voir {@see fullReindexLanguage()}). Élimine les documents
     *   orphelins (produits désactivés/supprimés hors hooks) sans downtime.
     * - SOUS-ENSEMBLE (`$productIds !== null`) : upsert direct sur l'index live +
     *   purge des IDs demandés absents. Utilisé par les hooks produit unique.
     *
     * @param array $language ligne Language::getLanguages()
     * @param int[]|null $productIds sous-ensemble de produits (null = tous). En mode
     *                               sous-ensemble, un produit absent (inactif/supprimé)
     *                               est retiré de l'index.
     * @param bool $applySettings appliquer les settings Meili (ignoré en full : toujours appliqués)
     */
    public function indexLanguage(array $language, ?array $productIds = null, bool $applySettings = true, int $batchSize = 100): void
    {
        if ($productIds === null) {
            $this->fullReindexLanguage($language, $batchSize);

            return;
        }

        // ── Mode sous-ensemble (hooks produit unique) : upsert direct + purge ────────
        $uid = $this->indexUid($language['iso_code']);
        $docs = $this->buildDocuments($language, $productIds);

        // Les produits demandés absents du résultat (inactifs/supprimés/hors contexte
        // boutique) doivent être retirés de l'index.
        $found = array_map('intval', array_column($docs, 'id_product'));
        foreach (array_map('intval', $productIds) as $requestedId) {
            if (!in_array($requestedId, $found, true)) {
                $this->module->requestCurlIndex(
                    $this->meiliUrl . 'indexes/' . $uid . '/documents/' . $requestedId,
                    null,
                    'DELETE'
                );
            }
        }

        // Pas d'ensureIndex ici : il enqueue une tâche « Index creation » à CHAQUE
        // sauvegarde produit × langue (échouant en index_already_exists), ce qui sature
        // la file. Le POST des documents auto-crée l'index si besoin, avec la bonne clé
        // primaire grâce au ?primaryKey=id_product posé dans pushDocuments().
        if (!empty($docs)) {
            $this->pushDocuments($uid, $docs, $batchSize);
        }

        if ($applySettings) {
            $this->applySettings($uid);
        }
    }

    /**
     * Réindexation complète atomique d'une langue : on reconstruit un index temporaire
     * ({uid}_tmp) à partir du catalogue courant (produits `active = 1`), puis on bascule
     * atomiquement live ⇄ tmp via `POST /swap-indexes` (zéro downtime), enfin on supprime
     * le tmp (qui contient désormais l'ancien contenu). Le live n'est JAMAIS vidé ni
     * exposé partiel : tout échec laisse l'index live intact et fonctionnel.
     */
    private function fullReindexLanguage(array $language, int $batchSize): void
    {
        // Les attentes de tâches (waitForTask) allongent le temps mur : sous SAPI web
        // (cron/admin) max_execution_time peut couper à 30 s.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $isoCode = $language['iso_code'];
        $live = $this->indexUid($isoCode);
        $tmp = $this->tmpUid($isoCode);
        $lockName = $this->lockName($live);

        // Verrou anti-concurrence (cron + admin simultanés utiliseraient le même _tmp).
        if (!$this->acquireLock($lockName)) {
            \PrestaShopLogger::addLog('Meilisearch: réindexation "' . $live . '" ignorée (verrou pris, run concurrent)', 2);

            return;
        }

        try {
            // Une réindexation AJAX (admin) en cours reconstruit le même index temporaire :
            // la piétiner (pré-nettoyage du tmp) ferait échouer ses gates pour rien.
            if (IndexRun::isActiveFor($isoCode)) {
                \PrestaShopLogger::addLog('Meilisearch: réindexation "' . $live . '" ignorée (réindexation AJAX admin en cours)', 2);

                return;
            }

            $liveExists = $this->indexExists($live);

            // Deux cas où l'on remplit DIRECTEMENT le live (sans tmp ni swap) :
            //  - Meili < 0.29 : /swap-indexes indisponible → repli sur l'upsert additif
            //    historique (sans purge des orphelins), pour ne pas casser l'indexation.
            //  - Premier run (aucun index live existant) : il n'y a rien à préserver ni
            //    aucun downtime à éviter. Passer par un tmp est ici un risque net : si un
            //    gate échoue (catalogue jugé incomplet, /stats lent) ou que le worker web
            //    est coupé (SAPI), on supprimerait le tmp et la boutique se retrouverait
            //    AVEC ZÉRO index. Un remplissage direct laisse au pire un live partiel
            //    (complété au prochain run, qui empruntera alors la voie swap), jamais vide.
            if (!$this->supportsSwap() || !$liveExists) {
                \PrestaShopLogger::addLog(
                    $liveExists
                        ? 'Meilisearch: /swap-indexes non supporté (< 0.29), réindexation additive sur "' . $live . '"'
                        : 'Meilisearch: premier remplissage direct de "' . $live . '" (aucun index existant, sans swap)',
                    2
                );
                $this->ensureIndex($live);
                $docs = $this->buildDocuments($language, null);
                if (!empty($docs)) {
                    $this->pushDocuments($live, $docs, $batchSize);
                }
                $this->applySettings($live);

                return;
            }

            // Pré-nettoyage d'un tmp résiduel (run précédent interrompu) — sûr sous verrou.
            $this->deleteIndexUid($tmp);

            // Les settings vont sur le tmp : le swap échange documents ET settings, l'UID
            // ne bouge pas → le live héritera des settings du tmp après bascule.
            $this->ensureIndex($tmp);
            $this->applySettings($tmp);

            $docs = $this->buildDocuments($language, null);
            // Meili dédoublonne par clé primaire (id_product) : en multiboutique / partage
            // de stock, buildDocuments peut renvoyer plusieurs lignes pour un même produit
            // (fan-out des JOIN stock_available / product_shop). Le nombre de docs RÉELLEMENT
            // stockés = nb d'id_product DISTINCTS. Compter les lignes brutes rendrait le
            // Gate 2 (count < expected) systématiquement faux → swap jamais confirmé, live
            // figé sur les installs concernées.
            $expected = count(array_unique(array_column($docs, 'id_product')));
            if ($expected === 0) {
                // On ne swappe jamais un index vide : live conservé.
                \PrestaShopLogger::addLog('Meilisearch: aucun produit à indexer pour "' . $live . '", live conservé', 2);
                $this->deleteIndexUid($tmp);

                return;
            }

            $lastPush = $this->pushDocuments($tmp, $docs, $batchSize);

            // Gate 1 : peuplement du tmp terminé (file FIFO → couvre create + settings + batches).
            if (!$this->waitForTask($lastPush)) {
                \PrestaShopLogger::addLog('Meilisearch: échec/timeout peuplement "' . $tmp . '", swap annulé, live conservé', 3);
                $this->deleteIndexUid($tmp);

                return;
            }

            // Gate 2 : le tmp doit contenir le nombre de documents attendu (clé primaire
            // id_product ⇒ 1 doc/produit ; un batch échoué donne un compte inférieur).
            $count = $this->getNumberOfDocuments($tmp);
            if ($count === null || $count < $expected) {
                \PrestaShopLogger::addLog('Meilisearch: "' . $tmp . '" incomplet (' . var_export($count, true) . '/' . $expected . '), swap annulé, live conservé', 3);
                $this->deleteIndexUid($tmp);

                return;
            }

            // Le swap exige que les deux index existent. Le live existait au début du run
            // (vérifié sous verrou) ; ce garde-fou ne couvre que la course rare d'une
            // suppression concurrente du live (action admin « Supprimer ») entre-temps.
            if (!$this->indexExists($live)) {
                $this->waitForTask($this->ensureIndex($live));
            }

            $swapTask = $this->swapIndexes($live, $tmp);
            if (!$this->waitForTask($swapTask)) {
                // Swap non confirmé : un swap tardif pourrait encore aboutir → on NE
                // supprime PAS le tmp (le pré-nettoyage du prochain run s'en chargera).
                \PrestaShopLogger::addLog('Meilisearch: swap "' . $live . '" ⇄ "' . $tmp . '" non confirmé, live conservé', 3);

                return;
            }

            // Swap confirmé : le tmp contient l'ancien contenu → on le supprime.
            $this->deleteIndexUid($tmp);

            // Le live vient de changer d'un coup → invalide le cache de réponses Meili.
            $this->bumpResponseCacheGeneration();
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('Meilisearch: exception réindexation "' . $live . '" : ' . $e->getMessage(), 3);
            // Best-effort : nettoyage du tmp, jamais du live.
            $this->deleteIndexUid($tmp);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // API « pas à pas » — réindexation AJAX pilotée depuis l'admin
    //
    // Même algorithme que fullReindexLanguage() (index temporaire + swap atomique,
    // gate peuplement, gate comptage, live jamais vidé), mais découpé en unités de
    // travail bornées tenant chacune dans une requête HTTP courte :
    //   prepareLanguage()  → index cible créé + settings posés
    //   pushSlice() × N    → un POST de documents par tranche (pagination par clé)
    //   finishLanguage()   → attentes / gates / swap / nettoyage, en polling non bloquant
    //
    // L'état entre deux requêtes est porté par {@see IndexRun}. Le verrou MySQL
    // (GET_LOCK) étant lié à la connexion, il ne survit pas à une requête : l'exclusion
    // mutuelle avec le cron/CLI repose sur isFullReindexRunning() ici, et sur
    // IndexRun::isActiveFor() dans fullReindexLanguage().
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Nombre de produits indexables pour une langue — dénominateur de la barre de
     * progression (le compte qui sert de gate, lui, est dérivé des envois réels).
     */
    public function countProducts(array $language): int
    {
        return (int) \Db::getInstance(true)->getValue('
            SELECT COUNT(DISTINCT p.`id_product`)
            FROM `' . _DB_PREFIX_ . 'product` p
            ' . \Shop::addSqlAssociation('product', 'p') . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON (p.`id_product` = pl.`id_product` ' . \Shop::addSqlRestrictionOnLang('pl') . ')
            WHERE pl.`id_lang` = ' . (int) $language['id_lang'] . '
            AND product_shop.`active` = 1
        ');
    }

    /**
     * Première unité de travail d'une langue : choix du mode, création de l'index
     * cible et pose des settings.
     *
     * @return array{mode: string, target: string, live: string} mode ∈ swap|direct
     */
    public function prepareLanguage(array $language): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $live = $this->indexUid($language['iso_code']);
        $tmp = $this->tmpUid($language['iso_code']);
        $liveExists = $this->indexExists($live);

        // Mêmes deux cas de remplissage direct que fullReindexLanguage() : Meili < 0.29
        // (pas de /swap-indexes), ou premier run (aucun live à préserver — passer par un
        // tmp risquerait de laisser la boutique sans aucun index si le run échoue).
        if (!$this->supportsSwap() || !$liveExists) {
            \PrestaShopLogger::addLog(
                $liveExists
                    ? 'Meilisearch: /swap-indexes non supporté (< 0.29), réindexation additive sur "' . $live . '"'
                    : 'Meilisearch: premier remplissage direct de "' . $live . '" (aucun index existant, sans swap)',
                2
            );
            $this->ensureIndex($live);
            $this->applySettings($live);

            return ['mode' => 'direct', 'target' => $live, 'live' => $live];
        }

        // Pré-nettoyage d'un tmp résiduel (run précédent interrompu), puis création +
        // settings sur le tmp : le swap échange documents ET settings.
        $this->deleteIndexUid($tmp);
        $this->ensureIndex($tmp);
        $this->applySettings($tmp);

        return ['mode' => 'swap', 'target' => $tmp, 'live' => $live];
    }

    /**
     * Indexe une tranche de produits. Pagination par clé (`id_product > $afterId`)
     * et non par OFFSET : stable même si le catalogue bouge pendant le run, et les
     * tranches restent disjointes (donc `unique` s'additionne sans double compte).
     *
     * @return array{rows: int, unique: int, lastId: int, taskUid: int|null, exhausted: bool, error: string|null}
     */
    public function pushSlice(array $language, string $target, int $afterId, int $limit): array
    {
        $docs = $this->buildDocuments($language, null, $afterId, $limit);

        if (empty($docs)) {
            return ['rows' => 0, 'unique' => 0, 'lastId' => $afterId, 'taskUid' => null, 'exhausted' => true, 'error' => null];
        }

        $ids = array_map('intval', array_column($docs, 'id_product'));

        // batchSize = taille de la tranche → un seul POST, sans attente interne : le
        // découpage et la backpressure sont pilotés par l'appelant, requête par requête.
        $taskUid = $this->pushDocuments($target, $docs, count($docs));

        return [
            'rows' => count($docs),
            // Meili dédoublonne par clé primaire : les documents réellement stockés se
            // comptent en id_product distincts (fan-out des JOIN multiboutique/stock).
            'unique' => count(array_unique($ids)),
            'lastId' => max($ids),
            'taskUid' => $taskUid,
            'exhausted' => count($docs) < $limit,
            'error' => $taskUid === null ? $this->lastPushError : null,
        ];
    }

    /**
     * Fin de réindexation d'une langue, en polling non bloquant : un seul contrôle
     * (ou une seule action) par appel, l'état étant porté par $ctx.
     *
     * Garde-fous identiques à fullReindexLanguage() : le live n'est jamais vidé, un
     * swap non confirmé laisse le tmp en place (le pré-nettoyage du prochain run s'en
     * chargera), tout échec conserve le live.
     *
     * @param array $ctx clés mode, target, live, expected, taskUid, finishPhase, waitSince
     *
     * @return array{ctx: array, status: string, message: string|null} status ∈ pending|done|error
     */
    public function finishLanguage(array $language, array $ctx): array
    {
        $tmp = (string) ($ctx['target'] ?? '');
        $live = (string) ($ctx['live'] ?? '');
        $taskUid = isset($ctx['taskUid']) && $ctx['taskUid'] !== null ? (int) $ctx['taskUid'] : null;
        $phase = !empty($ctx['finishPhase']) ? (string) $ctx['finishPhase'] : 'wait_push';

        // Mode direct : rien à basculer, les settings sont déjà posés.
        if (($ctx['mode'] ?? '') !== 'swap') {
            return ['ctx' => $ctx, 'status' => 'done', 'message' => null];
        }

        // On ne swappe JAMAIS un index vide : aucun document envoyé (catalogue vide pour
        // cette langue, ou tous les batches perdus) ⇒ live conservé tel quel.
        if ((int) ($ctx['expected'] ?? 0) === 0) {
            \PrestaShopLogger::addLog('Meilisearch: aucun produit à indexer pour "' . $live . '", live conservé', 2);
            $this->deleteIndexUid($tmp);
            $ctx['finishPhase'] = 'error';

            return [
                'ctx' => $ctx,
                'status' => 'error',
                'message' => $this->module->l('No product was indexed for this language: the live index was kept as-is.', 'productindexer'),
            ];
        }

        // Un Meili injoignable répondrait « pending » indéfiniment : borne d'attente par
        // phase, alignée sur le timeout de waitForTask().
        if (empty($ctx['waitSince'])) {
            $ctx['waitSince'] = time();
        }
        $expired = (time() - (int) $ctx['waitSince']) > self::FINISH_TIMEOUT;

        switch ($phase) {
            case 'wait_push':
                // Gate 1 : peuplement du tmp terminé (file FIFO → couvre create + settings + batches).
                $state = $this->taskState($taskUid);
                if ($state === 'pending' && !$expired) {
                    return ['ctx' => $ctx, 'status' => 'pending', 'message' => null];
                }
                if ($state !== 'succeeded') {
                    return $this->finishFail(
                        $ctx,
                        'Meilisearch: échec/timeout peuplement "' . $tmp . '", swap annulé, live conservé',
                        $this->module->l('Filling of the temporary index failed or timed out: swap cancelled, live index kept.', 'productindexer'),
                        true
                    );
                }

                return $this->finishAdvance($ctx, 'verify', $taskUid);

            case 'verify':
                // Gate 2 : le tmp doit contenir le nombre de documents attendu (1 doc/produit).
                $count = $this->getNumberOfDocuments($tmp);
                $expected = (int) ($ctx['expected'] ?? 0);
                if ($count === null || $count < $expected) {
                    return $this->finishFail(
                        $ctx,
                        'Meilisearch: "' . $tmp . '" incomplet (' . var_export($count, true) . '/' . $expected . '), swap annulé, live conservé',
                        sprintf(
                            $this->module->l('Temporary index incomplete (%s/%d documents): swap cancelled, live index kept.', 'productindexer'),
                            $count === null ? '?' : (string) $count,
                            $expected
                        ),
                        true
                    );
                }

                // Le swap exige que les deux index existent. Ne couvre que la course rare
                // d'une suppression concurrente du live (action admin « Supprimer »).
                if (!$this->indexExists($live)) {
                    return $this->finishAdvance($ctx, 'wait_live', $this->ensureIndex($live));
                }

                return $this->finishAdvance($ctx, 'wait_swap', $this->swapIndexes($live, $tmp));

            case 'wait_live':
                if ($this->taskState($taskUid) === 'pending' && !$expired) {
                    return ['ctx' => $ctx, 'status' => 'pending', 'message' => null];
                }

                return $this->finishAdvance($ctx, 'wait_swap', $this->swapIndexes($live, $tmp));

            case 'wait_swap':
                $state = $this->taskState($taskUid);
                if ($state === 'pending' && !$expired) {
                    return ['ctx' => $ctx, 'status' => 'pending', 'message' => null];
                }
                if ($state !== 'succeeded') {
                    // Swap non confirmé : un swap tardif pourrait encore aboutir → on NE
                    // supprime PAS le tmp (le pré-nettoyage du prochain run s'en chargera).
                    return $this->finishFail(
                        $ctx,
                        'Meilisearch: swap "' . $live . '" ⇄ "' . $tmp . '" non confirmé, live conservé',
                        $this->module->l('Index swap not confirmed: live index kept.', 'productindexer'),
                        false
                    );
                }

                // Swap confirmé : le tmp contient l'ancien contenu → on le supprime, et le
                // live vient de changer d'un coup → on invalide le cache de réponses.
                $this->deleteIndexUid($tmp);
                $this->bumpResponseCacheGeneration();
                $ctx['finishPhase'] = 'done';

                return ['ctx' => $ctx, 'status' => 'done', 'message' => null];
        }

        return ['ctx' => $ctx, 'status' => 'done', 'message' => null];
    }

    /**
     * Abandon d'une langue en cours (annulation admin, exception) : supprime l'index
     * temporaire, ne touche JAMAIS au live.
     */
    public function cancelLanguage(array $ctx): void
    {
        if (($ctx['mode'] ?? '') === 'swap' && !empty($ctx['target'])) {
            $this->deleteIndexUid((string) $ctx['target']);
        }
    }

    /**
     * Vrai si une réindexation complète cron/CLI de cette langue est en cours (verrou
     * MySQL détenu par une autre connexion).
     */
    public function isFullReindexRunning(string $isoCode): bool
    {
        try {
            $held = \Db::getInstance()->getValue(
                "SELECT IS_USED_LOCK('" . \pSQL($this->lockName($this->indexUid($isoCode))) . "')"
            );
        } catch (\Throwable $e) {
            // IS_USED_LOCK indisponible : ne pas bloquer l'indexation pour autant (le
            // chemin swap est de toute façon auto-protégé, un échec laisse le live intact).
            return false;
        }

        return $held !== null && $held !== false && (string) $held !== '';
    }

    /**
     * État d'une tâche Meili en un seul appel (non bloquant).
     *
     * @return string succeeded|failed|pending (réseau/timeout transitoire inclus)|none
     */
    public function taskState(?int $taskUid): string
    {
        if ($taskUid === null) {
            return 'none';
        }

        $task = $this->module->requestCurlSearch($this->meiliUrl . 'tasks/' . $taskUid);
        if (!isset($task->status)) {
            // Réponse absente/inattendue = transitoire : l'appelant réessaiera jusqu'à sa
            // borne d'attente (FINISH_TIMEOUT).
            return 'pending';
        }
        if ($task->status === 'succeeded') {
            return 'succeeded';
        }
        if ($task->status === 'failed' || $task->status === 'canceled') {
            return 'failed';
        }

        return 'pending';
    }

    /** Passage à la phase de fin suivante : réarme la borne d'attente. */
    private function finishAdvance(array $ctx, string $phase, ?int $taskUid): array
    {
        $ctx['finishPhase'] = $phase;
        $ctx['taskUid'] = $taskUid;
        $ctx['waitSince'] = time();

        return ['ctx' => $ctx, 'status' => 'pending', 'message' => null];
    }

    /** Échec d'une phase de fin : log technique + message admin, live toujours conservé. */
    private function finishFail(array $ctx, string $logMessage, string $userMessage, bool $dropTmp): array
    {
        \PrestaShopLogger::addLog($logMessage, 3);
        if ($dropTmp && !empty($ctx['target'])) {
            $this->deleteIndexUid((string) $ctx['target']);
        }
        $ctx['finishPhase'] = 'error';

        return ['ctx' => $ctx, 'status' => 'error', 'message' => $userMessage];
    }

    /**
     * Invalide le cache de réponses Meili (listings non filtrés) après un swap confirmé,
     * en bumpant le jeton de génération. Best-effort : une erreur ne doit jamais
     * compromettre la réindexation (le cache expirerait de toute façon par TTL).
     */
    private function bumpResponseCacheGeneration()
    {
        try {
            (new \PrestaShop\Module\MeiliSearch\Cache\MeilisearchResponseCache())->bumpGeneration();
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('Meilisearch: échec bump génération cache après swap : ' . $e->getMessage(), 2);
        }
    }

    /**
     * Construit les documents produits prêts à indexer pour une langue : SELECT
     * (produits `active = 1`), cast selon le typeMap, enrichissement feature_values /
     * ids_category / sales. Partagé par le mode full et le mode sous-ensemble.
     *
     * @param array $language ligne Language::getLanguages()
     * @param int[]|null $productIds null = tous les produits, sinon sous-ensemble
     * @param int|null $afterId pagination par clé : ne renvoie que les id_product > $afterId
     * @param int|null $limit taille de la fenêtre (null = pas de fenêtrage)
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDocuments(array $language, ?array $productIds, ?int $afterId = null, ?int $limit = null): array
    {
        $idLang = (int) $language['id_lang'];

        $idFilter = '';
        if ($productIds !== null) {
            $ids = array_map('intval', $productIds);
            $idFilter = ' AND p.`id_product` IN (' . implode(',', $ids) . ')';
        }

        // Fenêtre de pagination par clé (mode « pas à pas ») : ORDER BY id_product +
        // `> $afterId` garde les tranches disjointes et stables si le catalogue bouge
        // pendant le run, contrairement à un LIMIT/OFFSET.
        $window = '';
        if ($limit !== null) {
            $window = ' AND p.`id_product` > ' . (int) $afterId
                . ' ORDER BY p.`id_product` ASC LIMIT ' . max(1, (int) $limit);
        }

        // Le stock réel vit dans stock_available (id_product_attribute = 0 = agrégat produit),
        // pas dans ps_product.quantity qui n'est pas maintenu en PS 1.7/8. On écrase donc
        // `quantity` avec la valeur de stock_available (même pattern que le cœur PrestaShop).
        $sql = '
            SELECT p.*, product_shop.*, pl.*,
                m.`name` AS manufacturer_name,
                s.`name` AS supplier_name,
                IFNULL(sa.`quantity`, 0) AS quantity
            FROM `' . _DB_PREFIX_ . 'product` p
            ' . \Shop::addSqlAssociation('product', 'p') . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON (p.`id_product` = pl.`id_product` ' . \Shop::addSqlRestrictionOnLang('pl') . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                ON (sa.`id_product` = p.`id_product` AND sa.`id_product_attribute` = 0'
                . \StockAvailable::addSqlShopRestriction(null, null, 'sa') . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
                ON (m.`id_manufacturer` = p.`id_manufacturer`)
            LEFT JOIN `' . _DB_PREFIX_ . 'supplier` s
                ON (s.`id_supplier` = p.`id_supplier`)
            WHERE pl.`id_lang` = ' . $idLang . '
            AND product_shop.`active` = 1' . $idFilter . $window . '
        ';

        $products = \Db::getInstance(true)->executeS($sql);
        if (empty($products)) {
            return [];
        }

        $productIdsStr = implode(',', array_map('intval', array_column($products, 'id_product')));

        $productFeatureValues = $this->buildFeatureValues($productIdsStr);
        $productCategoryIds = $this->buildCategoryIds($productIdsStr);
        $productSales = $this->buildSales($productIdsStr);

        $typeMap = $this->typeMap();
        foreach ($products as &$product) {
            foreach ($typeMap as $field => $type) {
                if (array_key_exists($field, $product) && $product[$field] !== null) {
                    switch ($type) {
                        case 'int':
                            $product[$field] = (int) $product[$field];
                            break;
                        case 'float':
                            $product[$field] = (float) $product[$field];
                            break;
                        case 'bool':
                            $product[$field] = (bool) $product[$field];
                            break;
                    }
                }
            }
            $id = $product['id_product'];
            $product['feature_values'] = $productFeatureValues[$id] ?? [];
            $product['ids_category'] = $productCategoryIds[$id] ?? [];
            $product['sales'] = $productSales[$id] ?? 0;
        }

        unset($product);

        return $products;
    }

    /**
     * Envoie les documents vers un index par batches (POST /documents = upsert).
     *
     * @param array<int, array<string, mixed>> $products
     *
     * @return int|null taskUid du dernier batch (null si aucun envoi / échec réseau)
     */
    private function pushDocuments(string $uid, array $products, int $batchSize): ?int
    {
        // JSON_INVALID_UTF8_SUBSTITUTE (PHP >= 7.2, garanti par la contrainte 8.1) :
        // un octet UTF-8 invalide dans UN seul produit (contenu importé/synchronisé)
        // est remplacé par U+FFFD au lieu de faire échouer json_encode() sur TOUT le
        // batch. Sans ce flag, json_encode() renvoyait false → corps POST vide (la garde
        // `$payload != null` de requestCurlRaw ne pose pas CURLOPT_POSTFIELDS) → le batch
        // entier (jusqu'à $batchSize produits sains) n'était pas indexé, SANS erreur.
        // Régression rendue visible en 1.3 : la réindexation repart d'un index vide
        // (tmp + swap) et n'accumule plus les runs précédents qui masquaient la perte.
        $jsonFlags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;

        $this->lastPushError = null;
        $lastTask = null;
        $batchIndex = 0;
        $chunks = array_chunk($products, $batchSize);
        $batchCount = count($chunks);
        foreach ($chunks as $chunk) {
            ++$batchIndex;
            $payload = json_encode($chunk, $jsonFlags);

            // Garde anti-batch-vide : si l'encodage échoue malgré tout (float INF/NAN,
            // profondeur, ou UTF-8 sur un PHP sans le flag), on NE POSTe PAS un corps vide
            // (qui indexerait zéro doc silencieusement). On log les id_product concernés.
            if ($payload === false) {
                $this->lastPushError = 'JSON: ' . json_last_error_msg();
                \PrestaShopLogger::addLog(
                    'Meilisearch: batch #' . $batchIndex . ' de "' . $uid . '" non encodable ('
                    . json_last_error_msg() . '), ' . count($chunk) . ' produits ignorés '
                    . $this->idRange($chunk),
                    3
                );
                continue;
            }

            $taskUid = $this->pushBatchWithRetry($uid, $payload, $chunk, $batchIndex);
            if ($taskUid !== null) {
                $lastTask = $taskUid;
            }

            // Backpressure anti-saturation : sur un gros catalogue, enfiler des dizaines de
            // batches d'affilée sature Meili et fait timeouter les POST tardifs. Toutes les
            // BACKPRESSURE_EVERY batches, on attend que le dernier batch enfilé soit traité
            // pour laisser la file se vider avant de continuer. Inutile sur le dernier batch
            // (le Gate 1 l'attendra) → on l'exclut pour ne pas doubler l'attente finale.
            if ($lastTask !== null
                && $batchIndex < $batchCount
                && $batchIndex % self::BACKPRESSURE_EVERY === 0) {
                $this->waitForTask($lastTask);
            }
        }

        return $lastTask;
    }

    /**
     * POST d'un batch de documents, avec réessais sur échec transitoire.
     *
     * Meili renvoie {taskUid} (202 enqueued) au succès. Une absence de taskUid :
     *  - SANS message → réponse null de requestCurl = réseau/timeout (Meili saturé par la
     *    file) → TRANSITOIRE : on réessaie avec backoff (le délai laisse Meili drainer sa
     *    file, cause la plus fréquente de l'échec sur gros catalogue) ;
     *  - AVEC message → rejet applicatif 4xx (payload invalide, etc.) = DÉTERMINISTE : on
     *    n'insiste pas.
     * Après échec définitif, on loggue les id_product perdus pour rendre la perte VISIBLE.
     *
     * @param array<int, array<string, mixed>> $chunk lot courant (pour la plage d'id du log)
     *
     * @return int|null taskUid en cas de succès, null si le batch n'a pas pu être enfilé
     */
    private function pushBatchWithRetry(string $uid, string $payload, array $chunk, int $batchIndex): ?int
    {
        // ?primaryKey=id_product : garantit la bonne clé si l'index est auto-créé par cet
        // ajout (le catalogue a plusieurs champs id_* → inférence ambiguë).
        $url = $this->meiliUrl . 'indexes/' . $uid . '/documents?primaryKey=id_product';
        $sleepUs = 1000000; // 1 s, doublé à chaque essai (plafonné à 8 s)

        for ($attempt = 1; $attempt <= self::PUSH_MAX_ATTEMPTS; ++$attempt) {
            $resp = $this->module->requestCurlIndex($url, $payload);

            if (isset($resp->taskUid)) {
                return (int) $resp->taskUid;
            }

            // Rejet applicatif (Meili renvoie un message) : réessayer ne changerait rien.
            if (isset($resp->message)) {
                $this->lastPushError = 'Meilisearch: ' . (string) $resp->message;
                \PrestaShopLogger::addLog(
                    'Meilisearch: batch #' . $batchIndex . ' de "' . $uid . '" rejeté ('
                    . (string) $resp->message . '), ' . count($chunk) . ' produits non indexés '
                    . $this->idRange($chunk),
                    3
                );

                return null;
            }

            // null = réseau/timeout : transitoire → backoff puis nouvel essai. On retient
            // le diagnostic cURL de la tentative (écrasé par l'appel suivant).
            $this->lastPushError = $this->curlDiagnostic();

            if ($attempt < self::PUSH_MAX_ATTEMPTS) {
                usleep($sleepUs);
                $sleepUs = min($sleepUs * 2, 8000000);
            }
        }

        \PrestaShopLogger::addLog(
            'Meilisearch: échec du batch #' . $batchIndex . ' de "' . $uid . '" après '
            . self::PUSH_MAX_ATTEMPTS . ' tentatives, ' . count($chunk)
            . ' produits non indexés ' . $this->idRange($chunk)
            . ' — payload ' . round(strlen($payload) / 1024) . ' Ko, '
            . (string) $this->lastPushError,
            3
        );

        return null;
    }

    /**
     * Diagnostic du dernier appel cURL. Sans lui, tout échec d'envoi se lit
     * « réseau/Meilisearch » sans distinguer les causes réelles, qui appellent des
     * corrections très différentes : 413 d'un reverse-proxy (client_max_body_size trop
     * bas pour un batch de grosses descriptions), errno 28 (timeout de réponse),
     * errno 7 (connexion refusée), 5xx de Meilisearch (file saturée).
     */
    private function curlDiagnostic(): string
    {
        $info = is_array($this->module->lastCurlInfo) ? $this->module->lastCurlInfo : [];
        $httpCode = isset($info['http_code']) ? (int) $info['http_code'] : 0;
        $errno = isset($info['errno']) ? (int) $info['errno'] : 0;
        $errmsg = isset($info['errmsg']) ? trim((string) $info['errmsg']) : '';

        $diagnostic = 'HTTP ' . $httpCode . ', curl errno ' . $errno;
        if ($errmsg !== '') {
            $diagnostic .= ' (' . $errmsg . ')';
        }

        // Un corps non-JSON (page d'erreur HTML d'un proxy) est justement le symptôme le
        // plus parlant : on en garde un extrait court, aplati et tronqué proprement.
        $body = isset($info['content']) ? (string) $info['content'] : '';
        if ($body !== '' && json_decode($body) === null) {
            $flat = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));
            if ($flat !== '') {
                $diagnostic .= ', réponse: ' . \Tools::substr($flat, 0, 160);
            }
        }

        return $diagnostic;
    }

    /**
     * Plage d'id_product d'un lot pour les logs (borné : min–max, pas la liste complète).
     *
     * @param array<int, array<string, mixed>> $chunk
     */
    private function idRange(array $chunk): string
    {
        $ids = array_map('intval', array_column($chunk, 'id_product'));
        if (empty($ids)) {
            return '[]';
        }

        return '[id_product ' . min($ids) . '–' . max($ids) . ']';
    }

    private function indexUid(string $isoCode): string
    {
        return $this->prefix . 'products_' . $isoCode;
    }

    /**
     * Crée l'index (idempotent). Accepte un UID complet (live ou tmp).
     *
     * @return int|null taskUid (null si échec réseau ou réponse inattendue)
     */
    private function ensureIndex(string $uid): ?int
    {
        $resp = $this->module->requestCurlIndex($this->meiliUrl . 'indexes', json_encode([
            'uid' => $uid,
            'primaryKey' => 'id_product',
        ]));

        return isset($resp->taskUid) ? (int) $resp->taskUid : null;
    }

    /**
     * Applique les settings Meili à un index (UID complet, live ou tmp). Le swap
     * échangeant documents ET settings, on peut les poser sur le tmp avant bascule.
     *
     * @return int|null taskUid du dernier PUT (null si échec réseau)
     */
    private function applySettings(string $uid): ?int
    {
        $base = $this->meiliUrl . 'indexes/' . $uid . '/settings/';

        $this->module->requestCurlIndex($base . 'pagination', json_encode(['maxTotalHits' => 9999]), 'PATCH');
        // Le compteur "en stock" somme les tranches de la distribution `quantity` :
        // on relève la limite par facette (défaut 100) pour ne pas sous-compter
        // sur les catalogues à nombreuses valeurs de stock distinctes.
        $this->module->requestCurlIndex($base . 'faceting', json_encode(['maxValuesPerFacet' => 1000]), 'PATCH');
        $this->module->requestCurlIndex($base . 'sortable-attributes', json_encode(['name', 'price', 'date_add', 'quantity', 'sales']), 'PUT');
        $this->module->requestCurlIndex($base . 'ranking-rules', json_encode(['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness']), 'PUT');
        $resp = $this->module->requestCurlIndex($base . 'filterable-attributes', json_encode(['id_manufacturer', 'out_of_stock', 'condition', 'ids_category', 'quantity', 'feature_values', 'visibility', 'available_for_order']), 'PUT');

        return isset($resp->taskUid) ? (int) $resp->taskUid : null;
    }

    /** UID de l'index temporaire de reconstruction pour une langue. */
    private function tmpUid(string $isoCode): string
    {
        return $this->indexUid($isoCode) . '_tmp';
    }

    /** Supprime un index entier (idempotent : DELETE sur index inexistant est sans effet). */
    private function deleteIndexUid(string $uid): void
    {
        $this->module->requestCurlIndex($this->meiliUrl . 'indexes/' . $uid, null, 'DELETE');
    }

    /** Vrai si l'index existe (GET /indexes/{uid} renvoie un objet avec `uid`). */
    private function indexExists(string $uid): bool
    {
        $resp = $this->module->requestCurlSearch($this->meiliUrl . 'indexes/' . $uid);

        return isset($resp->uid);
    }

    /**
     * Nombre de documents effectivement indexés (documents TRAITÉS) dans un index.
     *
     * @return int|null null si l'index n'existe pas / réponse inattendue
     */
    private function getNumberOfDocuments(string $uid): ?int
    {
        $resp = $this->module->requestCurlSearch($this->meiliUrl . 'indexes/' . $uid . '/stats');

        return isset($resp->numberOfDocuments) ? (int) $resp->numberOfDocuments : null;
    }

    /**
     * Bascule atomique du contenu (documents + settings) entre deux index. Les UID
     * ne changent pas : le front continue d'interroger le même nom d'index.
     *
     * @return int|null taskUid du swap (null si échec réseau)
     */
    private function swapIndexes(string $uidA, string $uidB): ?int
    {
        $resp = $this->module->requestCurlIndex(
            $this->meiliUrl . 'swap-indexes',
            json_encode([['indexes' => [$uidA, $uidB]]]),
            'POST'
        );

        return isset($resp->taskUid) ? (int) $resp->taskUid : null;
    }

    /**
     * Attend la fin d'une tâche Meilisearch (polling GET /tasks/{uid}), avec backoff.
     * Ne suit QUE la tâche donnée (ne pas scanner /tasks : ensureIndex sur un index
     * existant enfile un `index_already_exists` en échec, légitime).
     *
     * @return bool true si `succeeded` ; false si `failed`/`canceled`/timeout/taskUid null
     */
    private function waitForTask(?int $taskUid, int $timeoutSeconds = 300): bool
    {
        if ($taskUid === null) {
            return false;
        }

        $deadline = time() + $timeoutSeconds;
        $sleepUs = 200000; // 200 ms
        $maxSleepUs = 2000000; // 2 s

        while (time() < $deadline) {
            $task = $this->module->requestCurlSearch($this->meiliUrl . 'tasks/' . $taskUid);
            if (isset($task->status)) {
                if ($task->status === 'succeeded') {
                    return true;
                }
                if ($task->status === 'failed' || $task->status === 'canceled') {
                    return false;
                }
                // enqueued / processing → on continue à attendre
            }
            // $task null (réseau/timeout transitoire) → on retente jusqu'au deadline
            usleep($sleepUs);
            $sleepUs = min($sleepUs * 2, $maxSleepUs);
        }

        return false;
    }

    /**
     * Compatibilité /swap-indexes (Meilisearch >= 0.29), mise en cache sur la durée
     * du process (indexAllProducts boucle sur les langues). Version indéterminée =>
     * considérée supportée (déploiements modernes en v1.x ; le chemin swap est de
     * toute façon auto-protégé : un échec laisse le live intact).
     */
    private function supportsSwap(): bool
    {
        if (self::$swapSupported !== null) {
            return self::$swapSupported;
        }

        $resp = $this->module->requestCurlSearch($this->meiliUrl . 'version');
        self::$swapSupported = !isset($resp->pkgVersion)
            || version_compare((string) $resp->pkgVersion, '0.29.0', '>=');

        return self::$swapSupported;
    }

    /**
     * Verrou applicatif MySQL non-bloquant (auto-libéré si le process meurt). Empêche
     * deux réindexations complètes concurrentes d'utiliser le même index temporaire.
     */
    /** Nom du verrou de réindexation complète d'un index live (partagé cron/CLI/admin). */
    private function lockName(string $liveUid): string
    {
        return 'meili_ridx_' . md5($liveUid);
    }

    private function acquireLock(string $name): bool
    {
        $safe = \pSQL($name);
        $res = \Db::getInstance()->getValue("SELECT GET_LOCK('" . $safe . "', 0)");

        return (string) $res === '1';
    }

    private function releaseLock(string $name): void
    {
        $safe = \pSQL($name);
        \Db::getInstance()->execute("SELECT RELEASE_LOCK('" . $safe . "')");
    }

    /**
     * feature_values à plat : [id_product => ["2-36", "2-60", ...]]
     *
     * @return array<int, string[]>
     */
    private function buildFeatureValues(string $productIdsStr): array
    {
        if ($productIdsStr === '') {
            return [];
        }

        $rows = \Db::getInstance()->executeS('
            SELECT id_product, id_feature, id_feature_value
            FROM `' . _DB_PREFIX_ . 'feature_product`
            WHERE id_product IN (' . $productIdsStr . ')
        ');

        $map = [];
        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            $map[$idProduct][] = (int) $row['id_feature'] . '-' . (int) $row['id_feature_value'];
        }

        return $map;
    }

    /**
     * ids_category avec expansion récursive des ancêtres (un produit d'une sous-catégorie
     * apparaît dans ses catégories parentes) : [id_product => [id_cat, ...ancêtres]]
     *
     * @return array<int, int[]>
     */
    private function buildCategoryIds(string $productIdsStr): array
    {
        if ($productIdsStr === '') {
            return [];
        }

        $rows = \Db::getInstance()->executeS('
            SELECT id_product, id_category
            FROM `' . _DB_PREFIX_ . 'category_product`
            WHERE id_product IN (' . $productIdsStr . ')
        ');

        $productCategoryIds = [];
        foreach ($rows as $row) {
            $productCategoryIds[(int) $row['id_product']][] = (int) $row['id_category'];
        }

        $catParent = [];
        foreach (\Db::getInstance()->executeS('
            SELECT id_category, id_parent FROM `' . _DB_PREFIX_ . 'category`
        ') as $row) {
            $catParent[(int) $row['id_category']] = (int) $row['id_parent'];
        }

        $catChainCache = [];
        foreach ($productCategoryIds as $idProduct => $cats) {
            $expanded = [];
            foreach ($cats as $catId) {
                if (!isset($catChainCache[$catId])) {
                    $chain = [];
                    $current = $catId;
                    $guard = 0;
                    while ($current > 0 && !in_array($current, $chain, true) && $guard < 1000) {
                        $chain[] = $current;
                        $current = $catParent[$current] ?? 0;
                        ++$guard;
                    }
                    $catChainCache[$catId] = $chain;
                }
                foreach ($catChainCache[$catId] as $ancestor) {
                    $expanded[$ancestor] = true;
                }
            }
            $productCategoryIds[$idProduct] = array_keys($expanded);
        }

        return $productCategoryIds;
    }

    /**
     * Ventes totales (tout temps) depuis l'agrégat natif PrestaShop `product_sale`
     * (maintenu par PS à partir des commandes valides) : [id_product => quantité vendue]
     *
     * @return array<int, int>
     */
    private function buildSales(string $productIdsStr): array
    {
        if ($productIdsStr === '') {
            return [];
        }

        $rows = \Db::getInstance()->executeS('
            SELECT `id_product`, `quantity` AS sales
            FROM `' . _DB_PREFIX_ . 'product_sale`
            WHERE `id_product` IN (' . $productIdsStr . ')
        ');

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id_product']] = (int) $row['sales'];
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function typeMap(): array
    {
        return [
            'id_product' => 'int',
            'id_supplier' => 'int',
            'id_manufacturer' => 'int',
            'id_category_default' => 'int',
            'id_shop_default' => 'int',
            'id_tax_rules_group' => 'int',
            'on_sale' => 'bool',
            'online_only' => 'bool',
            'low_stock_alert' => 'bool',
            'quantity' => 'int',
            'minimal_quantity' => 'int',
            'price' => 'float',
            'wholesale_price' => 'float',
            'unit_price_ratio' => 'float',
            'additional_shipping_cost' => 'float',
            'width' => 'float',
            'height' => 'float',
            'depth' => 'float',
            'weight' => 'float',
            'out_of_stock' => 'int',
            'additional_delivery_times' => 'bool',
            'quantity_discount' => 'bool',
            'customizable' => 'bool',
            'uploadable_files' => 'bool',
            'text_fields' => 'bool',
            'active' => 'bool',
            'id_type_redirected' => 'int',
            'available_for_order' => 'bool',
            'show_condition' => 'bool',
            'show_price' => 'bool',
            'indexed' => 'bool',
            'cache_is_pack' => 'bool',
            'cache_has_attachments' => 'bool',
            'is_virtual' => 'bool',
            'cache_default_attribute' => 'int',
            'advanced_stock_management' => 'bool',
            'pack_stock_type' => 'int',
            'state' => 'int',
            'atoosync' => 'bool',
            'id_shop' => 'int',
            'final_price' => 'float',
            'id_lang' => 'int',
        ];
    }
}
