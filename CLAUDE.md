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
src/
  Command/
    IndexProductsCommand.php  # CLI : php bin/console meilisearch:index-products
  Controller/Admin/
    MeiliSearchConfigurationController.php
    MeiliSearchStatsController.php
  Search/
    MeiliSearchProductSearchProvider.php
classes/
  MeilisearchStatssearch.php  # ObjectModel pour les stats de recherche
views/
  js/front/meilisearch_searchbar.js
  css/front/meilisearch_searchbar.css
  templates/hook/meilisearch_searchbar.tpl
```

## Configuration (table ps_configuration)

| Clé | Usage |
|-----|-------|
| `MEILISEARCHPRESTASHOP_URL` | URL de l'instance Meilisearch (avec `/` final) |
| `MEILISEARCHPRESTASHOP_KEY` | Clé API Meilisearch |
| `MEILISEARCHPRESTASHOP_PREFIX` | Préfixe des index (ex: `shop1_`) |
| `MEILISEARCHPRESTASHOP_TOKEN_CRON` | Token secret pour le cron HTTP |

## Indexation produits

La logique d'indexation est dupliquée en deux points :
- `controllers/front/cron.php` → `indexProductsAction()` (appel HTTP)
- `src/Command/IndexProductsCommand.php` → commande CLI Symfony

Si on modifie la logique SQL, le typeMap ou les settings Meilisearch, **modifier les deux**.

Index créés : `{prefix}products_{iso_code}` (ex: `shop1_products_fr`)

Settings Meilisearch appliqués à chaque indexation :
- sortable-attributes : `name`, `price`
- ranking-rules : `sort, words, typo, proximity, attribute, exactness`
- filterable-attributes : `id_manufacturer, out_of_stock, condition, id_category_default, quantity, feature_values, visibility, available_for_order`

## Hooks utilisés

- `displaySearch` : affiche la barre de recherche + charge JS/CSS (uniquement sur les pages avec la barre)
- `displayHeader` : uniquement pour la traduction front (`trans()`)
- `actionPresentProduct` : tracking Meilisearch (cookie)
- `actionCartUpdateQuantityBefore` : tracking ajout panier
- `actionValidateOrder` : marque la recherche comme convertie

## Conventions

- Commits préfixés `[FEAT]` ou `[FIX]` selon la nature du changement
- Version dans `meilisearchprestashop.php` → `$this->version`
- Ne pas charger JS/CSS dans `hookDisplayHeader` si ce n'est pas nécessaire sur toutes les pages
- Toujours vérifier l'existence d'un élément DOM avant de le manipuler en JS
