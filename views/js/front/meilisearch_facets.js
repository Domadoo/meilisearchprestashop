/**
 * meilisearch_facets.js
 * Système de filtres dynamique — aucune valeur en dur.
 * S'appuie sur window.meilisearch_facets_config injecté par le controller PHP.
 */

// ── Loader ────────────────────────────────────────────────────────────────────

function meilisearchShowLoader() {
    const wrapper = document.querySelector('#content-wrapper');
    const facets  = document.querySelector('.meilisearch-facets');

    if (wrapper) {
        wrapper.classList.add('is-loading');

        // Crée l'overlay s'il n'existe pas encore
        if (!wrapper.querySelector('.meilisearch-loader-overlay')) {
            wrapper.style.position = 'relative';
            const overlay = document.createElement('div');
            overlay.className = 'meilisearch-loader-overlay';
            overlay.innerHTML = '<div class="meilisearch-loader-spinner"></div>';
            wrapper.appendChild(overlay);
        } else {
            wrapper.querySelector('.meilisearch-loader-overlay').classList.remove('hidden');
        }
    }

    if (facets) {
        facets.classList.add('is-loading');
    }
}

function meilisearchHideLoader() {
    const wrapper = document.querySelector('#content-wrapper');
    const facets  = document.querySelector('.meilisearch-facets');

    if (wrapper) {
        wrapper.classList.remove('is-loading');
        const overlay = wrapper.querySelector('.meilisearch-loader-overlay');
        if (overlay) overlay.classList.add('hidden');
    }

    if (facets) {
        facets.classList.remove('is-loading');
    }
}

// ── Accordion ─────────────────────────────────────────────────────────────────

// eslint-disable-next-line no-unused-vars -- appelée via onclick dans meilisearch_facets.tpl
function meilisearchToggle(btn) {
    btn.classList.toggle('open');
    btn.setAttribute('aria-expanded', btn.classList.contains('open'));
    btn.nextElementSibling.classList.toggle('open');
}

// eslint-disable-next-line no-unused-vars -- appelée via onclick dans meilisearch_facets.tpl
function meilisearchShowMore(btn) {
    const group = btn.closest('.meilisearch-facet-body, .meilisearch-facet-sub-group');
    group.querySelectorAll('.meilisearch-facet-item--hidden').forEach(el => {
        el.classList.remove('meilisearch-facet-item--hidden');
    });
    btn.style.display = 'none';
}

// ── Tags actifs ───────────────────────────────────────────────────────────────

function meilisearchSyncTags() {
    const container = document.getElementById('meilisearch-active-tags');
    if (!container) return;
    container.innerHTML = '';

    document.querySelectorAll('.meilisearch-facet-checkbox:checked').forEach(cb => {
        const tag = document.createElement('div');
        tag.className = 'meilisearch-active-tag';
        tag.innerHTML =
            '<span>' + cb.dataset.label + '</span>' +
            '<button type="button" onclick="meilisearchRemoveTag(\'' + cb.id + '\')">×</button>';
        container.appendChild(tag);
    });
}

// eslint-disable-next-line no-unused-vars -- appelée via onclick dans meilisearch_facets.tpl
function meilisearchRemoveTag(inputId) {
    const cb = document.getElementById(inputId);
    if (cb) {
        cb.checked = false;
        meilisearchSyncTags();
        meilisearchApplyFilters();
    }
}

// eslint-disable-next-line no-unused-vars -- appelée via onclick dans meilisearch_facets.tpl
function meilisearchResetAll() {
    document.querySelectorAll('.meilisearch-facet-checkbox').forEach(cb => cb.checked = false);
    meilisearchSyncTags();
    meilisearchApplyFilters();
}

// ── Encodage des filtres ──────────────────────────────────────────────────────

