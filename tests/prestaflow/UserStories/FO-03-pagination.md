# FO-03 — Pagination

**En tant que** client
**Je veux** naviguer entre les pages de résultats
**Afin de** consulter tous les produits d'une catégorie ou d'une recherche

## Critères d'acceptation

- [ ] Les liens de pagination sont visibles quand il y a plus de 48 produits
- [ ] Cliquer sur "Page 2" charge les produits suivants sans redirection vers l'accueil
- [ ] L'URL reste sur la page catégorie après navigation (pas de redirection vers `/module/meilisearchprestashop/listing`)
- [ ] Le paramètre `?page=2` est présent dans l'URL du navigateur
- [ ] Les filtres actifs (`encodedFacets`) sont conservés lors du changement de page
- [ ] La pagination fonctionne depuis une page de résultats de recherche
- [ ] Recharger la page 2 affiche bien la page 2 (pas de redirect accueil)

## Régression connue

Bug résolu le 29/04/2026 : le handler jQuery du thème émettait `updateFacets` avant
le handler capture-phase de meilisearch, causant un `pushState` avec l'URL
`listing.php` → redirect accueil au refresh.

## Scénario PrestaFlow

`tests/prestaflow/Scenarios/PaginationScenario.php`
