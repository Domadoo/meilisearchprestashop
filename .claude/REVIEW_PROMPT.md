REPO: ${REPO}
PR NUMBER: ${PR_NUMBER}
PR TITLE: ${PR_TITLE}
PR AUTHOR: ${PR_AUTHOR}

You are performing an AI-assisted **pre-review** of a pull request on the `meilisearchprestashop` module.
You are NOT approving or rejecting this PR — this is an advisory pre-review only.

## Critical: read the repository guidelines first

The file `CLAUDE.md` at the repository root is the **source of truth** for all
conventions, architecture rules, hooks, indexation logic, facet system, security
requirements, and canonical examples.
**You must read `CLAUDE.md` before starting your review.** Every check you
perform should be grounded in the rules documented there.

## How to inspect the PR

**Your review must focus on the code changed in the PR — not the entire codebase.**
Start by fetching the diff and PR metadata:
- `gh pr diff $PR_NUMBER --repo $REPO` — the changed lines are your primary review scope
- `gh pr view $PR_NUMBER --repo $REPO` — PR description and metadata

Only read other files when directly referenced or imported by the changed code.
Focus on PHP files under `controllers/`, `src/`, `classes/`, and `meilisearchprestashop.php`.
Ignore `vendor/`, `*.lock`, binary files, and JS/CSS minified assets.

**Do not explore the full repository.** Use `Grep` or `Glob` only to verify
specific conventions or check related code referenced by the diff.

## Architecture areas covered by this module

- **Main class** (`meilisearchprestashop.php`): hooks registration, `requestCurl()`, `sanitizeEncodedFacets()`
- **Front controllers** (`controllers/front/`): `ajax.php` (product click tracking), `cron.php` (indexation), `meilisearch.php` (search results page), `listing.php` (category/manufacturer AJAX listing)
- **Admin controllers** (`src/Controller/Admin/`): Symfony-based config and index management
- **Indexation logic** (`src/Controller/Admin/MeiliSearchIndexController.php`, `src/Command/IndexProductsCommand.php`, `controllers/front/cron.php`): triplicated — all three must stay in sync
- **Search provider** (`src/Search/MeiliSearchProductSearchProvider.php`): Meilisearch query building, facet distribution
- **Facet system** (`src/Listing/MeilisearchListingControllerTrait.php`, `views/js/front/meilisearch_facets.js`): disjunctive queries, encodedFacets URL param
- **Stats tracking** (`classes/MeilisearchStatssearch.php`): ObjectModel for search session data

## Common pitfalls to flag

- **AJAX endpoints** (`ajax.php`, `listing.php`): must call `die()` / `$this->ajaxRender()` + `exit` — never return normally (PS lifecycle will try to render a full page)
- **Smarty template paths**: never use `module:` prefix or absolute paths in `setTemplate()` — use `addTemplateDir()` + filename only
- **Token auth** (`cron.php`): must use `!==` comparison, `empty()` check, and `exit` on reject
- **`encodedFacets` param**: always pass through `sanitizeEncodedFacets()` before use — regex whitelist `^[a-z0-9_|\-]*$`
- **Index UID** in admin actions: always validate with `isValidIndexUid()` regex `^[a-zA-Z0-9_\-]+$`
- **Indexation logic triplicated**: any change to SQL query, typeMap, or Meilisearch settings must be applied in all three locations (cron.php / IndexProductsCommand / MeiliSearchIndexController::indexLanguage)
- **`declare(strict_types=1)`**: required in every PHP file
- **`CURLOPT_SSL_VERIFYPEER`**: must remain `true`
- **Secrets in admin form**: API key and cron token fields must use `type="password"`, `value=""`, `autocomplete="new-password"` — never pre-fill with current value
- **CSRF**: admin form must include `<input type="hidden" name="_token" value="{{ csrf_token('save_meilisearch_config') }}">`; controller must validate with `isCsrfTokenValid()`
- **`formatProducts()`**: null-safe — must use `?? []` guards

## Output format

Post a **single comment** using `gh pr comment $PR_NUMBER --repo $REPO` with the following
structured format. Use Markdown with `<details>` for the longer sections.
Start the comment body with `<!-- ai-prereview -->` on the very first line.

```markdown
<!-- ai-prereview -->
> 🤖 **Claude AI Pre-Review** — Automated analysis. Does not replace human review.

## 📋 Summary of changes
[2–4 sentences: which area is modified, what the PR aims to fix or add]

## ⏱️ Estimated review time
[X–Y minutes — brief justification]

## 🎯 Scope
- **Area(s) touched:** hooks / front controllers / admin controllers / indexation / facets / JS / CSS
- **Security impact:** yes / no
- **Tests:** yes / no / manual only

<details>
<summary>🧱 Architecture & conventions compliance</summary>

[Verify against CLAUDE.md rules: AJAX lifecycle (die/exit), template paths,
token auth patterns, encodedFacets sanitization, indexation sync across 3 locations,
strict_types, SSL verify, secrets handling, CSRF]

</details>

<details>
<summary>💡 Improvement suggestions</summary>

[Actionable suggestions: null guards, edge cases, naming, missing validation, etc.]

</details>

## ✅ Pre-review checklist

**AJAX & controllers**
- [ ] AJAX endpoints terminate with `die()` / `ajaxRender()` + `exit` (no normal return)
- [ ] Non-XHR requests on listing endpoints redirect or return early
- [ ] `declare(strict_types=1)` present in all modified PHP files

**Security**
- [ ] `encodedFacets` passed through `sanitizeEncodedFacets()` before use
- [ ] Index UID validated with `isValidIndexUid()` in admin actions
- [ ] Cron token check uses `!==`, `empty()` guard, and `exit` on reject
- [ ] AJAX token check uses `!== '1'` (hardcoded front token)
- [ ] `CURLOPT_SSL_VERIFYPEER` remains `true`
- [ ] Admin form secrets use `type="password"`, `value=""`, `autocomplete="new-password"`
- [ ] CSRF token present in admin forms and validated in controller

**Indexation (if touched)**
- [ ] SQL query / typeMap / Meilisearch settings change applied in all 3 locations: `cron.php`, `IndexProductsCommand`, `MeiliSearchIndexController::indexLanguage()`
- [ ] `ids_category` built from `ps_category_product` (not `id_category_default`)
- [ ] `feature_values` uses `{id_feature}-{id_feature_value}` flat string format

**Smarty templates (if touched)**
- [ ] `addTemplateDir()` used instead of absolute path in `setTemplate()`
- [ ] No `module:` prefix in template names

**Facets (if touched)**
- [ ] Disjunctive queries pass `$savedContextFilters` (saved before search resets them)
- [ ] `$contextFilters` reset to `[]` after disjunctive queries
- [ ] Hidden facets list correct for each page type (category hides `ids_category`, manufacturer hides `id_manufacturer`)
```
