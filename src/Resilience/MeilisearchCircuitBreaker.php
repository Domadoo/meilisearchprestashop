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

namespace PrestaShop\Module\MeiliSearch\Resilience;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Circuit breaker des lectures Meilisearch du FRONT.
 *
 * Objectif : quand Meili est injoignable, arrêter d'appeler Meili au lieu de laisser
 * chaque worker PHP-FPM brûler 5 s par requête (× 1+N sous-requêtes disjunctives sur une
 * page filtrée). C'est le mécanisme de l'incident crawl Googlebot : ~1000 URLs filtrées
 * crawlées en parallèle saturent les workers ET Meilisearch.
 *
 * État persisté dans un unique fichier du cache PS, autonome : aucune dépendance infra
 * (ni APCu, ni `_PS_CACHE_ENABLED_`). Toute erreur d'E/S dégrade en `allow() === true`,
 * c'est-à-dire vers le comportement actuel — jamais de fatal en front.
 *
 * Machine à états :
 *   fermé  --(FAILURE_THRESHOLD échecs en FAILURE_WINDOW s)-->  ouvert
 *   ouvert --(OPEN_DURATION s écoulées)-->  semi-ouvert (une seule sonde à la fois)
 *   sonde OK     --> fermé
 *   sonde en échec --> ouvert pour une nouvelle OPEN_DURATION
 */
class MeilisearchCircuitBreaker
{
    /** Nom du fichier d'état dans le dossier de cache du module. */
    public const STATE_FILE = 'breaker.state';

    /** Nombre d'échecs consécutifs (dans FAILURE_WINDOW) qui ouvrent le circuit. */
    public const FAILURE_THRESHOLD = 5;

    /** Fenêtre glissante (s) de comptage des échecs. Au-delà, le compteur repart de zéro. */
    public const FAILURE_WINDOW = 60;

    /** Durée (s) pendant laquelle le circuit ouvert court-circuite tout appel. */
    public const OPEN_DURATION = 30;

    /** Intervalle (s) minimal entre deux sondes semi-ouvertes (anti-ruée de workers). */
    public const PROBE_INTERVAL = 5;

    /** @var string Dossier d'état (avec `/` final) — mutualisé avec le cache de réponses */
    private $dir;

    /** @var array|null État mémoïsé pour la requête courante (null = circuit fermé) */
    private $state;

    /** @var bool L'état a-t-il déjà été lu depuis le disque dans cette requête ? */
    private $stateLoaded = false;

    public function __construct()
    {
        $this->dir = rtrim(_PS_CACHE_DIR_, '/') . '/meilisearchprestashop/';
    }

    /**
     * Le circuit autorise-t-il un appel Meili maintenant ?
     *
     * @return bool false uniquement si le circuit est ouvert et la fenêtre non écoulée
     */
    public function allow()
    {
        $state = $this->loadState();
        if ($state === null) {
            return true;
        }

        $openedAt = (int) $state['openedAt'];
        if ($openedAt === 0) {
            // Des échecs sont comptés mais le seuil n'est pas atteint : on laisse passer.
            return true;
        }

        $now = time();
        if (($now - $openedAt) < self::OPEN_DURATION) {
            return false;
        }

        // Semi-ouvert : une seule sonde par PROBE_INTERVAL, sinon N workers simultanés
        // rejouent tous l'appel lent qu'on cherche justement à éviter.
        if (($now - (int) $state['probeAt']) < self::PROBE_INTERVAL) {
            return false;
        }

        $state['probeAt'] = $now;
        $this->writeState($state);

        return true;
    }

