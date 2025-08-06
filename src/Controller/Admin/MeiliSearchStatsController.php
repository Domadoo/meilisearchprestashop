<?php
namespace PrestaShop\Module\MeiliSearch\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShop\Module\Classes\MeilisearchStatssearch;
use Media;


class MeiliSearchStatsController extends FrameworkBundleAdminController
{
    private $module;

    public function __construct()
    {
        parent::__construct();
        // Récupère une instance fonctionnelle du module
        $this->module = \Module::getInstanceByName('meilisearch_prestashop');
    }

    public function indexAction()
    {
        // Juste la vue avec le bouton
        $objMeilisearchStats = new MeilisearchStatssearch();


        $mostSearchedQueries = $objMeilisearchStats->getMostSearchedQueries(10);
        foreach ($mostSearchedQueries as $query) {
            $dataMostSearchQueriesValues[] = [
                'label' => $query['label'],
                'value' => (int)$query['value'],
            ];
        }
        $dataMostSearchedQueries = [[
            'key' => 'Most Searched Queries',
            'color' => '#ff0000',
            'values' => $dataMostSearchQueriesValues,
        ]];

        $mostSearchedEmptyQueries = $objMeilisearchStats->getMostSearchedEmptyQueries(10);
        foreach ($mostSearchedEmptyQueries as $query) {
            $dataMostSearchedEmptyQueriesValues[] = [
                'label' => $query['label'],
                'value' => (int)$query['value'],
            ];
        }
        $dataMostSearchedEmptyQueries = [[
            'key' => 'Most Searched Empty Queries',
            'color' => '#ff0000',
            'values' => $dataMostSearchedEmptyQueriesValues,
        ]]; 

        Media::addJsDef([
            'dataSearches' => $dataMostSearchedQueries,
            'dataEmpty' => $dataMostSearchedEmptyQueries,
        ]);
        
        return $this->render('@Modules/meilisearch_prestashop/views/templates/admin/stats/statistiques.html.twig');
    }
}