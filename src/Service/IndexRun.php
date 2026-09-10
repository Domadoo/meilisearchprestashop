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
 * Réindexation « pas à pas » pilotée depuis l'admin en AJAX.
 *
 * Le navigateur appelle start() puis step() en boucle : chaque step consomme un budget
 * de travail borné ({@see STEP_BUDGET}) puis rend la main avec l'avancement. La requête
 * HTTP reste donc courte — plus de réindexation coupée par max_execution_time ou par un
 * timeout de proxy (504) — et la progression affichée est réelle (produits envoyés).
 *
 * L'état du run est persisté en base (clé de configuration {@see CONFIG_KEY}) : un
 * rechargement de page, voire un autre onglet, reprend le run là où il en était.
 *
 * Concurrence :
 * - deux step() simultanés sont sérialisés par un verrou MySQL le temps de la requête ;
 * - un run AJAX actif bloque le cron/CLI (via IndexRun::isActiveFor(), consulté par
 *   ProductIndexer::fullReindexLanguage()) et réciproquement (isFullReindexRunning()) ;
 * - un run dont le heartbeat a plus de {@see LEASE_TTL} secondes est considéré abandonné
 *   (onglet fermé) : il n'est plus « actif » et un nouveau run peut le remplacer.
 */
class IndexRun
{
    /** Clé de configuration portant l'état JSON du run en cours. */
    public const CONFIG_KEY = 'MEILISEARCHPRESTASHOP_INDEX_RUN';

    /** Produits par tranche (= par POST Meili) par défaut, comme indexAllProducts()/CLI. */
    public const SLICE_SIZE = 100;

    /**
     * Plancher de la taille de tranche. En dessous, un envoi qui échoue encore ne vient
     * plus d'un payload trop gros : insister n'a plus de sens.
     */
    private const SLICE_MIN = 25;

    /** Un run sans heartbeat depuis ce délai (s) est considéré abandonné. */
    private const LEASE_TTL = 120;

    /** Budget de travail (s) par requête AJAX : garde les requêtes courtes. */
    private const STEP_BUDGET = 4.0;

    /** Backpressure : toutes les N tranches, attendre que Meili ait digéré la dernière. */
    private const BACKPRESSURE_EVERY = 10;

    /** Verrou (durée d'une requête) sérialisant deux step() concurrents. */
    private const STEP_LOCK = 'meili_index_run_step';

    /** Nombre de lignes de journal conservées dans l'état. */
    private const LOG_MAX = 40;

    /** @var \Meilisearchprestashop */
    private $module;

    /** @var ProductIndexer */
    private $indexer;

    /**
     * @param \Meilisearchprestashop|null $module si null, résolu via Module::getInstanceByName
     */
    public function __construct($module = null)
    {
        /** @var \Meilisearchprestashop $resolved */
        $resolved = $module ?: \Module::getInstanceByName('meilisearchprestashop');
        $this->module = $resolved;
        $this->indexer = new ProductIndexer($resolved);
    }

    /** Vrai si un run est en cours ET vivant (heartbeat récent). */
    public static function isActive(): bool
    {
        return self::activeState() !== null;
    }

    /** Vrai si un run vivant couvre cette langue (déjà traitée exclue). */
    public static function isActiveFor(string $isoCode): bool
    {
        $state = self::activeState();
        if ($state === null) {
            return false;
        }

        if (isset($state['cur']['iso']) && $state['cur']['iso'] === $isoCode) {
            return true;
        }
        foreach ($state['queue'] as $language) {
            if ($language['iso'] === $isoCode) {
                return true;
            }
        }

        return false;
    }

