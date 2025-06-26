<form method="get" action="{$link->getPageLink('search')|escape:'html':'UTF-8'}" class="search-bar-wrapper" style="width: 100%; padding: 1rem;">
  <input type="hidden" name="controller" value="search">
  <div class="search-bar-container">
    <span class="search-icon">
      <i class="material-icons">search</i>
    </span>

    <input
      type="text"
      name="s"
      id="search-input"
      placeholder="Search"
      oninput="toggleClearButton()"
      value="{$search_string|default:''}"
    />

    <span class="clear-icon" id="clear-icon" onclick="clearSearch()">
      <i class="material-icons">close</i>
    </span>
  </div>
</form>

<style>
  .search-bar-container {
    position: relative;
    width: 100%;
    max-width: 100%;
  }

  .search-bar-container input {
    width: 100%;
    padding: 10px 40px 10px 40px;
    border: 1px solid #007bff;
    border-radius: 6px;
    font-size: 16px;
    box-sizing: border-box;
  }

  .search-icon,
  .clear-icon {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    color: #777;
    cursor: pointer;
  }

  .search-icon {
    left: 10px;
  }

  .clear-icon {
    right: 10px;
    display: none;
  }

  .search-bar-container input:focus {
    outline: none;
    border-color: #0056b3;
  }
</style>

<script>
  function toggleClearButton() {
    const input = document.getElementById('search-input');
    const clearIcon = document.getElementById('clear-icon');

    clearIcon.style.display = input.value ? 'block' : 'none';
  }

  function clearSearch() {
    const input = document.getElementById('search-input');
    input.value = '';
    toggleClearButton();
    input.focus();
  }

  // Trigger on page load if there's a default value
  document.addEventListener('DOMContentLoaded', toggleClearButton);
</script>
