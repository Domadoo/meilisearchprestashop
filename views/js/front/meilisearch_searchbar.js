function toggleClearButton() {
    const input = document.getElementById('search-input');
    const clearIcon = document.getElementById('clear-icon');

    if (!input || !clearIcon) return;

    clearIcon.style.display = input.value ? 'block' : 'none';
}

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