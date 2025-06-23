{extends file='page.tpl'}

{block name='page_content'}
    {if $resultat->totalHits == 0}
        <div class="msp-search-empty">
            <p>{l s='No result found for:' mod='meilisearch_prestashop'} "<strong>{$resultat->query}</strong>"</p>
        </div>
    {else}
        <div class="msp-search-top container">
            <h2 class="msp-title">{l s='results for:' mod='meilisearch_prestashop'} "<strong>{$resultat->query}</strong>"
            </h2>
        </div>

        <div class="msp-product-grid container">
            <div class="msp-product-list">
                {foreach from=$resultat->hits item=product}
                    {assign var='productUrl' value="/index.php?id_product=`$product->id_product`&controller=product"}

                    <div class="msp-product-card">
                        <a href="{$productUrl}" class="msp-product-image">
                            <img src="{$product->image_url}" alt="{$product->name|escape:'html'}" loading="lazy">
                        </a>

                        <div class="msp-product-info">
                            <h3 class="msp-product-title">
                                <a href="{$productUrl}">{$product->name|escape:'html'}</a>
                            </h3>

                            {if isset($product->rate) && $product->rate > 0}
                                <div class="skeepers_product__stars msp-product-rating" data-product-id="{$product->id_product}">
                                    <div class="stars">
                                        {assign var=rating value=$product->rate}
                                        {assign var=fullStars value=($rating|floor)}
                                        {assign var=partial value=$rating - $fullStars}
                                        {section name=i loop=5}
                                            {if $smarty.section.i.index < $fullStars}
                                                {assign var=width value=100}
                                            {elseif $smarty.section.i.index == $fullStars && $partial > 0}
                                                {assign var=width value=($partial*100)|intval}
                                            {else}
                                                {assign var=width value=0}
                                            {/if}
                                            <span class="stars__item"
                                                style="background: linear-gradient(to right, rgba(250,137,0,1) 0%, rgba(250,137,0,1) {$width}%, rgba(250,137,0,0.3) {$width}%, rgba(250,137,0,0.3) 100%)">
                                                ★
                                            </span>
                                        {/section}
                                    </div>
                                    <div class="stars__rating">
                                        <span class="rate-aggregate">{$rating}</span>
                                        <span class="rate-aggregate__separator">/</span>
                                        <span class="rate-aggregate__max">5 - </span>
                                        <span class="rate-total">{$product->rate_count|default:'—'}</span>
                                        <span>avis</span>
                                    </div>
                                </div>
                            {/if}

                            <div class="msp-product-price">
                                {$product->price|number_format:2:',':' '}&nbsp;€
                            </div>


                        </div>
                    </div>
                {/foreach}
            </div>
        </div>
    
        {assign var="currentPage" value=$resultat->page}
        {assign var="hitsPerPage" value=$resultat->hitsPerPage}
        {assign var="totalHits" value=$resultat->totalHits}

        {assign var="totalPages" value=$resultat->totalPages}
        {assign var="url" value=$url}
    
        {assign var="startIndex" value=($currentPage - 1) * $hitsPerPage + 1}
        {assign var="endIndex" value=$currentPage * $hitsPerPage}
        {if $endIndex > $totalHits}
            {assign var="endIndex" value=$totalHits}
        {/if}
    
        <div class="msp-pagination-wrapper">
            <div class="msp-pagination-info">
                Affichage {$startIndex} - {$endIndex} de {$totalHits} article(s)
            </div>
            {if $resultat->totalPages > 1}
                <nav class="msp-pagination">
                    <ul class="msp-pagination-list">
                        {if $currentPage > 1}
                            <li class="msp-pagination-item">
                                <a href="{$url}&page={$currentPage-1}">‹ Précédent</a>
                            </li>
                        {/if}
        
                        <li class="msp-pagination-item{if $currentPage == 1} active{/if}">
                            <a href="{$url}&page=1">1</a>
                        </li>
        
                        {if $currentPage > 3}
                            <li class="msp-pagination-item msp-pagination-dots">…</li>
                        {/if}
        
                        {if $currentPage - 1 > 1}
                            <li class="msp-pagination-item">
                                <a href="{$url}&page={$currentPage - 1}">{$currentPage - 1}</a>
                            </li>
                        {/if}
        
                        {if $currentPage != 1 && $currentPage != $totalPages}
                            <li class="msp-pagination-item active">
                                <a href="{$url}&page={$currentPage}">{$currentPage}</a>
                            </li>
                        {/if}
        
                        {if $currentPage + 1 < $totalPages}
                            <li class="msp-pagination-item">
                                <a href="{$url}&page={$currentPage + 1}">{$currentPage + 1}</a>
                            </li>
                        {/if}
        
                        {if $currentPage < $totalPages - 2}
                            <li class="msp-pagination-item msp-pagination-dots">…</li>
                        {/if}
        
                        {if $totalPages > 1}
                            <li class="msp-pagination-item{if $currentPage == $totalPages} active{/if}">
                                <a href="{$url}&page={$totalPages}">{$totalPages}</a>
                            </li>
                        {/if}
        
                        {if $currentPage < $totalPages}
                            <li class="msp-pagination-item">
                                <a href="{$url}&page={$currentPage+1}">Suivant ›</a>
                            </li>
                        {/if}
                    </ul>
                </nav>
            {/if}
        </div>
        
    {/if}
{/block}