    /**
     * Démarre un run.
     *
     * @param string[] $isos iso_code à réindexer (vide = toutes les langues)
     *
     * @return array payload de progression, ou ['error' => message] si refusé
     */
    public function start(array $isos, ?int $sliceSize = null): array
    {
        if (self::isActive()) {
            return ['error' => $this->module->l('An indexation is already running.', 'indexrun')];
        }

        if (!\Configuration::get('MEILISEARCHPRESTASHOP_URL')) {
            return ['error' => $this->module->l('Meilisearch URL is not configured.', 'indexrun')];
        }

        $languages = [];
        foreach (\Language::getLanguages() as $language) {
            if (!empty($isos) && !in_array($language['iso_code'], $isos, true)) {
                continue;
            }
            $languages[] = $language;
        }

        if (empty($languages)) {
            return ['error' => $this->module->l('No language to index.', 'indexrun')];
        }

        // Un cron/CLI déjà en train de réindexer une de ces langues utilise le même index
        // temporaire : on refuse plutôt que de se marcher dessus.
        foreach ($languages as $language) {
            if ($this->indexer->isFullReindexRunning($language['iso_code'])) {
                return ['error' => sprintf(
                    $this->module->l('A reindex of language "%s" is already running (CRON or CLI).', 'indexrun'),
                    $language['iso_code']
                )];
            }
        }

        $abandoned = self::read();
        $queue = [];
        $totalAll = 0;
        foreach ($languages as $language) {
            $total = $this->indexer->countProducts($language);
            $totalAll += $total;
            $queue[] = [
                'iso' => $language['iso_code'],
                'name' => $language['name'],
                'id_lang' => (int) $language['id_lang'],
                'total' => $total,
            ];
        }

        $state = [
            'startedAt' => time(),
            'updatedAt' => time(),
            'sliceSize' => max(10, min(1000, (int) ($sliceSize ?: self::SLICE_SIZE))),
            'queue' => $queue,
            'cur' => null,
            'done' => [],
            'indexedAll' => 0,
            'totalAll' => $totalAll,
            'errors' => 0,
            'finished' => false,
            'log' => [],
        ];

        if ($abandoned !== null && empty($abandoned['finished'])) {
            $state = $this->log($state, 'warn', $this->module->l('A previous indexation was interrupted; it is replaced by this one.', 'indexrun'));
        }
        $state = $this->log($state, 'info', sprintf(
            $this->module->l('Indexation started: %1$d language(s), %2$d product(s).', 'indexrun'),
            count($queue),
            $totalAll
        ));

        self::write($state);

        return $this->progress($state);
    }

    /**
     * Avance le run pendant au plus {@see STEP_BUDGET} secondes.
     *
     * @return array payload de progression
     */
    public function step(): array
    {
        $state = self::read();
        if ($state === null) {
            return $this->idlePayload();
        }
        if (!empty($state['finished'])) {
            return $this->progress($state);
        }

        if (!$this->acquireStepLock()) {
            // Un autre appel (autre onglet) avance déjà ce run : renvoyer l'état sans
            // travailler, le client réessaiera.
            $payload = $this->progress($state);
            $payload['waiting'] = true;

            return $payload;
        }

        $deadline = microtime(true) + self::STEP_BUDGET;
        $waiting = false;

        try {
            do {
                $outcome = $this->advance($state);
                $state = $outcome['state'];
                if ($outcome['waiting']) {
                    $waiting = true;
                    break;
                }
            } while (empty($state['finished']) && microtime(true) < $deadline);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('Meilisearch: exception réindexation AJAX : ' . $e->getMessage(), 3);
            $state = $this->failCurrent($state, $e->getMessage());
        } finally {
            $state['updatedAt'] = time();
            self::write($state);
            $this->releaseStepLock();
        }

        $payload = $this->progress($state);
        $payload['waiting'] = $waiting;

        return $payload;
    }

    /** Annule le run en cours : supprime l'index temporaire, le live reste intact. */
    public function abort(): array
    {
        $state = self::read();
        if ($state === null) {
            return $this->idlePayload();
        }

        if (!empty($state['cur'])) {
            $this->indexer->cancelLanguage($state['cur']);
        }
        \PrestaShopLogger::addLog('Meilisearch: réindexation AJAX annulée depuis l\'admin (live conservé)', 2);
        self::clear();

        $payload = $this->idlePayload();
        $payload['aborted'] = true;

        return $payload;
    }

