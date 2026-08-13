{*
 * 2007-2025 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 *
 * @author    Doudeau Adam, Johan Vivien
 * @copyright 2007-2026 Domadoo
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *}
<form method="get" action="{$meilisearchUrl|escape:'html':'UTF-8'}" class="search-bar-wrapper">
  <div class="search-bar-container">
    <span class="search-icon">
      <i class="material-icons">search</i>
    </span>

    <input
      type="text"
      name="s"
      placeholder="{l s='Search' mod='meilisearchprestashop'}"
      value="{$search_string|escape:'html':'UTF-8'}"
      autocomplete="off"
      spellcheck="false"
    />

    <span class="clear-icon">
      <i class="material-icons">close</i>
    </span>

    <div class="meilisearch-autocomplete" role="listbox" hidden></div>
  </div>
</form>