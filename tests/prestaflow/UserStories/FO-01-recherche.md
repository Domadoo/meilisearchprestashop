# FO-01 — Recherche produit

**En tant que** client
**Je veux** saisir un terme dans la barre de recherche
**Afin de** trouver rapidement un produit

## Critères d'acceptation

- [ ] La barre de recherche est visible sur toutes les pages
- [ ] Le placeholder change dynamiquement (article / produit / catégorie)
- [ ] Le bouton "clear" (×) apparaît dès qu'on saisit du texte
- [ ] Cliquer sur × vide le champ
- [ ] La saisie déclenche une navigation vers la page résultats `/module/meilisearchprestashop/meilisearch?s=`
- [ ] La page résultats affiche les produits correspondants
- [ ] La page résultats affiche un compteur de résultats

## Scénario PrestaFlow

`tests/prestaflow/Scenarios/SearchBarScenario.php`