    /**
     * Payload de progression destiné au navigateur.
     *
     * @param array|null $state état déjà chargé (évite une relecture)
     */
    public function progress(?array $state = null): array
    {
        $state = $state ?: self::read();
        if ($state === null) {
            return $this->idlePayload();
        }

        $cur = !empty($state['cur']) ? $state['cur'] : null;
        $finished = !empty($state['finished']);
        $totalAll = (int) $state['totalAll'];
        $indexedAll = (int) $state['indexedAll'];

        // La barre suit les produits envoyés ; la bascule finale (gates + swap) n'a pas
        // d'avancement mesurable → on plafonne à 99 % tant que le run n'est pas terminé.
        $percent = $totalAll > 0 ? (int) floor(min(100, ($indexedAll / $totalAll) * 100)) : ($finished ? 100 : 0);
        if (!$finished && $percent >= 100) {
            $percent = 99;
        }
        if ($finished) {
            $percent = 100;
        }

        return [
            'running' => !$finished,
            'finished' => $finished,
            'percent' => $percent,
            'indexed' => $indexedAll,
            'total' => $totalAll,
            'errors' => (int) $state['errors'],
            'languagesDone' => count($state['done']),
            'languagesTotal' => count($state['done']) + count($state['queue']) + ($cur ? 1 : 0),
            'phase' => $cur ? (string) $cur['phase'] : ($finished ? 'finished' : 'idle'),
            'phaseLabel' => $this->phaseLabel($cur ? (string) $cur['phase'] : ($finished ? 'finished' : 'idle'), $cur),
            'current' => $cur ? [
                'iso' => $cur['iso'],
                'name' => $cur['name'],
                'mode' => $cur['mode'],
                'indexed' => (int) $cur['indexed'],
                'total' => (int) $cur['total'],
            ] : null,
            'done' => $state['done'],
            'log' => $state['log'],
            'stale' => (time() - (int) $state['updatedAt']) > self::LEASE_TTL,
        ];
    }

