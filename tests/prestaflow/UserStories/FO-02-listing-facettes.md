# FO-02 — Filtrage par facettes sur page listing

**En tant que** client
**Je veux** filtrer les produits par marque, catégorie ou caractéristique sur une page catégorie
**Afin de** affiner ma recherche sans rechargement de page

## Critères d'acceptation

- [ ] La sidebar des facettes est visible sur les pages catégorie / fabricant / nouveaux / meilleures ventes
- [ ] Les groupes de facettes sont repliables (accordion)
- [ ] Cocher une facette déclenche une requête AJAX (pas de rechargement)
- [ ] Le compteur de produits se met à jour après filtrage
- [ ] La liste de produits se met à jour sans rechargement de page
- [ ] Les compteurs par facette se mettent à jour (facettes disjunctives)
- [ ] Les facettes à 0 résultat apparaissent en grisé et désactivées
- [ ] Un tag actif apparaît pour chaque filtre sélectionné
- [ ] Cliquer × sur un tag désactive le filtre correspondant
- [ ] Le bouton "Tout réinitialiser" supprime tous les filtres actifs
- [ ] Les filtres actifs sont encodés dans l'URL (`?encodedFacets=`)
- [ ] Recharger la page avec `?encodedFacets=` restaure les checkboxes

## Scénario PrestaFlow

`tests/prestaflow/Scenarios/FacetsScenario.php`
