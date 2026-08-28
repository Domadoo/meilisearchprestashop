<?php

/**
 * 2007-2026 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 *
 * @author    Doudeau Adam, Johan Vivien
 * @copyright 2007-2026 Domadoo
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\MeiliSearch\Listing\MeilisearchListingControllerTrait;
use PrestaShop\Module\MeiliSearch\Search\MeiliSearchProductSearchProvider;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;

/**
 * AJAX-only endpoint for Meilisearch on listing pages (category, manufacturer, new-products, best-sales).
 * Never serves a full HTML page — always returns JSON.
 */
class MeilisearchprestashopListingModuleFrontController extends ProductListingFrontController
{
    use MeilisearchListingControllerTrait;

    /** @var array Facets à masquer dans la réponse (ex: ids_category sur page catégorie) */
    private $hideFacets = [];

    public function initContent()
    {
        // Must call parent to set up Smarty context before any template rendering
        parent::initContent();
        $this->doProductSearch();
    }

    protected function doProductSearch($template = '', $params = [], $locale = null)
    {
        $isXhr = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!$isXhr) {
            Tools::redirect($this->context->link->getPageLink('index'));

            return;
        }

        $idCategory = (int) Tools::getValue('id_category', 0);
        $idManufacturer = (int) Tools::getValue('id_manufacturer', 0);

        if ($idCategory) {
            MeiliSearchProductSearchProvider::$contextFilters = ['ids_category = ' . $idCategory];
            $this->hideFacets = ['ids_category'];
        } elseif ($idManufacturer) {
            MeiliSearchProductSearchProvider::$contextFilters = ['id_manufacturer = ' . $idManufacturer];
            $this->hideFacets = ['id_manufacturer'];
        } else {
            MeiliSearchProductSearchProvider::$contextFilters = [];
            $this->hideFacets = [];
        }

        // Save before calling the search (search resets $contextFilters after use)
        $savedContextFilters = MeiliSearchProductSearchProvider::$contextFilters;

        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $this->ajaxRender(json_encode($this->getMeilisearchListingResponse($savedContextFilters)));
        exit;
    }

    private function getMeilisearchListingResponse(array $contextFilters = []): array
    {
        $variables = parent::getAjaxProductSearchVariables();

        // Meili injoignable / en erreur : on ne renvoie PAS de produits, pour que le JS
        // conserve le listing PrestaShop natif déjà rendu côté serveur (repli anti-page-vide).
        if (MeiliSearchProductSearchProvider::$lastRequestFailed) {
            $info = $this->module->lastCurlInfo;
            PrestaShopLogger::addLog(
                sprintf(
                    'Meilisearch indisponible (page listing) — repli PrestaShop natif (HTTP %s, errno %s: %s)',
                    $info['http_code'] ?? '?',
                    $info['errno'] ?? '?',
                    $info['errmsg'] ?? ''
                ),
                2
            );

            return ['meilisearch_failed' => true];
        }

        $facetDistribution = MeiliSearchProductSearchProvider::$lastFacetDistribution;
        $facets = json_decode(json_encode($facetDistribution), true) ?? [];

        $encodedFacets = Tools::getValue('encodedFacets', '');
        if ($encodedFacets) {
            // Restore context filters for disjunctive sub-queries (search already reset them)
            MeiliSearchProductSearchProvider::$contextFilters = $contextFilters;
            $facets = $this->getDisjunctiveFacets($facets, $encodedFacets);
            MeiliSearchProductSearchProvider::$contextFilters = [];
        } else {
            // Meilisearch ne renvoie pas la facette synthétique "availability" :
            // on la reconstruit depuis la distribution `quantity`, sinon l'AJAX
            // initial écrase le compteur serveur par 0.
            $facets['availability'] = ['in_stock' => $this->computeInStockCount($facets)];
        }

        $variables['meilisearch_facets'] = $facets;
        $variables['meilisearch_hidden_facets'] = $this->hideFacets;

        return $variables;
    }

    public function getProductSearchQuery()
    {
        $query = new ProductSearchQuery();
        $query->setQueryType('meilisearch');
        $query->setSearchString('');
        $query->setPage(max(1, (int) Tools::getValue('page', 1)));
        $query->setResultsPerPage(48);
        $_GET['resultsPerPage'] = 48;

        if ($encodedSortOrder = Tools::getValue('order')) {
            $query->setSortOrder(SortOrder::newFromString($encodedSortOrder));
        } else {
            switch (Tools::getValue('page_type', '')) {
                case 'new-products':
                    $query->setSortOrder(new SortOrder('meilisearch', 'date_add', 'DESC'));
                    break;
                case 'best-sales':
                    $query->setSortOrder(new SortOrder('meilisearch', 'quantity', 'DESC'));
                    break;
                default:
                    $query->setSortOrder(new SortOrder('meilisearch', 'relevance', 'ASC'));
                    break;
            }
        }

        $encodedFacets = Tools::getValue('encodedFacets', '');
        if ($encodedFacets) {
            $query->setEncodedFacets($encodedFacets);
        }

        return $query;
    }

    public function getProductSearchContext()
    {
        return new ProductSearchContext($this->context);
    }

    public function getDefaultProductSearchProvider()
    {
        return new MeiliSearchProductSearchProvider($this->getTranslator(), $this->context);
    }

    public function getListingLabel()
    {
        return '';
    }
}
