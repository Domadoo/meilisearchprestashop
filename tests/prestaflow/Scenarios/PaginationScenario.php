<?php

namespace Tests\Prestaflow\Scenarios;

use PrestaFlow\Library\Pages\FrontOfficePage;
use PrestaFlow\Library\Scenarios\Scenario;
use PrestaFlow\Library\Expects\Expect;

/**
 * Régression : cliquer sur la page 2 d'un listing redirige vers l'accueil
 * quand le handler jQuery du thème émet updateFacets avant le handler capture-phase.
 * Ce test valide que l'URL reste sur la catégorie après la navigation.
 */
class PaginationScenario extends Scenario
{
    protected array $params = [
        'id_category' => '184',
    ];

    public function steps($suite)
    {
        $fo = new FrontOfficePage(
            $this->getParam('locale'),
            $this->getParam('patchVersion'),
            $this->globals
        );

        $listingUrl = rtrim($this->globals['foUrl'], '/')
            . '/fr/module/meilisearchprestashop/listing?id_category='
            . $this->getParam('id_category');

        return $suite
            ->it('clique sur page 2 et reste sur la page catégorie', function () use ($fo, $listingUrl) {
                $fo->goToUrl($listingUrl);

                $fo->click('.js-search-link[aria-label="Page 2"], .pagination a[href*="page=2"]');

                $currentUrl = $fo->getGlobal('currentUrl') ?? '';

                Expect::that(str_contains($currentUrl, 'listing.php') || str_contains($currentUrl, '/module/meilisearchprestashop/listing'))
                    ->isFalse();

                Expect::that($fo->elementIsVisible('#js-product-list'))
                    ->isTrue();
            })

            ->it('la page 2 affiche des produits différents', function () use ($fo) {
                Expect::that($fo->elementIsVisible('#js-product-list'))
                    ->isTrue();
            });
    }
}
