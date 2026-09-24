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

namespace PrestaShop\Module\MeiliSearch\Search;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;

/**
 * Décorateur de recherche : Meilisearch d'abord, recherche SQL native de PrestaShop en repli.
 *
 * C'est la « Surface B » de l'analyse de résilience. Les pages listing gardent leur listing
 * natif déjà rendu quand Meili tombe (Surface A), mais la page de recherche n'avait rien : elle
 * affichait 0 produit, donc la branche « No products available yet » du thème — le visiteur lit
 * « ce catalogue est vide » au lieu de « la recherche est en panne ».
 *
 * Invariant : ce décorateur ne doit JAMAIS faire pire que l'absence de repli. Toute anomalie du
 * chemin natif (classe absente sur cette version de PS, signature de constructeur différente,
 * exception SQL) est rattrapée et on renvoie le résultat Meili tel quel, c'est-à-dire le
 * comportement d'avant. Un repli qui plante pendant une panne serait le pire des deux mondes.
 */
class NativeFallbackSearchProvider implements ProductSearchProviderInterface
{
    /** Classe du cœur PS assurant la recherche SQL. Absente ⇒ pas de repli, sans erreur. */
    public const NATIVE_PROVIDER = 'PrestaShop\\PrestaShop\\Adapter\\Search\\SearchProductSearchProvider';

    /**
     * Champs de tri propres à Meilisearch, sans équivalent en SQL : le provider natif
     * construit son `ORDER BY` depuis le SortOrder, un `meilisearch.sales` produirait une
     * requête invalide. On les rabat sur le tri par défaut de PrestaShop.
     */
    public const MEILI_ONLY_SORT_FIELDS = ['relevance', 'sales'];

    /** @var MeiliSearchProductSearchProvider */
    private $meili;

    /** @var mixed Translator PS — volontairement non typé, aucune interface ne tient sur 1.7/8/9 */
    private $translator;

    /**
     * @param MeiliSearchProductSearchProvider $meili
     * @param mixed $translator
     */
    public function __construct(MeiliSearchProductSearchProvider $meili, $translator)
    {
        $this->meili = $meili;
        $this->translator = $translator;
    }

    /**
     * {@inheritdoc}
     */
    public function runQuery(ProductSearchContext $context, ProductSearchQuery $query)
    {
        $result = $this->meili->runQuery($context, $query);

        // Panne avérée uniquement : `$lastRequestFailed` n'est jamais posé sur un « 0 résultat
        // légitime », qui doit continuer à afficher « aucun produit ne correspond ».
        if (!MeiliSearchProductSearchProvider::$lastRequestFailed) {
            return $result;
        }

        $native = $this->runNativeQuery($context, $query);

        return $native instanceof ProductSearchResult ? $native : $result;
    }

    /**
     * Les tris proposés restent ceux de Meilisearch : le visiteur ne doit pas voir la liste
     * déroulante changer parce que le moteur a basculé.
     *
     * @return array
     */
    public function getAvailableSortOrders(ProductSearchQuery $query): array
    {
        return $this->meili->getAvailableSortOrders($query);
    }

    /**
     * Exécute la recherche SQL native, en rattrapant tout.
     *
     * @return ProductSearchResult|null null si le repli est indisponible ou a échoué
     */
    private function runNativeQuery(ProductSearchContext $context, ProductSearchQuery $query)
    {
        if (!class_exists(self::NATIVE_PROVIDER)) {
            return null;
        }

        try {
            $class = self::NATIVE_PROVIDER;
            $provider = new $class($this->translator);

            $result = $provider->runQuery($context, $this->nativeQuery($query));
            if (!$result instanceof ProductSearchResult) {
                return null;
            }

            // Le thème lit le tri courant pour cocher la bonne entrée : on réaffiche celui que
            // le visiteur a demandé, pas celui qu'on a dû forger pour le SQL.
            $result->setCurrentSortOrder($query->getSortOrder());
            $result->setAvailableSortOrders($this->getAvailableSortOrders($query));

            \PrestaShopLogger::addLog(
                'Meilisearch indisponible (page recherche) — repli sur la recherche SQL native, '
                    . (int) $result->getTotalProductsCount() . ' résultat(s)',
                2
            );

            return $result;
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog(
                'Meilisearch: repli natif de la page recherche indisponible : ' . $e->getMessage(),
                3
            );

            return null;
        }
    }

    /**
     * Copie de la requête avec un tri que le SQL natif sait traiter.
     *
     * On clone plutôt que de muter : la requête d'origine est relue ensuite par le contrôleur
     * et par le thème, qui doivent continuer à voir le tri choisi par le visiteur.
     *
     * @return ProductSearchQuery
     */
    private function nativeQuery(ProductSearchQuery $query)
    {
        $native = clone $query;
        $sortOrder = $query->getSortOrder();

        $field = $sortOrder->getField();

        if (in_array($field, self::MEILI_ONLY_SORT_FIELDS, true)) {
            // `relevance` et `sales` n'existent pas côté SQL : tri par défaut de PrestaShop.
            $native->setSortOrder(new SortOrder('product', 'position', 'asc'));
        } else {
            // Champs communs (name, price, date_add) : seule l'entité change.
            $native->setSortOrder(new SortOrder('product', $field, $sortOrder->getDirection()));
        }

        return $native;
    }
}
