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

namespace PrestaShop\Module\MeiliSearch\Controller\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\Classes\MeilisearchStatssearch;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;

class MeiliSearchStatsController extends FrameworkBundleAdminController
{
    private $module;

    public function __construct()
    {
        $parent = get_parent_class($this);
        if ($parent && method_exists($parent, '__construct')) {
            $parent::__construct();
        }
        // Récupère une instance fonctionnelle du module
        $this->module = \Module::getInstanceByName('meilisearchprestashop');
    }

    public function indexAction()
    {
        $objMeilisearchStats = new MeilisearchStatssearch();
        $buttonClicked = \Tools::getValue('selectedPeriod', 'submitDateAllTime');
        $id_lang = \Tools::getValue('id_lang', 0);

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
                'value' => (int) $query['value'],
                'id' => $i++,
            ];
        }
        $dataMostSearchedQueries = [[
            'key' => 'Most Searched Queries',
            'color' => '#ff0000',
            'values' => $dataMostSearchQueriesValues,
        ]];

        $mostClickedProducts = $objMeilisearchStats->getMostClickedProducts(10, $dateBegin, $dateEnd, $id_lang);
        $i = 1;
        $dataMostClickedProductsValues = [];
        foreach ($mostClickedProducts as $product) {
            $dataMostClickedProductsValues[] = [
                'label' => $product['label'],
                'value' => (int) $product['value'],
                'id' => $i++,
            ];
        }

        $dataMostClickedProducts = [[
            'key' => 'Most Clicked Products',
            'color' => '#ff0000',
            'values' => $dataMostClickedProductsValues,
        ]];

        $mostSearchedEmptyQueries = $objMeilisearchStats->getMostSearchedEmptyQueries(10, $dateBegin, $dateEnd, $id_lang);
        $i = 1;
        $dataMostSearchedEmptyQueriesValues = [];
        foreach ($mostSearchedEmptyQueries as $query) {
            $dataMostSearchedEmptyQueriesValues[] = [
                'label' => $query['label'],
                'value' => (int) $query['value'],
                'id' => $i++,
            ];
        }

        $dataMostSearchedEmptyQueries = [[
            'key' => 'Most Searched Empty Queries',
            'color' => '#ff0000',
            'values' => $dataMostSearchedEmptyQueriesValues,
        ]];

        $ctrPercentages = $objMeilisearchStats->getCTR($dateBegin, $dateEnd, $id_lang);
        $dataCtr = [];
        if ($ctrPercentages) {
            $dataCtr = [
                [
                    'label' => $this->module->l('Click through rate', 'meilisearchstatscontroller'),
                    'value' => (float) $ctrPercentages,
                ],
                [
                    'label' => $this->module->l('No Click through rate', 'meilisearchstatscontroller'),
                    'value' => 100 - (float) $ctrPercentages,
                ],
            ];
        }

        $addedToCartRate = $objMeilisearchStats->getAddedToCartRate($dateBegin, $dateEnd, $id_lang);
        $dataAddedToCartRate = [];
        if ($addedToCartRate) {
            $dataAddedToCartRate = [
                [
                    'label' => $this->module->l('Added to cart rate', 'meilisearchstatscontroller'),
                    'value' => (float) $addedToCartRate,
                ],
                [
                    'label' => $this->module->l('No Added to cart rate', 'meilisearchstatscontroller'),
                    'value' => 100 - (float) $addedToCartRate,
                ],
            ];
        }

        $conversionRate = $objMeilisearchStats->getConversionRate($dateBegin, $dateEnd, $id_lang);
        $dataConversionRate = [];
        if ($conversionRate) {
            $dataConversionRate = [
                [
                    'label' => $this->module->l('Conversion rate', 'meilisearchstatscontroller'),
                    'value' => (float) $conversionRate,
                ],
                [
                    'label' => $this->module->l('No Conversion rate', 'meilisearchstatscontroller'),
                    'value' => 100 - (float) $conversionRate,
                ],
            ];
        }

        $totalSearches = $objMeilisearchStats->getTotalSearches($dateBegin, $dateEnd, $id_lang);

        \Media::addJsDef([
            'dataSearches' => $dataMostSearchedQueries,
            'dataEmpty' => $dataMostSearchedEmptyQueries,
            'dataClicks' => $dataMostClickedProducts,
            'dataCtr' => $dataCtr,
            'dataAddedToCartRate' => $dataAddedToCartRate,
            'dataConversionRate' => $dataConversionRate,
        ]);

        return $this->render('@Modules/meilisearchprestashop/views/templates/admin/stats/statistiques.html.twig', array_merge([
            'buttonClicked' => $buttonClicked,
            'ctrPercentages' => $ctrPercentages,
            'addedToCartRate' => $addedToCartRate,
            'conversionRate' => $conversionRate,
            'selectedIdLang' => \Tools::getValue('id_lang'),
            'languages' => \Language::getLanguages(false),
            'totalSearches' => $totalSearches,
        ], $this->getTranslatedText()));
    }

    public function getTranslatedText()
    {
        return [
            'allLanguagesText' => $this->module->l('All Languages', 'meilisearchstatscontroller'),
            'topSearchesText' => $this->module->l('Top Searches', 'meilisearchstatscontroller'),
            'topClickedText' => $this->module->l('Top Clicked', 'meilisearchstatscontroller'),
            'noResultsText' => $this->module->l('No results', 'meilisearchstatscontroller'),
            'CTRText' => $this->module->l('CTR', 'meilisearchstatscontroller'),
            'clickThroughRateText' => $this->module->l('Click through rate', 'meilisearchstatscontroller'),
            'addedToCartRateText' => $this->module->l('Added to cart rate', 'meilisearchstatscontroller'),
            'conversionRateText' => $this->module->l('Conversion rate', 'meilisearchstatscontroller'),
            'totalSearchesText' => $this->module->l('Total searches', 'meilisearchstatscontroller'),
        ];
    }
}
