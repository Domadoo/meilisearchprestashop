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

namespace PrestaShop\Module\MeiliSearch\Cache;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Cache filesystem des réponses Meilisearch (corps JSON bruts), pour les pages
 * listing NON filtrées. Persistant cross-requêtes, autonome : aucune dépendance
 * infra (ni APCu, ni _PS_CACHE_ENABLED_). Toute erreur d'E/S dégrade en silence
 * vers un appel Meili direct — jamais de fatal en front.
 *
 * Invalidation : TTL (âge mtime) + jeton de génération bumpé au full reindex.
 */
class MeilisearchResponseCache implements MeilisearchCacheInterface
{
    /** Clé Configuration du jeton de génération (bumpé au full reindex confirmé). */
    const GENERATION_KEY = 'MEILISEARCHPRESTASHOP_CACHE_GEN';

    /** Âge (s) au-delà duquel un fichier est éligible au GC. Doit dépasser le TTL servi. */
    const GC_MAX_AGE = 3600;

    /** Probabilité 1/N de déclencher un passage de GC lors d'un set(). */
    const GC_PROBABILITY = 100;

    /** Borne du nombre de suppressions par passage de GC (coût O(n) maîtrisé). */
    const GC_MAX_DELETIONS = 200;

    /** @var string Dossier de cache (avec `/` final) */
    private $dir;

    public function __construct()
    {
        $this->dir = rtrim(_PS_CACHE_DIR_, '/') . '/meilisearchprestashop/';
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, $ttl)
    {
        $file = $this->dir . $key . '.cache';
        if (!is_file($file)) {
            return null;
        }

        $mtime = @filemtime($file);
        if ($mtime === false || (time() - $mtime) >= (int) $ttl) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $body = @gzdecode($raw);

        return $body === false ? null : $body;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $body)
    {
        if (!is_string($body) || $body === '') {
            return;
        }

        if (!$this->ensureWritableDir()) {
            return;
        }

        $compressed = @gzencode($body);
        if ($compressed === false) {
            return;
        }

        // Écriture atomique : fichier temporaire dans le MÊME dossier puis rename().
        $tmp = $this->dir . $key . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, $compressed, LOCK_EX) === false) {
            @unlink($tmp);

            return;
        }

        if (!@rename($tmp, $this->dir . $key . '.cache')) {
            @unlink($tmp);
        }

        $this->maybeCollectGarbage();
    }

    /**
     * {@inheritdoc}
     */
    public function key($url, $payload)
    {
        return sha1($url . '|' . $payload . '|' . $this->getGeneration());
    }

    /**
     * {@inheritdoc}
     */
    public function getGeneration()
    {
        // Configuration::get renvoie false si la clé n'existe pas → (int) false = 0.
        return (int) \Configuration::get(self::GENERATION_KEY);
    }

    /**
     * {@inheritdoc}
     */
    public function bumpGeneration()
    {
        // Timestamp (pas d'incrément) : évite toute course read-modify-write entre
        // deux réindexations concurrentes ; la clé n'a besoin que d'une valeur qui change.
        \Configuration::updateValue(self::GENERATION_KEY, time());
    }

    /**
     * Crée le dossier de cache si besoin et vérifie qu'il est inscriptible.
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

    /**
     * GC opportuniste : supprime les fichiers plus vieux que GC_MAX_AGE (reclaime aussi
     * les fichiers orphelins d'une génération précédente). Probabiliste et borné.
     */
    private function maybeCollectGarbage()
    {
        if (mt_rand(1, self::GC_PROBABILITY) !== 1) {
            return;
        }

        $files = @glob($this->dir . '*.cache');
        $stray = @glob($this->dir . '*.tmp');
        if (is_array($stray)) {
            $files = is_array($files) ? array_merge($files, $stray) : $stray;
        }
        if (!is_array($files)) {
            return;
        }

        $threshold = time() - self::GC_MAX_AGE;
        $deleted = 0;
        foreach ($files as $file) {
            if ($deleted >= self::GC_MAX_DELETIONS) {
                break;
            }
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $threshold) {
                @unlink($file);
                ++$deleted;
            }
        }
    }
}