    /**
     * Enregistre un échec (réseau, timeout ou 5xx). Un 4xx n'est PAS un échec
     * d'indisponibilité et ne doit pas arriver ici.
     *
     * @return bool true si cet échec vient d'ouvrir le circuit (à logguer une seule fois)
     */
    public function recordFailure()
    {
        $now = time();
        $state = $this->loadState();
        $wasOpen = $state !== null && (int) $state['openedAt'] > 0;

        $failures = $state === null ? 0 : (int) $state['failures'];
        $firstFailureAt = $state === null ? 0 : (int) $state['firstFailureAt'];

        // Fenêtre glissante : un échec isolé il y a longtemps ne doit pas s'additionner
        // à un échec d'aujourd'hui.
        if ($firstFailureAt === 0 || ($now - $firstFailureAt) > self::FAILURE_WINDOW) {
            $firstFailureAt = $now;
            $failures = 0;
        }
        ++$failures;

        $newState = [
            'failures' => $failures,
            'firstFailureAt' => $firstFailureAt,
            'openedAt' => $state === null ? 0 : (int) $state['openedAt'],
            'probeAt' => $state === null ? 0 : (int) $state['probeAt'],
        ];

        if ($wasOpen) {
            // La sonde semi-ouverte a échoué : on repart pour une OPEN_DURATION complète.
            $newState['openedAt'] = $now;
            $this->writeState($newState);

            return false;
        }

        $justOpened = $failures >= self::FAILURE_THRESHOLD;
        if ($justOpened) {
            $newState['openedAt'] = $now;
        }
        $this->writeState($newState);

        return $justOpened;
    }

    /**
     * Enregistre un succès : referme le circuit et remet les compteurs à zéro.
     * No-op sans aucune E/S quand le circuit est déjà fermé (cas nominal du front).
     *
     * @return bool true si cet appel vient de refermer un circuit ouvert (à logguer)
     */
    public function recordSuccess()
    {
        $state = $this->loadState();
        if ($state === null) {
            return false;
        }

        $wasOpen = (int) $state['openedAt'] > 0;
        $this->clearState();

        return $wasOpen;
    }

    /**
     * Le circuit est-il ouvert ? (lecture seule, sans effet de bord — pour l'affichage)
     *
     * @return bool
     */
    public function isOpen()
    {
        $state = $this->loadState();

        return $state !== null
            && (int) $state['openedAt'] > 0
            && (time() - (int) $state['openedAt']) < self::OPEN_DURATION;
    }

    /**
     * Lit l'état, une seule fois par requête PHP (les N sous-requêtes disjunctives ne
     * doivent pas re-`stat()` le fichier N fois).
     *
     * @return array|null état normalisé, ou null si circuit fermé/illisible
     */
    private function loadState()
    {
        if ($this->stateLoaded) {
            return $this->state;
        }
        $this->stateLoaded = true;
        $this->state = null;

        $file = $this->dir . self::STATE_FILE;
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // Fichier corrompu : on repart d'un circuit fermé plutôt que de bloquer le front.
            return null;
        }

        $this->state = [
            'failures' => isset($decoded['failures']) ? (int) $decoded['failures'] : 0,
            'firstFailureAt' => isset($decoded['firstFailureAt']) ? (int) $decoded['firstFailureAt'] : 0,
            'openedAt' => isset($decoded['openedAt']) ? (int) $decoded['openedAt'] : 0,
            'probeAt' => isset($decoded['probeAt']) ? (int) $decoded['probeAt'] : 0,
        ];

        return $this->state;
    }

    /**
     * Écrit l'état de façon atomique (tmp dans le MÊME dossier + rename).
     * Le mémo est mis à jour même si l'écriture disque échoue : la requête courante
     * reste cohérente avec elle-même.
     *
     * @param array $state
     */
    private function writeState(array $state)
    {
        $this->state = $state;
        $this->stateLoaded = true;

        if (!$this->ensureWritableDir()) {
            return;
        }

        $payload = json_encode($state);
        if ($payload === false) {
            return;
        }

        $tmp = $this->dir . self::STATE_FILE . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            @unlink($tmp);

            return;
        }

        if (!@rename($tmp, $this->dir . self::STATE_FILE)) {
            @unlink($tmp);
        }
    }

    /**
     * Supprime l'état (= circuit fermé).
     */
    private function clearState()
    {
        $this->state = null;
        $this->stateLoaded = true;
        @unlink($this->dir . self::STATE_FILE);
    }

    /**
     * Crée le dossier si besoin et vérifie qu'il est inscriptible.
     *
     * @return bool
     */
    private function ensureWritableDir()
    {
        if (is_dir($this->dir)) {
            return is_writable($this->dir);
        }

        // Création best-effort ; verdict final via is_writable.
        @mkdir($this->dir, 0755, true);

        return is_dir($this->dir) && is_writable($this->dir);
    }
}
