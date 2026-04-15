<?php
/**
 * 2007-2026 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2026 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use PrestaShop\PrestaShop\Core\Product\Search\FacetCollection; #Collection de facettes 
use PrestaShop\PrestaShop\Core\Product\Search\Facet; #Classe de la facette 
use PrestaShop\PrestaShop\Core\Product\Search\Filter; #Classe des filtres 
use PrestaShop\PrestaShop\Core\Product\Search\URLFragmentSerializer; #Pour transformer l'url

use PrestaShop\Module\Classes\MeilisearchStatssearch;

class Meilisearchprestashop extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'meilisearchprestashop';
        $this->tab = 'search_filter';
        $this->version = '1.1.4';
        $this->author = 'Doudeau Adam, Johan Vivien';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Meilisearch Prestashop');
        $this->description = $this->l('Prestashop module to replace the standard searchbar with Meilisearch');

        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => '8.0');
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        include(dirname(__FILE__).'/sql/install.php');

        return parent::install() &&
            $this->registerHook('displayHeader') &&
            $this->registerHook('displaySearch') &&
            $this->registerHook('displayLeftColumn') &&
            $this->registerHook('actionCartUpdateQuantityBefore') &&
            $this->registerHook('actionPresentProduct') &&
            $this->registerHook('actionValidateOrder') &&
            $this->callInstallTab();
    }

    public function uninstall()
    {
        return parent::uninstall()
            && $this->uninstallTab();
    }

    public function installTab($className, $tabName, $tabParentName = false, $routeName = '')
    {
        $tab = new Tab();
        $tab->active = true;
        $tab->class_name = $className;
        $tab->route_name = $routeName;
        $tab->name = array();

        if ($className == 'AdminMeiliSearch') { //Tab name for which you want to add icon
            $tab->icon = 'repeat'; //Material Icon name
        }

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $tabName;
        }

        if ($tabParentName) {
            $tab->id_parent = (int) Tab::getIdFromClassName($tabParentName);
        } else {
            $tab->id_parent = 0;
        }

        $tab->module = $this->name;

        return $tab->add();
    }

    public function callInstallTab()
    {
        $this->installTab('AdminMeiliSearch', 'MeiliSearch', 'CONFIGURE');
        $this->installTab('AdminMeiliSearchParent', 'MeiliSearch', 'AdminMeiliSearch');
        $this->installTab('MeiliSearchConfigurationController', 'Settings', 'AdminMeiliSearchParent', 'admin_meilisearch_configuration_index');
        $this->installTab('MeiliSearchIndexController', 'Index', 'AdminMeiliSearchParent', 'admin_meilisearch_index_index');
        $this->installTab('MeiliSearchStatsController', 'Statistics', 'AdminMeiliSearch', 'admin_meilisearch_stats_index');

        return true;
    }

    public function uninstallTab()
    {
        $moduleTabs = Tab::getCollectionFromModule($this->name);
        if (!empty($moduleTabs)) {
            foreach ($moduleTabs as $moduleTab) {
                $moduleTab->delete();
            }
        }

        return true;
    }

    public function isUsingNewTranslationSystem()
    {
        return false;
    }

    /**
     * Redirect to the dedicated Settings controller.
     */
    public function getContent()
    {
        $this->uninstallTab();
        $this->callInstallTab();
        $router = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()->get('router');
        Tools::redirectAdmin($router->generate('admin_meilisearch_configuration_index'));
    }

    public function hookDisplaySearch(){
        $this->context->smarty->assign([
            'search_string' => Tools::getValue('s', Tools::getValue('search_query', '')),
        ]);
        return $this->display(__FILE__, 'meilisearch_searchbar.tpl');
    }

    public function hookDisplayHeader(){
        
        $this->trans('This product is no longer available.', [], 'Modules.Meilisearchprestashop.front');

        Media::addJsDef(['searchPlaceholder' =>  [
            '1' => $this->l('Search an article'),
            '2' => $this->l('Search a product'),
            '3' => $this->l('Search a category')
        ]]);

        $link = Context::getContext()->link->getModuleLink(
            'meilisearchprestashop',
            'meilisearch'
        );

        $cookie = $this->context->cookie;

        $this->context->smarty->assign([
            'meilisearchUrl' => $link,
        ]);

        $this->context->controller->addJS($this->_path.'views/js/front/meilisearch_searchbar.js');
        $this->context->controller->addCSS($this->_path.'views/css/front/meilisearch_searchbar.css');
    }

    public function hookDisplayLeftColumn(){

    }

    public function hookActionPresentProduct()
    {
        unset($this->context->cookie->meilisearch_query);
        unset($this->context->cookie->meilisearch_id);
        if(Tools::getValue('id_meilisearch_statssearch')) {
            $id_meilisearch_statssearch = (int)Tools::getValue('id_meilisearch_statssearch');
            $search = new MeilisearchStatssearch($id_meilisearch_statssearch);
            if(Validate::isLoadedObject($search)) {
                
                // @phpstan-ignore-next-line
                $this->context->cookie->meilisearch_id = $id_meilisearch_statssearch;
                // @phpstan-ignore-next-line
                $this->context->cookie->meilisearch_product_id = Tools::getValue('id_product');
            }
        }
    }

    public function hookActionCartUpdateQuantityBefore($params)
    {
        if(isset($this->context->cookie->meilisearch_id) && isset($this->context->cookie->meilisearch_product_id) 
            && $params['product']->id == $this->context->cookie->meilisearch_product_id && $params['operator'] == 'up') {

            $newSearch = new MeilisearchStatssearch($this->context->cookie->meilisearch_id);
            if(!Validate::isLoadedObject($newSearch) || $newSearch->id_product != $this->context->cookie->meilisearch_product_id) {
                return;
            }
            $newSearch->id_cart = $params['cart']->id;
            $newSearch->save();

            unset($this->context->cookie->meilisearch_id);
            unset($this->context->cookie->meilisearch_product_id);
        }
    }

    public function hookActionValidateOrder($params)
    {
        try {
            $listSearches = MeilisearchStatssearch::getSearchesByIdCart($params['cart']->id);
            foreach ($listSearches as $search) {
                $newSearch = new MeilisearchStatssearch($search['id_statssearch']);
                if(Validate::isLoadedObject($newSearch)) {
                    $newSearch->is_ordered = 1;
                    $newSearch->save();
                }
            }
        } catch (\Throwable $th) {
            PrestaShopLogger::addLog('Error validation order Meilisearch : '.$th->getMessage(), 3);
        }
    }

    public function requestCurl($url, $payload = null, $request = false)
    {
        $authorization = 'Authorization: Bearer ' . Configuration::get('MEILISEARCHPRESTASHOP_KEY');
        $options = array(
            CURLOPT_RETURNTRANSFER => true,     // return web page
            CURLOPT_HEADER         => false,    // don't return headers
            CURLOPT_FOLLOWLOCATION => true,     // follow redirects
            CURLOPT_ENCODING       => "",       // handle all encodings
            CURLOPT_USERAGENT      => "spider", // who am i
            CURLOPT_AUTOREFERER    => true,     // set referer on redirect
            CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
            CURLOPT_TIMEOUT        => 120,      // timeout on response
            CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
            CURLOPT_SSL_VERIFYPEER => false    // Disabled SSL Cert checks
        );
        $header = ['Content-Type: application/json', $authorization];
        $ch      = curl_init($url);

        if ($payload != null && $payload != []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        if ($request != false) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt_array($ch, $options);

        $content = curl_exec($ch);
        $err     = curl_errno($ch);
        $errmsg  = curl_error($ch);
        $header  = curl_getinfo($ch);
        curl_close($ch);

        $header['errno']   = $err;
        $header['errmsg']  = $errmsg;
        $header['content'] = $content;

        return json_decode($content);
    }
}
