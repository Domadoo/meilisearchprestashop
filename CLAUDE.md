# CLAUDE.md — meilisearchprestashop

Module PrestaShop (1.7 / 8.0) remplaçant la barre de recherche native par Meilisearch.
Auteurs : Adam Doudeau, Johan Vivien.

## Stack

- PrestaShop 1.7–8.0 (PHP, Smarty, Symfony Console)
- Meilisearch (API REST via cURL)
- PSR-4 autoload via Composer

## Structure clé

```
meilisearchprestashop.php   # Classe principale du module, hooks
config/services.yml         # Enregistrement des services Symfony (commandes CLI)
controllers/front/
  cron.php                  # Indexation via HTTP (token requis)
  ajax.php                  # Endpoint AJAX front
  meilisearch.php           # Page de résultats de recherche
  listing.php               # Endpoint AJAX pour les pages listing (catégorie, fabricant, etc.)
src/
  Command/
    IndexProductsCommand.php  # CLI : php bin/console meilisearch:index-products
  Controller/Admin/
    MeiliSearchConfigurationController.php
    MeiliSearchStatsController.php
  Listing/
    MeilisearchListingControllerTrait.php  # Trait partagé : facettes, labels, disjunctive queries
  Search/
    MeiliSearchProductSearchProvider.php
classes/
  MeilisearchStatssearch.php  # ObjectModel pour les stats de recherche
views/
  js/front/meilisearch_searchbar.js
  js/front/meilisearch_facets.js    # Système de facettes (filtres, tags, AJAX)
  js/front/meilisearch_listing.js   # Init spécifique aux pages listing
  css/front/meilisearch_searchbar.css
  css/front/meilisearch_facets.css
  templates/hook/meilisearch_searchbar.tpl
  templates/front/
    search.tpl                          # Page résultats de recherche
    _partials/meilisearch_facets.tpl    # Bloc facettes (réutilisé sur toutes les pages listing)
```

## Configuration (table ps_configuration)

| Clé | Usage |
|-----|-------|
| `MEILISEARCHPRESTASHOP_URL` | URL de l'instance Meilisearch (avec `/` final) |
| `MEILISEARCHPRESTASHOP_KEY` | Clé API Meilisearch |
| `MEILISEARCHPRESTASHOP_PREFIX` | Préfixe des index (ex: `shop1_`) |
| `MEILISEARCHPRESTASHOP_TOKEN_CRON` | Token secret pour le cron HTTP |

## Indexation produits