    /**
     * Une unité de travail : sélection de la langue suivante, préparation, envoi d'une
     * tranche, ou une étape de la bascule finale.
     *
     * @return array{state: array, waiting: bool} waiting = rendre la main et laisser
     *                                            respirer Meili avant le prochain appel
     */
    private function advance(array $state): array
    {
        // ── Langue suivante ─────────────────────────────────────────────────────────
        if (empty($state['cur'])) {
            if (empty($state['queue'])) {
                $state['finished'] = true;
                $state = $this->log(
                    $state,
                    $state['errors'] > 0 ? 'warn' : 'info',
                    $state['errors'] > 0
                        ? $this->module->l('Indexation finished with errors: the live indexes concerned were kept as-is.', 'indexrun')
                        : $this->module->l('Indexation finished.', 'indexrun')
                );

                return ['state' => $state, 'waiting' => false];
            }

            $language = array_shift($state['queue']);
            $state['cur'] = [
                'iso' => $language['iso'],
                'name' => $language['name'],
                'id_lang' => (int) $language['id_lang'],
                'total' => (int) $language['total'],
                'sliceSize' => (int) $state['sliceSize'],
                'drain' => false,
                'phase' => 'prepare',
                'mode' => null,
                'target' => null,
                'live' => null,
                'indexed' => 0,
                'expected' => 0,
                'lastId' => 0,
                'slices' => 0,
                'taskUid' => null,
                'exhausted' => false,
                'finishPhase' => null,
                'waitSince' => null,
            ];

            return ['state' => $state, 'waiting' => false];
        }

        $cur = $state['cur'];
        $language = $this->language((string) $cur['iso']);
        if ($language === null) {
            return ['state' => $this->finishCurrent($state, 'error', sprintf(
                $this->module->l('Language "%s" not found.', 'indexrun'),
                $cur['iso']
            )), 'waiting' => false];
        }

        // ── Préparation : index cible + settings ────────────────────────────────────
        if ($cur['mode'] === null) {
            $prepared = $this->indexer->prepareLanguage($language);
            $cur['mode'] = $prepared['mode'];
            $cur['target'] = $prepared['target'];
            $cur['live'] = $prepared['live'];
            $cur['phase'] = 'push';
            $state['cur'] = $cur;

            return ['state' => $this->log($state, 'info', sprintf(
                $prepared['mode'] === 'swap'
                    ? $this->module->l('%1$s: building temporary index "%2$s".', 'indexrun')
                    : $this->module->l('%1$s: direct fill of index "%2$s" (no swap).', 'indexrun'),
                $cur['iso'],
                $prepared['target']
            )), 'waiting' => false];
        }

        // ── Envoi des tranches ──────────────────────────────────────────────────────
        if (empty($cur['exhausted'])) {
            // Backpressure : sur un gros catalogue, enfiler des dizaines de batches
            // d'affilée sature Meili et fait timeouter les POST tardifs. Toutes les N
            // tranches — et juste après un envoi perdu —, on ne pousse rien tant que la
            // dernière tâche n'est pas digérée.
            $mustDrain = !empty($cur['drain'])
                || ($cur['slices'] > 0 && $cur['slices'] % self::BACKPRESSURE_EVERY === 0);
            if ($mustDrain
                && $cur['taskUid'] !== null
                && $this->indexer->taskState((int) $cur['taskUid']) === 'pending') {
                $state['cur']['phase'] = 'throttle';

                return ['state' => $state, 'waiting' => true];
            }

            $sliceSize = (int) ($cur['sliceSize'] ?: $state['sliceSize']);
            $slice = $this->indexer->pushSlice(
                $language,
                (string) $cur['target'],
                (int) $cur['lastId'],
                $sliceSize
            );

            ++$cur['slices'];
            $cur['drain'] = false;

            // ── Envoi perdu : la tranche n'est PAS validée ────────────────────────────
            // On n'avance pas lastId (elle sera réessayée) et on divise la tranche par
            // deux : la cause la plus fréquente est un payload trop gros pour le
            // reverse-proxy ou le timeout (produits à grosses descriptions).
            if ($slice['rows'] > 0 && $slice['taskUid'] === null) {
                $state['cur'] = $cur;
                $state = $this->log($state, 'warn', sprintf(
                    $this->module->l('%1$s: a batch of %2$d product(s) was not queued — %3$s', 'indexrun'),
                    $cur['iso'],
                    $slice['rows'],
                    (string) $slice['error']
                ));

                $reduced = (int) max(self::SLICE_MIN, (int) floor($sliceSize / 2));
                if ($reduced < $sliceSize) {
                    $cur['sliceSize'] = $reduced;
                    $cur['drain'] = true;
                    $state['cur'] = $cur;
                    // Les langues suivantes hériteront de la tranche réduite : si la cause
                    // est une limite du serveur, elles la rencontreraient toutes.
                    $state['sliceSize'] = $reduced;

                    return ['state' => $this->log($state, 'warn', sprintf(
                        $this->module->l('%1$s: retrying that batch with %2$d products per batch.', 'indexrun'),
                        $cur['iso'],
                        $reduced
                    )), 'waiting' => true];
                }

                // Déjà au plancher : envoyer le reste du catalogue ne servirait à rien
                // (le gate de comptage annulerait la bascule de toute façon). On arrête
                // ici et on supprime le tmp — le live reste intact.
                $this->indexer->cancelLanguage($cur);

                return [
                    'state' => $this->finishCurrent($state, 'error', $this->module->l('Products could not be sent to Meilisearch: swap cancelled, live index kept. See the shop logs for the HTTP/cURL cause.', 'indexrun')),
                    'waiting' => false,
                ];
            }

            $cur['indexed'] = (int) $cur['indexed'] + $slice['unique'];
            // `expected` ne compte que les documents réellement enfilés : le gate de
            // comptage détectera tout écart avec ce que le tmp contient.
            $cur['expected'] = (int) $cur['expected'] + $slice['unique'];
            $cur['lastId'] = (int) $slice['lastId'];
            $cur['exhausted'] = (bool) $slice['exhausted'];
            $cur['phase'] = $cur['exhausted'] ? 'finish' : 'push';
            if ($slice['taskUid'] !== null) {
                $cur['taskUid'] = (int) $slice['taskUid'];
            }
            $state['cur'] = $cur;
            $state['indexedAll'] = (int) $state['indexedAll'] + $slice['unique'];

            return ['state' => $state, 'waiting' => false];
        }

        // ── Bascule finale : attentes, gates, swap, nettoyage ───────────────────────
        $outcome = $this->indexer->finishLanguage($language, $cur);
        $state['cur'] = $outcome['ctx'];
        $state['cur']['phase'] = 'finish';

        if ($outcome['status'] === 'pending') {
            // Polling de tâche Meili : laisser passer un délai avant le prochain appel.
            return ['state' => $state, 'waiting' => true];
        }

        return [
            'state' => $this->finishCurrent($state, $outcome['status'] === 'error' ? 'error' : 'ok', $outcome['message']),
            'waiting' => false,
        ];
    }

