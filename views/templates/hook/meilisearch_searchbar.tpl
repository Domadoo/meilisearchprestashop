<form method="get" action="{$link->getPageLink('search')|escape:'html':'UTF-8'}" class="search-bar-wrapper">
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