function meilisearchBuildEncodedFacets() {
    const config = window.meilisearch_facets_config || {};
    const parts  = [];

    document.querySelectorAll('.meilisearch-facet-checkbox:checked').forEach(cb => {
        const group = cb.dataset.group;
        const value = cb.dataset.value;
        const conf  = config[group];

        if (!conf) {
            console.warn('[Meilisearch] Groupe non configuré:', group);
            return;
        }

        switch (conf.type) {
            case 'direct':
                parts.push(conf.prefix + '-' + value);
                break;
            case 'map': {
                const mapped = conf.map[value] || value;
                parts.push(conf.prefix + '-' + mapped);
                break;
            }
            case 'feature': {
                const dashIdx   = value.indexOf('-');
                const featureId = value.substring(0, dashIdx);
                const valueId   = value.substring(dashIdx + 1);
                const prefix    = conf.feature_map[featureId];
                if (prefix) {
                    parts.push(prefix + '-' + valueId);
                } else {
                    console.warn('[Meilisearch] Feature ID non mappé:', featureId);
                }
                break;
            }
        }
    });

    return parts.join('|');
}

// ── Requête Ajax ──────────────────────────────────────────────────────────────

function meilisearchApplyFilters() {
    const encoded      = meilisearchBuildEncodedFacets();
    const baseAjaxUrl  = window.meilisearch_listing_ajax_url || window.location.href;
    const fetchUrl     = new URL(baseAjaxUrl);
    const cleanUrl     = new URL(window.location.href);

    fetchUrl.searchParams.set('ajax', '1');
    fetchUrl.searchParams.set('page', '1');
    if (encoded) {
        fetchUrl.searchParams.set('encodedFacets', encoded);
    } else {
        fetchUrl.searchParams.delete('encodedFacets');
    }

    cleanUrl.searchParams.delete('ajax');
    cleanUrl.searchParams.set('page', '1');
    if (encoded) {
        cleanUrl.searchParams.set('encodedFacets', encoded);
    } else {
        cleanUrl.searchParams.delete('encodedFacets');
    }

    meilisearchShowLoader();

    fetch(fetchUrl.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            meilisearchUpdateProducts(data);
            history.pushState(null, '', cleanUrl.toString());
            meilisearchUpdateSortUrls(); 
        } catch (e) {
            console.error('[Meilisearch] JSON parse error:', e, text.substring(0, 300));
        }
    })
    .catch(err => {
        console.error('[Meilisearch] Fetch error:', err);
    })
    .finally(() => {
        meilisearchHideLoader();
    });
}

function meilisearchApplyFiltersWithSort(order) {
    const encoded      = meilisearchBuildEncodedFacets();
    const baseAjaxUrl  = window.meilisearch_listing_ajax_url || window.location.href;
    const fetchUrl     = new URL(baseAjaxUrl);
    const cleanUrl     = new URL(window.location.href);

    fetchUrl.searchParams.set('ajax', '1');
    fetchUrl.searchParams.set('page', '1');
    fetchUrl.searchParams.set('order', order);
    if (encoded) {
        fetchUrl.searchParams.set('encodedFacets', encoded);
    } else {
        fetchUrl.searchParams.delete('encodedFacets');
    }

    cleanUrl.searchParams.delete('ajax');
    cleanUrl.searchParams.set('page', '1');
    cleanUrl.searchParams.set('order', order);
    if (encoded) {
        cleanUrl.searchParams.set('encodedFacets', encoded);
    } else {
        cleanUrl.searchParams.delete('encodedFacets');
    }

    meilisearchShowLoader();

    fetch(fetchUrl.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            meilisearchUpdateProducts(data);
            history.pushState(null, '', cleanUrl.toString());
            meilisearchUpdateSortUrls();
        } catch (e) {
            console.error('[Meilisearch] JSON parse error:', e, text.substring(0, 300));
        }
    })
    .catch(err => {
        console.error('[Meilisearch] Fetch error:', err);
    })
    .finally(() => {
        meilisearchHideLoader();
    });
}

// ── Mise à jour du DOM ────────────────────────────────────────────────────────