    /** Clôt la langue courante (succès ou échec) et passe à la suivante. */
    private function finishCurrent(array $state, string $status, ?string $message): array
    {
        $cur = $state['cur'];
        $state['done'][] = [
            'iso' => $cur['iso'],
            'name' => $cur['name'],
            'indexed' => (int) $cur['indexed'],
            'status' => $status,
            'error' => $message,
        ];

        if ($status === 'error') {
            $state['errors'] = (int) $state['errors'] + 1;
            $state = $this->log($state, 'error', $cur['iso'] . ' : ' . (string) $message);
        } else {
            $state = $this->log($state, 'info', sprintf(
                $this->module->l('%1$s: %2$d product(s) indexed.', 'indexrun'),
                $cur['iso'],
                (int) $cur['indexed']
            ));
        }

        $state['cur'] = null;

        return $state;
    }

    /** Échec inattendu (exception) : on abandonne la langue courante, jamais le live. */
    private function failCurrent(array $state, string $message): array
    {
        if (empty($state['cur'])) {
            return $state;
        }
        $this->indexer->cancelLanguage($state['cur']);

        return $this->finishCurrent($state, 'error', $message);
    }

    /** Libellé de phase affiché sous la barre de progression. */
    private function phaseLabel(string $phase, ?array $cur): string
    {
        switch ($phase) {
            case 'prepare':
                return $this->module->l('Preparing index…', 'indexrun');
            case 'push':
                return $this->module->l('Sending products…', 'indexrun');
            case 'throttle':
                return $this->module->l('Waiting for Meilisearch to catch up…', 'indexrun');
            case 'finish':
                return ($cur !== null && ($cur['mode'] ?? '') === 'swap')
                    ? $this->module->l('Verifying and swapping index…', 'indexrun')
                    : $this->module->l('Finalizing…', 'indexrun');
            case 'finished':
                return $this->module->l('Finished.', 'indexrun');
            default:
                return '';
        }
    }

    /** @return array|null ligne Language::getLanguages() correspondant à l'iso */
    private function language(string $isoCode): ?array
    {
        foreach (\Language::getLanguages() as $language) {
            if ($language['iso_code'] === $isoCode) {
                return $language;
            }
        }

        return null;
    }

    private function log(array $state, string $level, string $message): array
    {
        $state['log'][] = ['t' => time(), 'level' => $level, 'msg' => $message];
        if (count($state['log']) > self::LOG_MAX) {
            $state['log'] = array_slice($state['log'], -self::LOG_MAX);
        }

        return $state;
    }

    private function idlePayload(): array
    {
        return [
            'running' => false,
            'finished' => false,
            'idle' => true,
            'percent' => 0,
            'errors' => 0,
            'log' => [],
            'done' => [],
        ];
    }

    /** @return array|null état du run s'il existe ET est vivant */
    private static function activeState(): ?array
    {
        $state = self::read();
        if ($state === null || !empty($state['finished'])) {
            return null;
        }

        return (time() - (int) $state['updatedAt']) <= self::LEASE_TTL ? $state : null;
    }

    /** @return array|null état brut du run (même abandonné), null si aucun */
    private static function read(): ?array
    {
        $raw = \Configuration::get(self::CONFIG_KEY);
        if (empty($raw)) {
            return null;
        }
        $state = json_decode((string) $raw, true);

        return is_array($state) && isset($state['queue'], $state['updatedAt']) ? $state : null;
    }

    private static function write(array $state): void
    {
        \Configuration::updateValue(self::CONFIG_KEY, (string) json_encode($state));
    }

    public static function clear(): void
    {
        \Configuration::deleteByName(self::CONFIG_KEY);
    }

    /**
     * Verrou non bloquant, valable le temps de la requête (GET_LOCK est lié à la
     * connexion MySQL) : empêche deux step() concurrents de doubler une tranche ou,
     * pire, un swap.
     */
    private function acquireStepLock(): bool
    {
        try {
            return (string) \Db::getInstance()->getValue(
                "SELECT GET_LOCK('" . \pSQL(self::STEP_LOCK) . "', 0)"
            ) === '1';
        } catch (\Throwable $e) {
            // GET_LOCK indisponible : ne pas bloquer la fonctionnalité pour autant.
            return true;
        }
    }

    private function releaseStepLock(): void
    {
        try {
            \Db::getInstance()->execute("SELECT RELEASE_LOCK('" . \pSQL(self::STEP_LOCK) . "')");
        } catch (\Throwable $e) {
            // Libéré de toute façon à la fermeture de la connexion.
        }
    }
}