La logique d'indexation est dupliquée en trois points :
- `controllers/front/cron.php` → `indexProductsAction()` (appel HTTP)
- `src/Command/IndexProductsCommand.php` → commande CLI Symfony
- `src/Controller/Admin/MeiliSearchIndexController.php` → `indexLanguage()` (indexation manuelle depuis l'admin)

Si on modifie la logique SQL, le typeMap ou les settings Meilisearch, **modifier les trois**.

Index créés : `{prefix}products_{iso_code}` (ex: `shop1_products_fr`)

### Champs personnalisés indexés

- `feature_values` : tableau à plat de chaînes `"{id_feature}-{id_feature_value}"` (ex: `["2-36", "2-60"]`), construit depuis `ps_feature_product`
- `ids_category` : tableau de tous les `id_category` du produit (ex: `[3, 5, 12]`), construit depuis `ps_category_product`. Permet de filtrer sur toutes les catégories d'un produit, pas seulement `id_category_default`

### Settings Meilisearch appliqués à chaque indexation

- sortable-attributes : `name`, `price`, `date_add`, `quantity`
- ranking-rules : `sort, words, typo, proximity, attribute, exactness`
- filterable-attributes : `id_manufacturer, out_of_stock, condition, ids_category, quantity, feature_values, visibility, available_for_order`

### Filtre catégorie

Le filtre catégorie utilise le champ `ids_category` (tableau). Meilisearch matche nativement `ids_category = 5` sur un tableau. Ne pas utiliser `id_category_default` pour filtrer (colonne SQL encore présente dans le document mais non filtrée).

## Pages listing gérées par Meilisearch

Les pages natives PS (catégorie, fabricant, nouveaux produits, meilleures ventes) utilisent Meilisearch via une approche **Hook + AJAX** (pas d'overrides de contrôleur).

### Architecture

```
Page listing (chargement initial)
├─ hookDisplayLeftColumn  →  requête Meilisearch (limit=0, facets=['*'])
│                            →  HTML facettes rendu côté serveur (sans flash)
├─ hookDisplayHeader      →  injection JS globals (ajax_url, context, facets_config)
│                            →  registration meilisearch_facets.js + meilisearch_listing.js
└─ Body natif PS masqué par opacity:0

DOMContentLoaded (meilisearch_listing.js)
└─ AJAX vers listing.php?id_category=5 → JSON → remplace #js-product-list

Clic filtre
└─ AJAX vers listing.php?id_category=5&encodedFacets=manu-3
   └─ Met à jour produits + comptes facettes
```

### Contrôleur listing.php

- Étend `ProductListingFrontController` (jamais de page HTML — AJAX uniquement)
- Appelle `parent::initContent()` pour initialiser le contexte Smarty, puis `doProductSearch()`
- `doProductSearch()` : redirige si non-XHR, sinon retourne JSON
- Utilise `MeiliSearchProductSearchProvider::$contextFilters` (static) pour injecter le filtre de contexte (catégorie/fabricant) avant la recherche
- Sauvegarde `$contextFilters` avant la recherche (la recherche les remet à `[]` après usage) pour les réutiliser dans `getDisjunctiveFacets()`
- L'ID de catégorie/fabricant est lu depuis `Tools::getValue()` (params URL), plus fiable que `$ctrl->category->id`

### Trait MeilisearchListingControllerTrait

Partagé entre `meilisearch.php` et `listing.php` (et `meilisearchprestashop.php` pour les hooks) :
- `getDisjunctiveFacets()` — requêtes disjunctives pour des compteurs facettes corrects quand des filtres sont actifs. Lit `MeiliSearchProductSearchProvider::$contextFilters` pour inclure le filtre de contexte dans les sous-requêtes.
- `buildFacetsJsConfig()` — construit la config JS des facettes (prefix, type, map)
- `getFacetLabels()` — labels traduits pour fabricants, catégories, feature values
- `slugify()` — utilitaire interne

### Globals JS injectés par hookDisplayHeader (pages listing)

| Variable | Contenu |
|----------|---------|
| `meilisearch_listing_ajax_url` | URL complète de `listing.php` avec `?id_category=X` ou `?page_type=...` |
| `meilisearch_listing_context` | `{type, id, param, ...}` — contexte de la page |
| `meilisearch_facets_config` | Config encodage des facettes (pour `meilisearch_facets.js`) |
| `meilisearch_encoded_facets` | Valeur courante de `encodedFacets` depuis l'URL |

### Facettes cachées par page

| Page | Facettes masquées |
|------|------------------|
| Catégorie | `ids_category` (+ toujours : `out_of_stock`, `visibility`, `quantity`, `available_for_order`) |
| Fabricant | `id_manufacturer` |
| Nouveaux / Meilleures ventes | aucune de plus |

## Hooks utilisés

- `displaySearch` : affiche la barre de recherche + charge JS/CSS (uniquement sur les pages avec la barre)
- `displayHeader` : traduction front + injection JS/CSS pour les pages listing (`category`, `manufacturer`, `new-products`, `best-sales`)
- `displayLeftColumn` : injection du bloc facettes Meilisearch sur les pages listing (rendu serveur)
- `actionPresentProduct` : tracking Meilisearch (cookie)
- `actionCartUpdateQuantityBefore` : tracking ajout panier
- `actionValidateOrder` : marque la recherche comme convertie

## MeiliSearchProductSearchProvider

- `$contextFilters` (static) : filtres de contexte non-utilisateur (ex: `['ids_category = 5']`). Injectés dans `buildFilterArray()` ET dans les sous-requêtes disjunctives. Remis à `[]` à la fin de `searchInMeili()`.
- `$lastFacetDistribution` (static) : distribution des facettes du dernier appel, lue par les contrôleurs pour construire la réponse JSON.

## Conventions

- Commits préfixés `[FEAT]` ou `[FIX]` selon la nature du changement
- Version dans `meilisearchprestashop.php` → `$this->version`
- Ne pas charger JS/CSS dans `hookDisplayHeader` si ce n'est pas nécessaire sur toutes les pages
- Toujours vérifier l'existence d'un élément DOM avant de le manipuler en JS
- Pour les pages listing : toujours lire l'ID (catégorie, fabricant) depuis `Tools::getValue()`, pas depuis `$ctrl->category->id` (peut être inaccessible selon la version PS)
- `listing.php` doit toujours appeler `parent::initContent()` avant tout rendu Smarty — sinon `assignContentVars()` n'est pas appelé et les templates échouent
