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