function meilisearchUpdateProducts(data) {
    // Mise à jour du listing produits
    const productList = document.querySelector('#js-product-list');
    if (productList && data.rendered_products) {
        productList.innerHTML = data.rendered_products;
    }

    // Mise à jour du compteur uniquement — pas tout le bloc top
    // pour éviter le décalage de mise en page
    if (data.products) {
        const totalCount = data.products.length;

        // Cherche le compteur dans le DOM — adapte le sélecteur à ton thème
        const counter = document.querySelector('#js-product-list-top .total-products p');
        if (counter) {
            // Reconstruit le texte avec le bon total
            // PrestaShop utilise data.pagination.total_items pour le vrai total
            const total = data.pagination && data.pagination.total_items
                ? data.pagination.total_items
                : totalCount;
            var i18n = window.meilisearch_i18n || {};
            counter.innerHTML = total > 1
                ? (i18n.products_many || 'There are %d products.').replace('%d', total)
                : (i18n.products_one || 'There is 1 product.');
        }
    }

    // Mise à jour de la pagination uniquement
    const paginationBottom = document.querySelector('#js-product-list-bottom .pagination');
    if (paginationBottom && data.rendered_products_bottom) {
        // Extrait uniquement la pagination du HTML rendu
        const tmp = document.createElement('div');
        tmp.innerHTML = data.rendered_products_bottom;
        const newPagination = tmp.querySelector('.pagination');
        if (newPagination) {
            paginationBottom.innerHTML = newPagination.innerHTML;
        }
    }

    if (data.meilisearch_facets) {
        meilisearchUpdateFacetCounts(data.meilisearch_facets);
    }
}


function meilisearchUpdateFacetCounts(facets) {
    document.querySelectorAll('.meilisearch-facet-checkbox').forEach(cb => {
        const group    = cb.dataset.group;
        const value    = cb.dataset.value;
        const label    = document.querySelector('label[for="' + cb.id + '"]');
        const item     = cb.closest('.meilisearch-facet-item');
        if (!label) return;

        const countEl  = label.querySelector('.meilisearch-facet-count');
        if (!countEl) return;

        let newCount = null;
        if (facets[group] && facets[group][value] !== undefined) {
            newCount = facets[group][value];
        }

        const count = newCount !== null ? newCount : 0;
        countEl.textContent = count;

        if (item) {
            item.style.opacity = count === 0 ? '0.4' : '1';
            if (count === 0 && !cb.checked) {
                cb.disabled = true;
                item.classList.add('meilisearch-facet-item--empty');
            } else {
                cb.disabled = false;
                item.classList.remove('meilisearch-facet-item--empty');
            }
        }
    });
}

// ── Restauration depuis l'URL ─────────────────────────────────────────────────

function meilisearchRestoreFiltersFromUrl() {
    const encoded = window.meilisearch_encoded_facets || '';
    if (!encoded) return;

    const config = window.meilisearch_facets_config || {};
    const parts  = encoded.split('|').filter(Boolean);

    parts.forEach(part => {
        document.querySelectorAll('.meilisearch-facet-checkbox').forEach(cb => {
            const group = cb.dataset.group;
            const value = cb.dataset.value;
            const conf  = config[group];
            if (!conf) return;

            let encodedValue = null;

            switch (conf.type) {
                case 'direct':
                    encodedValue = conf.prefix + '-' + value;
                    break;
                case 'map': {
                    const mapped = conf.map[value] || value;
                    encodedValue = conf.prefix + '-' + mapped;
                    break;
                }
                case 'feature': {
                    const dashIdx   = value.indexOf('-');
                    const featureId = value.substring(0, dashIdx);
                    const valueId   = value.substring(dashIdx + 1);
                    const prefix    = conf.feature_map[featureId];
                    if (prefix) encodedValue = prefix + '-' + valueId;
                    break;
                }
            }

            if (encodedValue === part) {
                cb.checked = true;
            }
        });
    });

    meilisearchSyncTags();
    meilisearchRefreshFacetCounts();
}

function meilisearchRefreshFacetCounts() {
    const encoded = meilisearchBuildEncodedFacets();
    if (!encoded) return;

    const url = new URL(window.meilisearch_listing_ajax_url || window.location.href);
    url.searchParams.set('ajax', '1');
    url.searchParams.set('page', '1');
    url.searchParams.set('encodedFacets', encoded);

    fetch(url.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.meilisearch_facets) {
                meilisearchUpdateFacetCounts(data.meilisearch_facets);
            }
        } catch (e) {
            console.error('[Meilisearch] Erreur refresh compteurs:', e);
        }
    })
    .catch(err => {
        console.error('[Meilisearch] Fetch error refresh:', err);
    });
}

