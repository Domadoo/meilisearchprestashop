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
 * Contrat d'un cache de réponses Meilisearch (corps JSON bruts).
 *
 * Permet de remplacer le backend filesystem par APCu/Redis sans toucher aux
 * appelants (interface réutilisable pour le circuit breaker #4).
 */
interface MeilisearchCacheInterface
{
    /**
     * Retourne le corps caché pour cette clé s'il existe et n'est pas expiré, sinon null.
     *
     * @param string $key clé issue de key()
     * @param int $ttl durée de vie en secondes
     *
     * @return string|null corps JSON brut, ou null si absent/expiré/erreur
     */
    public function get($key, $ttl);

    /**
     * Stocke le corps JSON brut sous cette clé (best-effort, silencieux sur échec).
     *
     * @param string $key
     * @param string $body corps JSON brut de la réponse Meili
     */
    public function set($key, $body);

    /**
     * Construit une clé stable pour un couple (url, payload), incluant la génération.
     *
     * @param string $url
     * @param string $payload
     *
     * @return string
     */
    public function key($url, $payload);

    /**
     * Jeton de génération courant (inclus dans la clé). Un bump invalide tout le cache.
     *
     * @return int
     */
    public function getGeneration();

    /**
     * Invalide l'ensemble du cache en changeant la génération (ex: après un full reindex).
     */
    public function bumpGeneration();
}
