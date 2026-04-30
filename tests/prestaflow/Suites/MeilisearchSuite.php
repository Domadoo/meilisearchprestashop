<?php

namespace Tests\Prestaflow\Suites;

use PrestaFlow\Library\Tests\TestsSuite;
use Tests\Prestaflow\Scenarios\ListingPageScenario;
use Tests\Prestaflow\Scenarios\PaginationScenario;

class MeilisearchSuite extends TestsSuite
{
    public function init(): void
    {
        $this->describe('Module Meilisearch — listing & pagination')
            ->setGlobals([
                'foUrl' => $_ENV['PRESTAFLOW_FO_URL'] ?? '',
            ])
            ->scenario(ListingPageScenario::class)
            ->scenario(PaginationScenario::class);
    }
}
