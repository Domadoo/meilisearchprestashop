{assign var=hidden_facets value=['out_of_stock', 'visibility', 'quantity']}

<div class="meilisearch-facets">

  <div class="meilisearch-facets-toolbar">
    <span class="meilisearch-facets-title">{l s='Filtres' mod='meilisearchprestashop'}</span>
    <button class="meilisearch-btn-reset" type="button" onclick="meilisearchResetAll()">
      {l s='Tout effacer' mod='meilisearchprestashop'}
    </button>
  </div>

  <div class="meilisearch-active-tags" id="meilisearch-active-tags"></div>

  {foreach from=$meilisearch_facets key=group_key item=group_values}
    {if $group_key|in_array:$hidden_facets}{continue}{/if}

    {assign var=group_label value=$group_key}
    {if $group_key == 'condition'}{assign var=group_label value='État'}
    {elseif $group_key == 'available_for_order'}{assign var=group_label value='Disponibilité'}
    {elseif $group_key == 'id_manufacturer'}{assign var=group_label value='Marque'}
    {elseif $group_key == 'feature_values'}{assign var=group_label value='Caractéristiques'}
    {/if}

    {assign var=is_open value=false}
    {if $group_key|in_array:$open_facets}{assign var=is_open value=true}{/if}

    <div class="meilisearch-facet-group" data-facet="{$group_key|escape:'html':'UTF-8'}">

      <button class="meilisearch-facet-toggle{if $is_open} open{/if}" type="button"
              aria-expanded="{if $is_open}true{else}false{/if}"
              onclick="meilisearchToggle(this)">
        <span>{$group_label}</span>
        <span class="meilisearch-facet-chevron">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
            <path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span>
      </button>

      <div class="meilisearch-facet-body{if $is_open} open{/if}">

        {if $group_key == 'feature_values'}

          {foreach from=$meilisearch_grouped_features key=feature_id item=feature_data}
            <div class="meilisearch-facet-sub-group">
              <p class="meilisearch-facet-sub-label">{$feature_data.label}</p>
              {assign var=i value=0}
              {foreach from=$feature_data.values key=val item=count}
                {assign var=i value=$i+1}
                {assign var=input_id value="meilisearch-facet-`$val`"}
                {assign var=val_label value=$val}
                {if isset($meilisearch_facet_labels['feature_values'][$val])}
                  {assign var=val_label value=$meilisearch_facet_labels['feature_values'][$val]}
                {/if}
                <div class="meilisearch-facet-item{if $i > 8} meilisearch-facet-item--hidden{/if}{if $count == 0} meilisearch-facet-item--empty{/if}">
                  <input type="checkbox" id="{$input_id}" class="meilisearch-facet-checkbox"
                         name="facets[{$group_key}][]"
                         value="{$val|escape:'html':'UTF-8'}"
                         data-group="{$group_key}"
                         data-label="{$val_label|escape:'html':'UTF-8'}"
                         data-value="{$val|escape:'html':'UTF-8'}"
                         {if $count == 0}disabled{/if}>
                  <label for="{$input_id}">
                    <span class="meilisearch-facet-name">{$val_label}</span>
                    <span class="meilisearch-facet-count">{$count}</span>
                  </label>
                </div>
              {/foreach}
              {if $i > 8}
                <button type="button" class="meilisearch-btn-show-more" onclick="meilisearchShowMore(this)">
                  + {$i - 8} {l s='de plus' mod='meilisearchprestashop'}
                </button>
              {/if}
            </div>
          {/foreach}

        {else}

          {assign var=i value=0}
          {foreach from=$group_values key=val item=count}
            {assign var=i value=$i+1}
            {assign var=input_id value="meilisearch-facet-`$group_key`-`$val`"}

            {assign var=val_label value=$val}
            {if $group_key == 'condition'}
              {if $val == 'new'}{assign var=val_label value='Neuf'}
              {elseif $val == 'refurbished'}{assign var=val_label value='Reconditionné'}
              {elseif $val == 'used'}{assign var=val_label value='Occasion'}{/if}
            {elseif $group_key == 'available_for_order'}
              {if $val == 'true' || $val == '1'}{assign var=val_label value='En stock'}
              {else}{assign var=val_label value='Sur commande'}{/if}
            {elseif isset($meilisearch_facet_labels[$group_key][$val])}
              {assign var=val_label value=$meilisearch_facet_labels[$group_key][$val]}
            {/if}

            <div class="meilisearch-facet-item{if $i > 5} meilisearch-facet-item--hidden{/if}{if $count == 0} meilisearch-facet-item--empty{/if}">
              <input type="checkbox" id="{$input_id}" class="meilisearch-facet-checkbox"
                     name="facets[{$group_key}][]"
                     value="{$val|escape:'html':'UTF-8'}"
                     data-group="{$group_key}"
                     data-label="{$val_label|escape:'html':'UTF-8'}"
                     data-value="{$val|escape:'html':'UTF-8'}"
                     {if $count == 0}disabled{/if}>
              <label for="{$input_id}">
                <span class="meilisearch-facet-name">{$val_label}</span>
                <span class="meilisearch-facet-count">{$count}</span>
              </label>
            </div>
          {/foreach}

          {if $i > 5}
            <button type="button" class="meilisearch-btn-show-more" onclick="meilisearchShowMore(this)">
              + {$i - 5} {l s='de plus' mod='meilisearchprestashop'}
            </button>
          {/if}

        {/if}
      </div>
    </div>
  {/foreach}

</div>