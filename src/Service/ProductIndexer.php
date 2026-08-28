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
    public function indexAllProducts(?array $isoFilter = null, int $batchSize = 200): void
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
    public function indexLanguage(array $language, ?array $productIds = null, bool $applySettings = true, int $batchSize = 200): void
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
        $lockName = 'meili_ridx_' . md5($live);

        // Verrou anti-concurrence (cron + admin simultanés utiliseraient le même _tmp).
        if (!$this->acquireLock($lockName)) {
            \PrestaShopLogger::addLog('Meilisearch: réindexation "' . $live . '" ignorée (verrou pris, run concurrent)', 2);

            return;
        }

        try {
            // Meili < 0.29 : /swap-indexes indisponible → repli sur l'upsert additif
            // historique (sans purge des orphelins), pour ne pas casser l'indexation.
            if (!$this->supportsSwap()) {
                \PrestaShopLogger::addLog('Meilisearch: /swap-indexes non supporté (< 0.29), réindexation additive sur "' . $live . '"', 2);
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
            $expected = count($docs);
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

            // Le swap exige que les deux index existent (premier run : le live n'existe pas).
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
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('Meilisearch: exception réindexation "' . $live . '" : ' . $e->getMessage(), 3);
            // Best-effort : nettoyage du tmp, jamais du live.
            $this->deleteIndexUid($tmp);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Construit les documents produits prêts à indexer pour une langue : SELECT
     * (produits `active = 1`), cast selon le typeMap, enrichissement feature_values /
     * ids_category / sales. Partagé par le mode full et le mode sous-ensemble.
     *
     * @param array $language ligne Language::getLanguages()
     * @param int[]|null $productIds null = tous les produits, sinon sous-ensemble
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDocuments(array $language, ?array $productIds): array
    {
        $idLang = (int) $language['id_lang'];

        $idFilter = '';
        if ($productIds !== null) {
            $ids = array_map('intval', $productIds);
            $idFilter = ' AND p.`id_product` IN (' . implode(',', $ids) . ')';
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
            AND product_shop.`active` = 1' . $idFilter . '
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
        $lastTask = null;
        foreach (array_chunk($products, $batchSize) as $chunk) {
            // ?primaryKey=id_product : garantit la bonne clé si l'index est auto-créé
            // par cet ajout (le catalogue a plusieurs champs id_* → inférence ambiguë).
            $resp = $this->module->requestCurlIndex(
                $this->meiliUrl . 'indexes/' . $uid . '/documents?primaryKey=id_product',
                json_encode($chunk)
            );
            if (isset($resp->taskUid)) {
                $lastTask = (int) $resp->taskUid;
            }
        }

        return $lastTask;
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
