# Analyse perf & résilience — meilisearchprestashop

**Date** : 2026-06-20
**Contexte** : incident Googlebot saturant Meilisearch (crawl de toutes les combinaisons de filtres `?page=20&q=Marque-...`, ~1000 req concurrentes → panics). Serveur `domfront-1` (PrestaShop 1.7.8.11 prod).
**Pour discussion avec Adam.** Aucun code modifié — analyse statique uniquement, vérifiée contre le core PrestaShop 1.7.8.11 (github.com/PrestaShop/PrestaShop/tree/1.7.8.11).

---

## TL;DR — décisions à prendre

| # | Sujet | Verdict | Décision attendue |
|---|-------|---------|-------------------|
| 1 | Timeouts cURL (120s → fail-fast) | ✅ à faire, prérequis de tout le reste | OK pour split `requestCurlSearch` / `requestCurlIndex` ? |
| 2 | Noindex pages filtrées | ✅ à faire (condition à corriger : `s`/`encodedFacets`/`page`, pas `q`) | OK pour injection via `hookDisplayHeader` ? |
| 3 | N+1 SQL sur listing | ⚠️ double N+1 réel, fix risqué | On profile d'abord ou on tranche la direction Meili (cf. #5) ? |
| 4 | Fallback Meili down | ✅ priorité haute, à coupler avec #1 | Valider l'archi décrite ci-dessous |
| 5 | **Meili `['*']` vs liste d'IDs** | 🔴 **décision d'architecture structurante** | **Le vrai débat à avoir avec Adam** |
| 6 | Listing : AJAX vs hook SSR + cache | piste forte | Dépend du coût SQL natif (à mesurer) |

---

## 1. Timeouts cURL — à faire en premier

**Constat** : `requestCurl()` (`meilisearchprestashop.php` ~ligne 461) utilise `CURLOPT_CONNECTTIMEOUT => 120` et `CURLOPT_TIMEOUT => 120`.

**Problème** : quand Meili est lent/down, chaque worker PHP-FPM attend **120s** avant d'abandonner. Avec ~100 workers, 100 requêtes simultanées saturent le serveur → timeouts en cascade sur tout le site. C'est le mécanisme de l'incident.

**Fix** : Meili répond en <100ms en conditions normales. `3s` connect / `5s` total suffit largement.

**Affinage (idée de Johan, retenue)** : la fonction est **partagée** entre recherche (besoin fail-fast) et indexation (besoin de temps — chunks de 200 produits). Plutôt qu'un paramètre `$timeout` (un défaut se fait oublier silencieusement), **splitter en deux fonctions par usage** :

- `requestCurlSearch()` → 3s/5s → provider, trait listing, lectures admin légères
- `requestCurlIndex()` → 30-60s → `MeiliSearchIndexController` **et `controllers/front/cron.php`**

> ⚠️ Piège de nommage : nommer par **usage** (search/index), **pas** par emplacement (front/admin). `cron.php` est dans `controllers/front/` mais fait de l'**indexation** → il lui faut le timeout long.

