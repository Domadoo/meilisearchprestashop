<?php
namespace PrestaShop\Module\MeiliSearch\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShop\Module\Classes\MeilisearchStatssearch;



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
        // exit(print_r($test->getMostSearchedQueries(100)));
        return $this->render('@Modules/meilisearch_prestashop/views/templates/admin/stats/statistiques.html.twig');
    }
}