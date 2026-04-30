# BO-02 — Gestion des indexes

**En tant qu'** administrateur
**Je veux** gérer les indexes Meilisearch depuis le back-office
**Afin de** maintenir les données de recherche à jour

## Critères d'acceptation

- [ ] La page liste les indexes avec : UID, nombre de documents, date de création, date de mise à jour
- [ ] Le bouton "Reindex" par langue relance l'indexation complète
- [ ] Le bouton "Flush" vide l'index sans le supprimer
- [ ] Le bouton "Delete" supprime l'index après confirmation
- [ ] Les bulk actions (Reindex / Flush / Delete) s'appliquent à tous les indexes sélectionnés
- [ ] Une action réussie affiche un message de confirmation
- [ ] La page settings d'un index affiche les attributs filtrables et triables au format JSON

## Scénario PrestaFlow

`tests/prestaflow/Scenarios/BoIndexesScenario.php`
