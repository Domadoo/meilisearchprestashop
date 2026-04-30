<?php

namespace Tests\Prestaflow\Scenarios;

use PrestaFlow\Library\Pages\FrontOfficePage;
use PrestaFlow\Library\Scenarios\Scenario;
use PrestaFlow\Library\Expects\Expect;

class ListingPageScenario extends Scenario
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
            ->it('charge la page listing avec les facettes', function () use ($fo, $listingUrl) {
                $fo->goToUrl($listingUrl);

                Expect::that($fo->elementIsVisible('.meilisearch-facets'))
                    ->isTrue();
            })

            ->it('affiche des produits sur la page listing', function () use ($fo) {
                Expect::that($fo->elementIsVisible('#js-product-list'))
                    ->isTrue();

                Expect::that($fo->getTextContent('.total-products'))
                    ->isNotEmpty();
            });
    }
}
