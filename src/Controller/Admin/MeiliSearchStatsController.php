<?php
namespace PrestaShop\Module\MeiliSearch\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShop\Module\Classes\MeilisearchStatssearch;
use Media;
use Tools;
use Language;

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
        $objMeilisearchStats = new MeilisearchStatssearch();
        $buttonClicked = Tools::getValue('selectedPeriod', 'submitDateAllTime');
        $id_lang = Tools::getValue('id_lang', 0);

        foreach ($_POST as $key => $value) {
            if (strpos($key, 'submitDate') === 0) {
                $buttonClicked = $key;
                break;
            }
        }

        $dateBegin = null;
        $dateEnd = null;
        if ($buttonClicked) {
            switch ($buttonClicked) {
                case 'submitDateDay':
                    $dateBegin = date('Y-m-d H:i:s', strtotime('-1 day'));
                    $dateEnd = date('Y-m-d H:i:s');
                    break;
                case 'submitDateMonth':
                    $dateBegin = date('Y-m-d H:i:s', strtotime('-1 month'));
                    $dateEnd = date('Y-m-d H:i:s');
                    break;
                case 'submitDateYear':
                    $dateBegin = date('Y-m-d H:i:s', strtotime('-1 year'));
                    $dateEnd = date('Y-m-d H:i:s');
                    break;
                case 'submitDateDayPrev':
                    $dateBegin = date('Y-m-d H:i:s', strtotime('-2 day'));
                    $dateEnd = date('Y-m-d H:i:s', strtotime('-1 day'));
                    break;
                case 'submitDateMonthPrev':
                    $dateBegin = date('Y-m-d H:i:s', strtotime('-2 month'));
                    $dateEnd = date('Y-m-d H:i:s', strtotime('-1 month'));
                    // Mois -1
                    break;
                case 'submitDateYearPrev':
                    $dateBegin = date('Y-m-d H:i:s', strtotime('-2 year'));
                    $dateEnd = date('Y-m-d H:i:s', strtotime('-1 year'));
                    // Année -1
                    break;
                
            }
        }

        $mostSearchedQueries = $objMeilisearchStats->getMostSearchedQueries(10, $dateBegin, $dateEnd, $id_lang);
        $i = 1;
        $dataMostSearchQueriesValues = [];
        foreach ($mostSearchedQueries as $query) {

            $dataMostSearchQueriesValues[] = [
                'label' => $query['label'],
                'value' => (int)$query['value'],
                'id' => $i++,
            ];
        }
        $dataMostSearchedQueries = [[
            'key' => 'Most Searched Queries',
            'color' => '#ff0000',
            'values' => $dataMostSearchQueriesValues,
        ]];

        $mostSearchedEmptyQueries = $objMeilisearchStats->getMostSearchedEmptyQueries(10, $dateBegin, $dateEnd, $id_lang);
        $i = 1;
        $dataMostSearchedEmptyQueriesValues = [];
        foreach ($mostSearchedEmptyQueries as $query) {
            $dataMostSearchedEmptyQueriesValues[] = [
                'label' => $query['label'],
                'value' => (int)$query['value'],
                'id' => $i++,
            ];
        }

        $dataMostSearchedEmptyQueries = [[
            'key' => 'Most Searched Empty Queries',
            'color' => '#ff0000',
            'values' => $dataMostSearchedEmptyQueriesValues,
        ]]; 

        $mostClickedProducts = $objMeilisearchStats->getMostClickedProducts(10, $dateBegin, $dateEnd, $id_lang);
        $i = 1;
        $dataMostClickedProductsValues = [];
        foreach ($mostClickedProducts as $product) {
            $dataMostClickedProductsValues[] = [
                'label' => $product['label'],
                'value' => (int)$product['value'],
                'id' => $i++,
            ];
        }

        $dataMostClickedProducts = [[
            'key' => 'Most Clicked Products',
            'color' => '#ff0000',
            'values' => $dataMostClickedProductsValues
        ]];

        $ctrPercentages = $objMeilisearchStats->getCTR($dateBegin, $dateEnd, $id_lang);
        $dataCtr = [];
        if ($ctrPercentages) {
            $dataCtr = [
                [
                    'label' => 'Click Through Rate',
                    'value' => (float)$ctrPercentages,
                ],
                [
                    'label' => 'No Click Through Rate',
                    'value' => 100 - (float)$ctrPercentages,
                ],
            ];
        }


        Media::addJsDef([
            'dataSearches' => $dataMostSearchedQueries,
            'dataEmpty' => $dataMostSearchedEmptyQueries,
            'dataClicks' => $dataMostClickedProducts,
            'dataCtr' => $dataCtr,
        ]);

        return $this->render('@Modules/meilisearch_prestashop/views/templates/admin/stats/statistiques.html.twig', [
            'buttonClicked' => $buttonClicked,
            'ctrPercentages' => $ctrPercentages,
            'selectedIdLang' => Tools::getValue('id_lang'),
            'languages' => Language::getLanguages(false),
        ]);
    }
}