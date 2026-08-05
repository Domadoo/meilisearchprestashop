/**
 * 2007-2025 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 * @author    Doudeau Adam, Johan Vivien
 * @copyright 2007-2026 Domadoo
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

/**
 * meilisearch_listing.js
 * Gestion des pages de listing (catégorie, fabricant, nouveaux produits, meilleures ventes).
 * Remplace le listing produits natif PrestaShop par les résultats Meilisearch au chargement.
 * S'appuie sur les fonctions de meilisearch_facets.js (chargé avant ce fichier).
 */

document.addEventListener('DOMContentLoaded', function () {
    if (!window.meilisearch_listing_context || !window.meilisearch_listing_ajax_url) return;

    // Restaure les checkboxes depuis l'URL (sans déclencher de requête AJAX supplémentaire)
    meilisearchRestoreCheckboxesFromUrl();
    meilisearchSyncTags();

    // Attache les listeners sur les checkboxes des facettes (injectées via hookDisplayLeftColumn)
    document.querySelectorAll('.meilisearch-facet-checkbox').forEach(function (cb) {
        cb.addEventListener('change', function () {
            meilisearchSyncTags();
            meilisearchApplyFilters();
        });
    });

    // Charge les produits Meilisearch et remplace le listing natif
    meilisearchLoadListingProducts();
});

/**
 * Restaure les checkboxes depuis l'URL sans déclencher de requête AJAX.
 * Contrairement à meilisearchRestoreFiltersFromUrl(), ne fait pas de refresh des compteurs.
 */
function meilisearchRestoreCheckboxesFromUrl() {
    const encoded = new URL(window.location.href).searchParams.get('encodedFacets') || '';
    if (!encoded) return;

    const config = window.meilisearch_facets_config || {};
    const parts  = encoded.split('|').filter(Boolean);

    parts.forEach(function (part) {
        document.querySelectorAll('.meilisearch-facet-checkbox').forEach(function (cb) {
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
                    encodedValue = conf.prefix + '-' + (conf.map[value] || value);
                    break;
                case 'feature':
                    var dashIdx   = value.indexOf('-');
                    var featureId = value.substring(0, dashIdx);
                    var valueId   = value.substring(dashIdx + 1);
                    var prefix    = conf.feature_map[featureId];
                    if (prefix) encodedValue = prefix + '-' + valueId;
                    break;
            }

            if (encodedValue === part) {
                cb.checked = true;
            }
        });
    });
}

/**
 * Charge les produits via Meilisearch et remplace le listing natif PS.
 */
function meilisearchLoadListingProducts() {
    var baseUrl      = window.meilisearch_listing_ajax_url;
    var currentUrl   = new URL(window.location.href);
    var encodedFacets = currentUrl.searchParams.get('encodedFacets') || '';
    var page         = currentUrl.searchParams.get('page') || '1';
    var order        = currentUrl.searchParams.get('order') || '';

    var fetchUrl = new URL(baseUrl);
    fetchUrl.searchParams.set('page', page);
    if (encodedFacets) fetchUrl.searchParams.set('encodedFacets', encodedFacets);
    if (order) fetchUrl.searchParams.set('order', order);

    // Masque le listing natif pendant le chargement
    meilisearchHideNativeListing();
    meilisearchShowLoader();

    fetch(fetchUrl.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        meilisearchUpdateProducts(data);
        meilisearchRevealListing();
        if (data.meilisearch_facets) {
            meilisearchUpdateFacetCounts(data.meilisearch_facets);
        }
        meilisearchUpdateSortUrls();
    })
    .catch(function (err) {
        console.error('[Meilisearch] Erreur chargement listing:', err);
        meilisearchRevealListing();
    })
    .finally(function () {
        meilisearchHideLoader();
    });
}

function meilisearchHideNativeListing() {
    var ids = ['#js-product-list', '#js-product-list-top', '#js-product-list-bottom'];
    ids.forEach(function (sel) {
        var el = document.querySelector(sel);
        if (el) el.style.opacity = '0';
    });
}

function meilisearchRevealListing() {
    var ids = ['#js-product-list', '#js-product-list-top', '#js-product-list-bottom'];
    ids.forEach(function (sel) {
        var el = document.querySelector(sel);
        if (el) el.style.opacity = '';
    });
}
