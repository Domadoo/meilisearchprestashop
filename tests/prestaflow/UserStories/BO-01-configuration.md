# BO-01 — Configuration du module

**En tant qu'** administrateur
**Je veux** configurer la connexion à l'instance Meilisearch
**Afin que** le module puisse interroger le bon serveur avec les bons credentials

## Critères d'acceptation

- [ ] La page de configuration est accessible depuis le menu BO
- [ ] Les champs URL, clé API, préfixe et token CRON sont présents
- [ ] Sauvegarder affiche un message de confirmation
- [ ] Une URL invalide affiche un message d'erreur
- [ ] Une clé API incorrecte affiche un message d'erreur
- [ ] Les valeurs sauvegardées sont bien rechargées à la réouverture de la page

## Scénario PrestaFlow

`tests/prestaflow/Scenarios/BoConfigurationScenario.php`