function meilisearchUpdateSortUrls() {
    const currentUrl = new URL(window.location.href);
    const encodedFacets = currentUrl.searchParams.get('encodedFacets');

    document.querySelectorAll('.js-search-link').forEach(function(link) {
        const url = new URL(link.href);

        if (encodedFacets) {
            url.searchParams.set('encodedFacets', encodedFacets);
        } else {
            url.searchParams.delete('encodedFacets');
        }

        link.href = url.toString();
    });
}

// ── Interception pagination/tri — pages listing ───────────────────────────────
// Phase CAPTURE : s'exécute avant le handler jQuery du thème (bubble phase).
// Sans stopPropagation, le thème émet prestashop('updateFacets', listing_url) et
// PS core.js fait son propre pushState avec l'URL de listing.php → redirect accueil au refresh.
document.addEventListener('click', function(e) {
    if (!window.meilisearch_listing_context || !window.meilisearch_listing_ajax_url) return;

    const link = e.target.closest('.js-search-link');
    if (!link) return;

    e.preventDefault();
    e.stopPropagation(); // Coupe la chaîne : le handler jQuery du thème ne se déclenche pas

    const url           = new URL(link.href);
    const encodedFacets = new URL(window.location.href).searchParams.get('encodedFacets');
    const page          = url.searchParams.get('page') || '1';
    const order         = url.searchParams.get('order') || '';

    const fetchUrl = new URL(window.meilisearch_listing_ajax_url);
    fetchUrl.searchParams.set('page', page);
    if (order)         fetchUrl.searchParams.set('order', order);
    if (encodedFacets) fetchUrl.searchParams.set('encodedFacets', encodedFacets);
    else               fetchUrl.searchParams.delete('encodedFacets');

    const browserUrl = new URL(window.location.href);
    browserUrl.searchParams.set('page', page);
    if (order)         browserUrl.searchParams.set('order', order);
    else               browserUrl.searchParams.delete('order');
    if (encodedFacets) browserUrl.searchParams.set('encodedFacets', encodedFacets);
    else               browserUrl.searchParams.delete('encodedFacets');

    meilisearchShowLoader();
    fetch(fetchUrl.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        meilisearchUpdateProducts(data);
        history.pushState(null, '', browserUrl.toString());
        meilisearchUpdateSortUrls();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    })
    .catch(function(err) { console.error('[Meilisearch] Erreur pagination listing:', err); })
    .finally(function() { meilisearchHideLoader(); });

}, true); // true = capture phase

// ── Interception tri — pages recherche ───────────────────────────────────────
// Bubble phase : uniquement pour les pages hors listing (ex: page résultats de recherche).
// Propage encodedFacets dans l'URL du lien avant navigation.
document.addEventListener('click', function(e) {
    if (window.meilisearch_listing_context) return; // Géré par le handler capture ci-dessus

    const link = e.target.closest('.js-search-link');
    if (!link) return;

    e.preventDefault();

    const url           = new URL(link.href);
    const encodedFacets = new URL(window.location.href).searchParams.get('encodedFacets');

    if (encodedFacets) {
        url.searchParams.set('encodedFacets', encodedFacets);
    } else {
        url.searchParams.delete('encodedFacets');
    }

    window.location.href = url.toString();
});

// ── Init ──────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
    // Les pages listing ont leur propre init dans meilisearch_listing.js
    if (window.meilisearch_listing_context) return;

    meilisearchRestoreFiltersFromUrl();

    document.querySelectorAll('.meilisearch-facet-checkbox').forEach(cb => {
        cb.addEventListener('change', function () {
            meilisearchSyncTags();
            meilisearchApplyFilters();
        });
    });

    // ── Interception du tri ──────────────────────────────────────────
    document.addEventListener('change', function (e) {
        const select = e.target.closest('select[name="order"]');
        if (!select) return;

        e.preventDefault();
        e.stopPropagation();

        const sortValue = select.value; // ex: "price:asc"
        meilisearchApplyFiltersWithSort(sortValue);
    });
});