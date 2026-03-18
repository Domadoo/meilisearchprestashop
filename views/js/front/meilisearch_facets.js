/**
 * meilisearch_facets.js
 * Système de filtres dynamique — aucune valeur en dur.
 * S'appuie sur window.meilisearch_facets_config injecté par le controller PHP.
 */

function meilisearchToggle(btn) {
    btn.classList.toggle('open');
    btn.setAttribute('aria-expanded', btn.classList.contains('open'));
    btn.nextElementSibling.classList.toggle('open');
}

function meilisearchShowMore(btn) {
    const group = btn.closest('.meilisearch-facet-body, .meilisearch-facet-sub-group');
    group.querySelectorAll('.meilisearch-facet-item--hidden').forEach(el => {
        el.classList.remove('meilisearch-facet-item--hidden');
    });
    btn.style.display = 'none';
}

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

function meilisearchRemoveTag(inputId) {
    const cb = document.getElementById(inputId);
    if (cb) {
        cb.checked = false;
        meilisearchSyncTags();
        meilisearchApplyFilters();
    }
}

function meilisearchResetAll() {
    document.querySelectorAll('.meilisearch-facet-checkbox').forEach(cb => cb.checked = false);
    meilisearchSyncTags();
    meilisearchApplyFilters();
}

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
            case 'map':
                const mapped = conf.map[value] || value;
                parts.push(conf.prefix + '-' + mapped);
                break;
            case 'feature':
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
    });

    return parts.join('|');
}

function meilisearchApplyFilters() {
    const encoded  = meilisearchBuildEncodedFacets();
    const fetchUrl = new URL(window.location.href);
    const cleanUrl = new URL(window.location.href);

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

    fetch(fetchUrl.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            meilisearchUpdateProducts(data);
            history.pushState(null, '', cleanUrl.toString());
        } catch (e) {
            console.error('[Meilisearch] JSON parse error:', e, text.substring(0, 300));
        }
    })
    .catch(err => {
        console.error('[Meilisearch] Fetch error:', err);
    });
}

function meilisearchUpdateProducts(data) {
    const productList = document.querySelector('#js-product-list');
    if (productList && data.rendered_products) {
        productList.innerHTML = data.rendered_products;
    }

    const productListTop = document.querySelector('#js-product-list-top');
    if (productListTop && data.rendered_products_top) {
        productListTop.innerHTML = data.rendered_products_top;
    }

    const pagination = document.querySelector('.pagination');
    if (pagination && data.rendered_pagination) {
        pagination.innerHTML = data.rendered_pagination;
    }

    if (data.meilisearch_facets) {
        meilisearchUpdateFacetCounts(data.meilisearch_facets);
    }
}

function meilisearchUpdateFacetCounts(facets) {
    document.querySelectorAll('.meilisearch-facet-checkbox').forEach(cb => {
        const group = cb.dataset.group;
        const value = cb.dataset.value;
        let newCount = null;

        if (facets[group] && facets[group][value] !== undefined) {
            newCount = facets[group][value];
        }

        const label = document.querySelector('label[for="' + cb.id + '"]');
        if (!label) return;

        const countEl = label.querySelector('.meilisearch-facet-count');
        if (!countEl) return;

        const item = cb.closest('.meilisearch-facet-item');

        if (newCount !== null && newCount !== undefined) {
            countEl.textContent = newCount;
            if (item) item.style.opacity = newCount === 0 ? '0.4' : '1';
        } else {
            countEl.textContent = '0';
            if (item) item.style.opacity = '0.4';
        }
    });
}

/**
 * Au chargement, restitue l'état des filtres depuis l'URL
 * et met à jour les compteurs disjunctifs.
 */
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
                case 'map':
                    const mapped = conf.map[value] || value;
                    encodedValue = conf.prefix + '-' + mapped;
                    break;
                case 'feature':
                    const dashIdx   = value.indexOf('-');
                    const featureId = value.substring(0, dashIdx);
                    const valueId   = value.substring(dashIdx + 1);
                    const prefix    = conf.feature_map[featureId];
                    if (prefix) encodedValue = prefix + '-' + valueId;
                    break;
            }

            if (encodedValue === part) {
                cb.checked = true;
            }
        });
    });

    meilisearchSyncTags();
    meilisearchRefreshFacetCounts();
}

/**
 * Requête Ajax uniquement pour mettre à jour les compteurs.
 * Utilisée au chargement de page quand des filtres sont déjà actifs.
 */
function meilisearchRefreshFacetCounts() {
    const encoded = meilisearchBuildEncodedFacets();
    if (!encoded) return;

    const url = new URL(window.location.href);
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

document.addEventListener('DOMContentLoaded', function () {
    meilisearchRestoreFiltersFromUrl();

    document.querySelectorAll('.meilisearch-facet-checkbox').forEach(cb => {
        cb.addEventListener('change', function () {
            meilisearchSyncTags();
            meilisearchApplyFilters();
        });
    });
});