**Dépendance** : le fallback (#4) est **inutilisable** sans cette réduction. À 120s, l'utilisateur attend 120s avant le fallback = autant ne rien faire.

---

## 2. Noindex pages filtrées

**Constat** : Googlebot crawle toutes les combinaisons de filtres. `robots.txt` (`Disallow /*?q=`) est déjà en place, mais les URLs déjà connues de Google continuent d'être crawlées.

**Correction du prompt initial** : le module n'utilise **pas** `q`. La recherche utilise `s` (`getProductSearchQuery()` : `Tools::getValue('s')`), les filtres sont dans `encodedFacets`. Condition correcte :

```php
$noindex = Tools::getValue('s', '')
        || Tools::getValue('encodedFacets', '')
        || (int) Tools::getValue('page', 1) > 1;
```

**Implémentation** : injecter `<meta name="robots" content="noindex, follow">` via `hookDisplayHeader()` (déjà point d'injection JS). Pas de template à toucher, pas de `addMeta()` (non standard PS 1.7).

> ⚠️ `hookDisplayHeader()` fait un `return` anticipé si la page n'est pas une listing page. Placer la logique noindex **avant** ce return, sinon les pages de recherche pure n'auront jamais le noindex.

---

## 3. N+1 SQL sur les pages listing — il y en a DEUX

Vérifié contre le core 1.7.8.11. Flux réel de présentation :

```
prepareMultipleProductsForTemplate(array $products)
└─ array_map(prepareProductForTemplate, $products)        ← 1 itération PAR produit
   └─ prepareProductForTemplate($rawProduct)
      ├─ ProductAssembler::assembleProduct($rawProduct)
      │  ├─ addMissingProductFields()       ← ① "SELECT p.*, ps.*, pl.*, sa.*" PAR PRODUIT, INCONDITIONNEL
      │  └─ Product::getProductProperties() ← ② calcule le prix → getPriceStatic() → SpecificPrice::getSpecificPrice()
      └─ ProductPresenter::present()         ← ③ ne recalcule RIEN, lit $product['price'] déjà calculé
```

| | Source | Coût | Le fix "préchargement SpecificPrice" le règle ? |
|---|--------|------|--------------------------------------------------|
| ① | `addMissingProductFields()` — jointure 4 tables par produit, **sans aucune garde** | **le plus gros** | ❌ non |
| ② | `getProductProperties` → `getPriceStatic` → `SpecificPrice` par produit | secondaire (~1-5ms × N) | ✅ partiellement |

**Points clés** :
- Le prix est calculé dans **`Product::getProductProperties()`** (appelé par l'assembler), **pas** dans le presenter. (Correction d'une analyse initiale erronée.)
- `addMissingProductFields()` re-requête en base des données (`p.*, ps.*, pl.*`, stock) **que Meili contient déjà** → gâchis (cf. #5).
- Le cache statique s'appelle `$_specificPriceCache` (pas `$_cache_specific_price`), clé `computeKey()` = `id_product-id_shop-id_currency-id_country-id_group-quantity-id_product_attribute-id_cart-id_customer-real_quantity`. **Aucune méthode de préchargement batch n'existe.** Préremplir à la main = risque de **prix faux silencieux**.

**Verdict** : le vrai levier n'est pas le cache SpecificPrice (②) mais **`addMissingProductFields` (①)**. Fix propre = override de `ProductAssembler` (classe overridable) avec une requête batch `WHERE id_product IN (...)`. Mais c'est un override de classe core = projet délibéré, à **profiler avant** (Xdebug/Blackfire sur un listing réel) pour chiffrer ① vs ②. **Lié à la décision #5** : si Meili ne renvoyait que les IDs, ① deviendrait justifié.

---

## 4. Fallback Meilisearch down

### Keystone : `requestCurl()` jette l'info de diagnostic

Fin de `requestCurl()` (~lignes 490-499) : `curl_errno`, `curl_error`, `curl_getinfo` (http_code) sont **calculés, rangés dans `$header`, puis jetés** — la fonction ne retourne que `json_decode($content)`.

C'est l'info dont dépend tout le fallback. À l'échec :

| Cas | retour actuel | distinguable ? |
|-----|---------------|----------------|
| Timeout / connexion refusée (down) | `null` | oui |
| HTTP 5xx avec body JSON d'erreur | `stdClass` sans `hits` | oui |
| **0 résultat légitime** | `stdClass` avec `hits=[]` | **à NE PAS confondre** |

> Le fallback ne se déclenche **que** sur panne (null / 5xx), **jamais** sur un 0 résultat légitime (sinon chaque vraie recherche sans résultat lancerait une recherche DB parasite).

**Fix fondateur (non-breaking)** : exposer le diagnostic.
```php
public $lastCurlInfo = [];          // propriété du module
// ... avant le return :
$this->lastCurlInfo = $header;      // errno, http_code, errmsg
return json_decode($content);
```

### Deux surfaces, deux fallbacks très différents

Confirmé : **le module ne hooke PAS `productSearchProvider`.** Mécanisme core : `getProductSearchProviderFromModules()` (hook) → sinon `getDefaultProductSearchProvider()` (natif).

**Surface A — pages listing (catégorie, fabricant, nouveaux, meilleures ventes) : fallback quasi gratuit.**
Comme pas de hook, ces pages sont rendues serveur par le **`DatabaseProductSearchProvider` natif** (vrais produits), masquées en `opacity:0` par `meilisearch_listing.js`, qui appelle `listing.php` (Meili) pour remplacer `#js-product-list`. Le `.catch()` JS **révèle déjà** le natif sur erreur réseau.
- **Trou** : si Meili est down, `searchInMeili()` retourne `[]` **sans exception** → `listing.php` renvoie `200 OK` + JSON vide → le `.then()` (pas le `.catch()`) écrase le natif par du vide → "0 produit".
- **Fix** : `listing.php` détecte l'échec (via `lastCurlInfo`) → renvoie `{"meilisearch_failed": true}` (ou HTTP 503) ; le JS, dans `.then()`, si `meilisearch_failed` → `meilisearchRevealListing()` **sans** `meilisearchUpdateProducts()`. Coût serveur du fallback = **zéro** (natif déjà rendu).

**Surface B — page de recherche dédiée (`meilisearch.php`) : fallback serveur nécessaire.**
Ici `getDefaultProductSearchProvider()` est surchargé → provider = **toujours Meili**, rien de natif dessous. Candidat fallback natif : `src/Adapter/Search/SearchProductSearchProvider.php`.
- **Pattern** : le provider devient un **décorateur** dans `runQuery()` — try Meili → si `lastRequestFailed`, déléguer à `new SearchProductSearchProvider($translator)` et retourner son `runQuery()`.
- **Pourquoi dans le provider** : l'échec n'est connu qu'**après** la requête ; le provider est le seul endroit qui voit le résultat, et il couvre les deux contrôleurs.
- **Caveat** : sur fallback, pas de `meilisearch_facets` → le template doit garder `{if $meilisearch_facets}` (dégradation acceptable). À vérifier aussi : le sort order `'meilisearch'` est-il digéré par le provider natif ?

### Le piège production : cascade DB

Si Meili est down et que **chaque** requête bascule sur la recherche DB native, MySQL (OVH managé, distant) ramasse toute la charge de recherche → on déplace la saturation. Pire : sans garde, chaque requête paie 5s de timeout **avant** de basculer.

**Solution : circuit breaker court** (APCu, pas `Configuration`/DB qui ajouterait une écriture SQL par requête). Au-delà de N échecs en T secondes, ouvrir 30s : on saute Meili entièrement → direct au natif (0 pénalité timeout) + on laisse Meili respirer. **Bonus** : couplé à un cache (cf. #6), le breaker ouvert peut servir le cache périmé = served-stale gracieux.

### Plan d'implémentation ordonné

| Étape | Fichier | Nature |
|-------|---------|--------|
| 0 | `meilisearchprestashop.php` | `requestCurlSearch()` 3/5s + exposer `$lastCurlInfo` (keystone) |
| 1 | `MeiliSearchProductSearchProvider` | `static $lastRequestFailed` (sur null/5xx, **pas** 0 légitime) |
| 2 | `MeiliSearchProductSearchProvider::runQuery()` | décorateur → `SearchProductSearchProvider` natif |
| 3 | `listing.php` + `meilisearch_listing.js` | flag `meilisearch_failed` → révéler natif sans écraser |
| 4 | `src/Service/CircuitBreaker` (APCu) | anti-cascade, ouvre 30s après N échecs |
| 5 | `search.tpl` | garder `{if $meilisearch_facets}` (vérif dégradation) |

---

## 5. 🔴 Le vrai débat : Meili `['*']` vs liste d'IDs

**Constat (vérifié)** : aujourd'hui la requête principale dans `searchInMeili()` fait :

```php
$baseData = [
    'q' => $search,
    'limit' => 9999,
    'attributesToRetrieve' => ['*'],   // ← TOUT le document, pas juste l'id
    ...
];
```

Meili retourne le **document complet** (id, name, price, quantity, features…), converti par `formatProducts($response->hits)`, et **ces arrays** partent dans `prepareProductForTemplate`.

**Conséquence — le `array_merge` du core écrase la DB.** Dans `ProductAssembler::addMissingProductFields()` :
```php
return array_merge($rows[0], $rawProduct);   // 2e argument (Meili) gagne sur collision de clé
```
Donc le `quantity` **frais** de `stock_available` est **écrasé** par le `quantity` **Meili**. Le stock affiché = stock Meili = périmé au dernier reindex près.

**Aujourd'hui = pire des deux mondes** :

| | Meili = IDs (modèle attendu) | Meili = `['*']` (code actuel) |
|---|---|---|
| `addMissingProductFields` (N+1 ①) | **nécessaire** | tourne quand même, mais **inutile** |
| stock affiché | frais (DB) | **périmé** (Meili écrase la DB) |
| payload Meili | minuscule | gros (tous champs × catalogue) |

> Note : le **prix**, lui, est recalculé frais par `getProductProperties` → `getPriceStatic` (la DB gagne sur le prix). Donc cacher le JSON Meili ne fige jamais le prix. C'est seulement le **stock** qui suit Meili.

**Les deux directions possibles (À TRANCHER AVEC ADAM)** :

- **Direction A — Meili source d'affichage** (`['*']`, statu quo) : rapide, mais stock périmé (au reindex près) et N+1 ① inutile. Acceptable **si** la fréquence de reindex rend le stock "assez frais" et qu'on assume.
- **Direction B — Meili = index de pertinence** (`attributesToRetrieve => ['id_product']`) : Meili ne renvoie que les IDs, PS réhydrate tout depuis la DB en **1 requête batch** (override `ProductAssembler`). Stock/prix **toujours frais**, payload Meili minuscule, N+1 ① transformé en 1 requête. Plus propre, plus de travail.

Cette décision **conditionne** le #3 (le N+1 n'a de sens à optimiser que selon la direction choisie).

---

## 6. Listing : AJAX (actuel) vs hook SSR + cache

**Choix actuel d'Adam = AJAX, à la demande explicite de Johan** (ne pas pénaliser le TTFB). **C'est correct vu la contrainte.**

**Le double-travail est réel mais c'est le prix de la contrainte AJAX** : PS n'a qu'un seul levier pour empêcher la recherche DB native de tourner sur une page catégorie — le hook `productSearchProvider`. Sans hook, `CategoryController` exécute **toujours** son provider natif. Donc :

| | Natif tourne ? | Meili dans le TTFB ? | SEO / no-JS | Conforme à la contrainte Johan |
|---|---|---|---|---|
| **Hook (SSR)** | non | **oui** ← pénalise | produits Meili dans le HTML | ❌ |
| **Pas de hook + AJAX** (actuel) | **oui** (double) | non | produits natifs dans le HTML | ✅ |

Le travail natif n'est pas du pur déchet : c'est le **baseline SEO + no-JS + fallback**.

**La 3e voie (proposée par Johan) : hook SSR + cache.** Résout les 3 problèmes d'un coup : pas de double-travail (hook tue le natif), pas de pénalité TTFB (cache sert la majorité), et le cache chaud **est** le fallback.

Deux garde-fous indispensables :
1. **Ne cacher que la page non filtrée** par (catégorie, tri, langue) — fort trafic/réemploi. Les combos filtrés (= explosion de clés, = le crawl Googlebot) vont en live + sont `noindex` (#2). On cache où ça se réutilise, pas où ça explose.
2. **Quoi cacher** : le **JSON Meili** (IDs + facetDistribution), pas le HTML rendu (invalidation prix/devise/groue client = enfer). Comme on ne met pas Meili à jour en live, la fraîcheur n'est pas un sujet supplémentaire — le seul TTL utile reflète un reindex.

> Décision dépend du **coût SQL réel d'une page catégorie native** sur domfront-1 (à mesurer). <100ms → AJAX actuel suffit, on bouche juste le trou de fallback. ~800ms (gros catalogue, facettes lourdes) → migrer vers hook SSR + cache.

---

## Questions ouvertes pour Adam

1. **#5 (structurant)** : on garde Meili en `['*']` (source d'affichage, stock périmé assumé) ou on passe Meili en index de pertinence (IDs only + réhydratation DB batch, stock frais) ?
2. **#1** : OK pour split `requestCurlSearch` (5s) / `requestCurlIndex` (30-60s) ?
3. **#6** : quel est le temps SQL d'une page catégorie native sur domfront-1 ? (décide AJAX vs hook SSR+cache)
4. **Infra** : APCu ou Redis dispo sur domfront-1 pour le cache + circuit breaker ?
5. **#4** : le provider natif `SearchProductSearchProvider` digère-t-il une `ProductSearchQuery` avec sort order `'meilisearch'` ? (à tester)

---

## Annexe — fichiers concernés

| Fichier | Rôle dans l'analyse |
|---------|---------------------|
| `meilisearchprestashop.php` | `requestCurl()` (#1, #4 keystone), `hookDisplayHeader()` (#2), pas de `hookProductSearchProvider` (#4, #6) |
| `src/Search/MeiliSearchProductSearchProvider.php` | `searchInMeili()` `['*']` (#5), `runQuery()` décorateur (#4), N+1 (#3) |
| `controllers/front/meilisearch.php` | surface B — page recherche (#4) |
| `controllers/front/listing.php` | surface A — pages listing (#4) |
| `controllers/front/cron.php` | indexation → timeout long (#1) |
| `src/Controller/Admin/MeiliSearchIndexController.php` | indexation chunks de 200 → timeout long (#1) |
| `views/js/front/meilisearch_listing.js` | hide/reveal natif, `.catch()` (#4 surface A) |
| **Core PS** `classes/ProductAssembler.php` | `addMissingProductFields()` + `array_merge` (#3, #5) |
| **Core PS** `classes/Product.php` | `getProductProperties()` → `getPriceStatic` (#3) |
| **Core PS** `src/Adapter/Presenter/Product/ProductLazyArray.php` | presenter ne recalcule pas le prix (#3) |
