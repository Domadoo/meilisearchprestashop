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

/**
 * Construit la chaîne encodedFacets depuis les checkboxes cochées.
 * Utilise meilisearch_facets_config injecté par PHP pour savoir comment
 * encoder chaque groupe — aucun mapping en dur ici.
 */
function meilisearchBuildEncodedFacets() {
    const config = window.meilisearch_facets_config || {};
    const parts  = [];

    document.querySelectorAll('.meilisearch-facet-checkbox:checked').forEach(cb => {
        const group  = cb.dataset.group;
        const value  = cb.dataset.value;
        const conf   = config[group];

        if (!conf) {
            console.warn('[Meilisearch] Groupe non configuré:', group);
            return;
        }

        switch (conf.type) {
            case 'direct':
                // ex: manu-374, cond-new
                parts.push(conf.prefix + '-' + value);
                break;

            case 'map':
                // ex: available_for_order true → avail-stock
                const mapped = conf.map[value] || value;
                parts.push(conf.prefix + '-' + mapped);
                break;

            case 'feature':
                // ex: feature_values "7-45" → technology-45
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

            default:
                console.warn('[Meilisearch] Type de facette inconnu:', conf.type);
        }
    });

    return parts.join('|');
}

function meilisearchApplyFilters() {
    const encoded  = meilisearchBuildEncodedFacets();
    const fetchUrl = new URL(window.location.href);
    const cleanUrl = new URL(window.location.href);

    // URL de fetch : avec ajax=1 pour que PrestaShop renvoie du JSON
    fetchUrl.searchParams.set('ajax', '1');
    fetchUrl.searchParams.set('page', '1');
    if (encoded) {
        fetchUrl.searchParams.set('encodedFacets', encoded);
    } else {
        fetchUrl.searchParams.delete('encodedFacets');
    }

    // URL propre pour le navigateur : sans ajax=1
    cleanUrl.searchParams.delete('ajax');
    cleanUrl.searchParams.set('page', '1');
    if (encoded) {
        cleanUrl.searchParams.set('encodedFacets', encoded);
    } else {
        cleanUrl.searchParams.delete('encodedFacets');
    }

    console.log('[Meilisearch] Fetching:', fetchUrl.toString());

    fetch(fetchUrl.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            console.log('[Meilisearch] Response keys:', Object.keys(data));
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
    console.log('[Meilisearch] meilisearch_facets:', data.meilisearch_facets);

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


/**
 * Met à jour les compteurs affichés dans les checkboxes.
 * Ne touche pas aux cases cochées — seulement aux .meilisearch-facet-count.
 */
function meilisearchUpdateFacetCounts(facets) {
    document.querySelectorAll('.meilisearch-facet-checkbox').forEach(cb => {
        const group = cb.dataset.group;
        const value = cb.dataset.value;

        // Trouve le compteur dans les facettes reçues
        let newCount = null;

        if (facets[group] && facets[group][value] !== undefined) {
            newCount = facets[group][value];
        }

        // Met à jour le badge .meilisearch-facet-count dans le label associé
        const label = document.querySelector('label[for="' + cb.id + '"]');
        if (label) {
            const countEl = label.querySelector('.meilisearch-facet-count');
            if (countEl) {
                if (newCount !== null && newCount !== undefined) {
                    countEl.textContent = newCount;
                    cb.closest('.meilisearch-facet-item').style.opacity = '1';
                } else {
                    // Filtre sans résultat → grisé mais toujours visible
                    countEl.textContent = '0';
                    cb.closest('.meilisearch-facet-item').style.opacity = '0.4';
                }
            }
        }
    });
}


document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.meilisearch-facet-checkbox').forEach(cb => {
        cb.addEventListener('change', function () {
            meilisearchSyncTags();
            meilisearchApplyFilters();
        });
    });
});