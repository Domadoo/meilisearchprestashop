# BO-03 — Dashboard statistiques

**En tant qu'** administrateur
**Je veux** consulter les statistiques de recherche
**Afin de** comprendre le comportement des clients et identifier les requêtes sans résultats

## Critères d'acceptation

- [ ] Le dashboard est accessible depuis le menu BO
- [ ] Les KPIs sont affichés : CTR %, taux ajout panier %, taux conversion %, total recherches
- [ ] Le top 10 des requêtes les plus cherchées est affiché
- [ ] Le top 10 des produits les plus cliqués est affiché
- [ ] Le top 10 des requêtes sans résultats est affiché
- [ ] Le filtre par jour / mois / année fonctionne
- [ ] Le filtre "période précédente" fonctionne (j-1, mois-1, an-1)
- [ ] Le sélecteur de langue filtre les stats par langue

## Scénario PrestaFlow

`tests/prestaflow/Scenarios/BoStatsScenario.php`
