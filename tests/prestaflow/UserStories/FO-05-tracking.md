# FO-05 — Tracking recherche → clic → panier → commande

**En tant que** administrateur
**Je veux** que chaque recherche client soit tracée jusqu'à la commande
**Afin de** mesurer le CTR, le taux d'ajout panier et le taux de conversion

## Critères d'acceptation

- [ ] Cliquer sur un produit depuis la page résultats envoie une requête AJAX au controller `ajax?action=productClick`
- [ ] La position du produit dans les résultats est correctement calculée (tient compte de la page)
- [ ] Le cookie `meilisearch_id` est positionné après le clic
- [ ] Naviguer vers la page produit conserve le paramètre `id_meilisearch_statssearch` dans l'URL
- [ ] L'ajout au panier met à jour le champ `id_cart` de la stat de recherche
- [ ] La validation d'une commande marque la stat `is_ordered = 1`

## Scénario PrestaFlow

`tests/prestaflow/Scenarios/TrackingScenario.php`
