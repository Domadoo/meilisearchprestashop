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

function toggleClearButton() {
    const input = document.getElementById('search-input');
    const clearIcon = document.getElementById('clear-icon');

    if (!input || !clearIcon) return;

    clearIcon.style.display = input.value ? 'block' : 'none';
}

// eslint-disable-next-line no-unused-vars -- appelée via onclick dans meilisearch_searchbar.tpl
function clearSearch() {
    const input = document.getElementById('search-input');
    if (!input) return;

    input.value = '';
    toggleClearButton();
    input.focus();
}

// Trigger on page load if there's a default value
document.addEventListener('DOMContentLoaded', toggleClearButton);


document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById("search-input");
    if (!searchInput) return;

    const placeholders = searchPlaceholder ? Object.values(searchPlaceholder) : [];
    let index = 0;

    function changePlaceholder() {
        if (placeholders.length > 0) {
            searchInput.setAttribute("placeholder", placeholders[index]);
            index = (index + 1) % placeholders.length;
        }
    }

    changePlaceholder();
    setInterval(changePlaceholder, 1500);
});


// ── Autocomplétion : recherches populaires + aperçu produits ──────────────────
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('search-input');
    const box = document.getElementById('meilisearch-autocomplete');
    if (!input || !box) return;

    const url = window.meilisearch_autocomplete_url || '';
    if (!url) return;

    const labels = window.meilisearch_ac_labels || {};
    const config = window.meilisearch_ac_config || {};
    const minChars = parseInt(config.minChars, 10) || 2;
    const debounceMs = parseInt(config.debounce, 10) || 200;

    let items = [];       // éléments navigables (requêtes + produits), dans l'ordre
    let activeIndex = -1;
    let debounceTimer = null;
    let controller = null; // AbortController de la requête en cours

    function closeBox() {
        box.hidden = true;
        box.innerHTML = '';
        items = [];
        activeIndex = -1;
    }

    function setActive(idx) {
        if (items[activeIndex]) items[activeIndex].classList.remove('meilisearch-ac-item--active');
        activeIndex = idx;
        if (items[activeIndex]) {
            items[activeIndex].classList.add('meilisearch-ac-item--active');
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    // Construit un libellé de requête en mettant en gras le préfixe tapé (sans innerHTML).
    function buildQueryLabel(query, typed) {
        const span = document.createElement('span');
        const lower = query.toLowerCase();
        const t = typed.toLowerCase();
        if (t && lower.indexOf(t) === 0) {
            const strong = document.createElement('strong');
            strong.textContent = query.substring(0, typed.length);
            span.appendChild(strong);
            span.appendChild(document.createTextNode(query.substring(typed.length)));
        } else {
            span.textContent = query;
        }
        return span;
    }

    function submitQuery(query) {
        input.value = query;
        if (input.form) {
            input.form.submit();
        }
    }

    function render(data, typed) {
        box.innerHTML = '';
        items = [];
        activeIndex = -1;

        const queries = Array.isArray(data.queries) ? data.queries : [];
        const products = Array.isArray(data.products) ? data.products : [];

        if (!queries.length && !products.length) {
            closeBox();
            return;
        }

        // Section recherches populaires
        if (queries.length) {
            if (labels.queries) {
                const title = document.createElement('div');
                title.className = 'meilisearch-ac-section-title';
                title.textContent = labels.queries;
                box.appendChild(title);
            }
            queries.forEach(function (query) {
                const item = document.createElement('div');
                item.className = 'meilisearch-ac-item meilisearch-ac-query';
                item.setAttribute('role', 'option');
                item.appendChild(buildQueryLabel(query, typed));
                item.addEventListener('mouseenter', function () { setActive(items.indexOf(item)); });
                item.addEventListener('mousedown', function (e) { e.preventDefault(); submitQuery(query); });
                box.appendChild(item);
                items.push(item);
            });
        }

        // Séparateur entre les deux sections
        if (queries.length && products.length) {
            const divider = document.createElement('div');
            divider.className = 'meilisearch-ac-divider';
            box.appendChild(divider);
        }

        // Section produits
        if (products.length) {
            if (labels.products) {
                const title = document.createElement('div');
                title.className = 'meilisearch-ac-section-title';
                title.textContent = labels.products;
                box.appendChild(title);
            }
            products.forEach(function (product) {
                const item = document.createElement('a');
                item.className = 'meilisearch-ac-item meilisearch-ac-product';
                item.setAttribute('role', 'option');
                item.href = product.url || '#';

                if (product.image) {
                    const img = document.createElement('img');
                    img.className = 'meilisearch-ac-product-img';
                    img.src = product.image;
                    img.alt = '';
                    img.loading = 'lazy';
                    item.appendChild(img);
                }

                const info = document.createElement('span');
                info.className = 'meilisearch-ac-product-info';

                const name = document.createElement('span');
                name.className = 'meilisearch-ac-product-name';
                name.textContent = product.name || '';
                info.appendChild(name);

                if (product.price) {
                    const price = document.createElement('span');
                    price.className = 'meilisearch-ac-product-price';
                    price.textContent = product.price;
                    info.appendChild(price);
                }

                item.appendChild(info);
                item.addEventListener('mouseenter', function () { setActive(items.indexOf(item)); });
                box.appendChild(item);
                items.push(item);
            });
        }

        box.hidden = false;
    }

    function fetchSuggestions(q) {
        if (controller) controller.abort();
        controller = new AbortController();

        let fetchUrl;
        try {
            fetchUrl = new URL(url, window.location.origin);
        } catch {
            return;
        }
        fetchUrl.searchParams.set('action', 'autocomplete');
        fetchUrl.searchParams.set('s', q);

        fetch(fetchUrl.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        })
            .then(function (r) { return r.json(); })
            .then(function (data) { render(data, q); })
            .catch(function () { /* abort ou réseau : on ignore */ });
    }

    input.addEventListener('input', function () {
        const q = input.value.trim();
        clearTimeout(debounceTimer);
        if (q.length < minChars) {
            closeBox();
            return;
        }
        debounceTimer = setTimeout(function () { fetchSuggestions(q); }, debounceMs);
    });

    input.addEventListener('keydown', function (e) {
        if (box.hidden || !items.length) return;
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                setActive((activeIndex + 1) % items.length);
                break;
            case 'ArrowUp':
                e.preventDefault();
                setActive((activeIndex - 1 + items.length) % items.length);
                break;
            case 'Enter':
                if (activeIndex >= 0 && items[activeIndex]) {
                    e.preventDefault();
                    const active = items[activeIndex];
                    if (active.classList.contains('meilisearch-ac-product')) {
                        window.location = active.href;
                    } else {
                        submitQuery(active.textContent);
                    }
                }
                break;
            case 'Escape':
                closeBox();
                break;
            default:
                break;
        }
    });

    // Fermeture au clic extérieur
    document.addEventListener('click', function (e) {
        if (!box.contains(e.target) && e.target !== input) {
            closeBox();
        }
    });
});