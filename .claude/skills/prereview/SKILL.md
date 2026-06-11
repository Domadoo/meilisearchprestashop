---
name: prereview
description: AI pre-review d'une PR sur meilisearchprestashop. Lit le diff, analyse selon les conventions du module, poste un commentaire structuré sur la PR. Usage: /prereview <PR_NUMBER>. Trigger: /prereview
context: fork
---

# Pre-review meilisearchprestashop

Tu effectues une pre-review automatisée d'une PR sur le module `meilisearchprestashop`.

## Repo cible

`Domadoo/meilisearchprestashop`

## Input

Le numéro de PR est passé en argument : `$ARGS`

Si `$ARGS` est vide ou n'est pas un entier valide, affiche :
```
Usage : /prereview <PR_NUMBER>
Exemple : /prereview 42
```
et arrête-toi.

## Étapes à suivre dans l'ordre

### 1. Lire les guidelines du module

Lis le fichier `.claude/REVIEW_PROMPT.md` dans le repo courant — il contient le template de sortie exact et la liste des points à vérifier. C'est ta source de vérité pour cette review.

### 2. Récupérer les données de la PR

Exécute en parallèle :
```bash
gh pr diff "$PR_NUMBER" --repo Domadoo/meilisearchprestashop
gh pr view "$PR_NUMBER" --repo Domadoo/meilisearchprestashop --json number,title,author,body,baseRefName,headRefName,files
```

### 3. Analyser le diff

Concentre-toi **uniquement sur les fichiers modifiés par la PR**. N'explore pas le reste du repo, sauf si un fichier modifié importe ou référence explicitement un autre fichier — dans ce cas, lis uniquement ce fichier.

Vérifie chaque point du checklist présent dans `REVIEW_PROMPT.md` :
- AJAX lifecycle (`die()` / `ajaxRender()` + `exit`)
- Paths Smarty (`addTemplateDir` + nom de fichier seul)
- Token auth cron (`!==`, `empty()`, `exit`)
- `encodedFacets` → `sanitizeEncodedFacets()`
- Index UID → `isValidIndexUid()`
- Tripliquation de l'indexation (cron / Command / Controller)
- `declare(strict_types=1)` dans tout fichier PHP modifié
- `CURLOPT_SSL_VERIFYPEER` reste `true`
- Secrets admin : `type="password"`, `value=""`, `autocomplete="new-password"`
- CSRF token présent et validé
- `formatProducts()` avec `?? []`

### 4. Poster le commentaire

Remplace les variables du template `REVIEW_PROMPT.md` :
- `${REPO}` → `Domadoo/meilisearchprestashop`
- `${PR_NUMBER}` → numéro de la PR
- `${PR_TITLE}` → titre récupéré à l'étape 2
- `${PR_AUTHOR}` → auteur récupéré à l'étape 2

Puis poste le commentaire :
```bash
gh pr comment "$PR_NUMBER" --repo Domadoo/meilisearchprestashop --body "$(cat <<'COMMENT'
<!-- contenu du commentaire structuré -->
COMMENT
)"
```

Le commentaire doit commencer par `<!-- ai-prereview -->` (requis par le template).

### 5. Confirmer

Affiche en sortie :
```
Pre-review postée sur PR #<N> — <URL de la PR>
```
