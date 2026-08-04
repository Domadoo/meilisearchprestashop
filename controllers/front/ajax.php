<?php

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
 * @copyright 2007-2025 Domadoo
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\Classes\MeilisearchStatssearch;

class MeilisearchprestashopAjaxModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function postProcess()
    {
        $action = Tools::getValue('action');
        $cookie = $this->context->cookie;

        // Endpoint public d'autocomplétion (données de recherche publiques, pas de token requis)
        if ($action === 'autocomplete') {
            $this->autocompleteAction();

            return;
        }

        // Les autres actions (tracking) restent protégées par le token.
        $token = Tools::getValue('token');
        if ($token !== '1') {
            http_response_code(403);
            exit(json_encode(['error' => 'Access denied']));
        }

        switch ($action) {
            case 'productClick':
                $idProduct = (int) Tools::getValue('id_product');
                $position = (int) Tools::getValue('position');
                if ($idProduct <= 0 || $position < 0) {
                    break;
                }
                // @phpstan-ignore-next-line
                if (isset($cookie->meilisearch_id)) {
                    try {
                        // @phpstan-ignore-next-line
                        $newSearch = new MeilisearchStatssearch((int) $cookie->meilisearch_id);
                        if (!Validate::isLoadedObject($newSearch)) {
                            break;
                        }
                        $newSearch->id_product = $idProduct;
                        $newSearch->position = $position;
                        $newSearch->save();

                        // @phpstan-ignore-next-line
                        $cookie->meilisearch_product_id = $idProduct;
                    } catch (Exception $e) {
                        PrestaShopLogger::addLog(
                            'Meilisearch productClick error: ' . $e->getMessage(),
                            2,
                            null,
                            'MeilisearchStatssearch'
                        );
                    }
                }
                break;

            default:
                break;
        }

        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success' => true]));
    }

    /**
     * Autocomplétion de la barre de recherche : renvoie des suggestions de recherches
     * populaires (stats) + un aperçu de produits correspondants (préfixe Meilisearch).
     */
    private function autocompleteAction()
    {
        header('Content-Type: application/json; charset=utf-8');

        $q = trim((string) Tools::getValue('s'));
        $q = mb_substr($q, 0, 100);

        if (mb_strlen($q) < 2) {
            exit(json_encode(['queries' => [], 'products' => []]));
        }

        $idLang = (int) $this->context->language->id;
        $isoLang = $this->context->language->iso_code;

        // 1) Recherches populaires (préfixe)
        $queries = MeilisearchStatssearch::getSuggestionsByPrefix($q, 3, $idLang);

        // 2) Produits correspondants via Meilisearch (préfixe natif)
        $products = [];
        $meiliUrl = Configuration::get('MEILISEARCHPRESTASHOP_URL');

        if ($meiliUrl) {
            $searchUrl = $meiliUrl . 'indexes/' . Configuration::get('MEILISEARCHPRESTASHOP_PREFIX')
                . 'products_' . $isoLang . '/search';

            $payload = [
                'q' => $q,
                'limit' => 5,
                'attributesToRetrieve' => ['id_product'],
                'attributesToSearchOn' => ['name', 'manufacturer_name'],
                'filter' => [['visibility = both'], ['available_for_order = true']],
                'sort' => ['sales:desc'],
            ];

            // @phpstan-ignore-next-line
            $response = $this->module->requestCurlSearch($searchUrl, json_encode($payload));

            if ($response instanceof stdClass && isset($response->hits) && is_array($response->hits)) {
                foreach ($response->hits as $hit) {
                    $idProduct = (int) (is_object($hit) ? ($hit->id_product ?? 0) : ($hit['id_product'] ?? 0));
                    if ($idProduct <= 0) {
                        continue;
                    }
                    $card = $this->buildProductCard($idProduct, $idLang);
                    if ($card !== null) {
                        $products[] = $card;
                    }
                }
            }
        }

        exit(json_encode(['queries' => $queries, 'products' => $products]));
    }

    /**
     * Construit une carte produit légère (nom, prix formaté, image, url) pour l'aperçu.
     *
     * @return array<string, mixed>|null
     */
    private function buildProductCard(int $idProduct, int $idLang): ?array
    {
        $product = new Product($idProduct, false, $idLang);
        if (!Validate::isLoadedObject($product)) {
            return null;
        }

        $link = $this->context->link;

        // Image de couverture (fallback : pas d'image)
        $image = '';
        $cover = Image::getCover($idProduct);
        if ($cover && isset($cover['id_image'])) {
            $rewrite = is_array($product->link_rewrite) ? reset($product->link_rewrite) : $product->link_rewrite;
            $image = $link->getImageLink($rewrite, (int) $cover['id_image'], 'home_default');
        }

        // Prix TTC formaté selon la locale/devise du contexte
        $price = Product::getPriceStatic($idProduct, true);
        try {
            $priceFormatted = $this->context->getCurrentLocale()->formatPrice((float) $price, $this->context->currency->iso_code);
        } catch (Throwable $th) {
            $priceFormatted = Tools::displayPrice((float) $price);
        }

        $name = is_array($product->name) ? reset($product->name) : $product->name;

        return [
            'id' => $idProduct,
            'name' => $name,
            'price' => $priceFormatted,
            'image' => $image,
            'url' => $link->getProductLink($product),
        ];
    }
}
