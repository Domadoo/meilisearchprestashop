# FO-04 — Tri des produits

**En tant que** client
**Je veux** trier les produits par prix, nouveauté ou pertinence
**Afin de** trouver le produit le plus adapté à mes besoins

## Critères d'acceptation

- [ ] Le sélecteur de tri est visible sur les pages listing et résultats de recherche
- [ ] Changer le tri recharge les produits via AJAX (pas de rechargement de page)
- [ ] L'ordre sélectionné est reflété dans l'URL (`?order=price:asc`)
- [ ] Les filtres actifs (`encodedFacets`) sont conservés lors du changement de tri
- [ ] Le tri par prix croissant affiche le produit le moins cher en premier
- [ ] Le tri par nouveauté affiche les produits les plus récents en premier

## Scénario PrestaFlow

`tests/prestaflow/Scenarios/SortScenario.php`
