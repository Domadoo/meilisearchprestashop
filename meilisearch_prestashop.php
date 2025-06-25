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
*  @copyright 2007-2025 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Product\Search\FacetCollection; #Collection de facettes 
use PrestaShop\PrestaShop\Core\Product\Search\Facet; #Classe de la facette 
use PrestaShop\PrestaShop\Core\Product\Search\Filter; #Classe des filtres 
use PrestaShop\PrestaShop\Core\Product\Search\URLFragmentSerializer; #Pour transformer l'url 

class Meilisearch_prestashop extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'meilisearch_prestashop';
        $this->tab = 'search_filter';
        $this->version = '1.0.0';
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
        return parent::install() &&
            $this->registerHook('displaySearch') &&
            $this->registerHook('actionProductSearchAfter') &&
            $this->registerHook('productSearchProvider') &&
            $this->registerHook('header') &&
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
        if(!is_int(Tab::getIdFromClassName('AdminMeiliSearch'))) {
            $this->installTab('AdminMeiliSearch', 'MeiliSearch', 'CONFIGURE');
            $this->installTab('MeiliSearchConfigurationController', 'Configuration', 'AdminMeiliSearch', 'admin_meilisearch_index');
        }

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

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitMeilisearch_prestashopModule')) == true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        return $output.$this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMeilisearch_prestashopModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Live mode'),
                        'name' => 'MEILISEARCH_PRESTASHOP_LIVE_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('Use this module in live mode'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-envelope"></i>',
                        'desc' => $this->l('Enter a valid email address'),
                        'name' => 'MEILISEARCH_PRESTASHOP_ACCOUNT_EMAIL',
                        'label' => $this->l('Email'),
                    ),
                    array(
                        'type' => 'password',
                        'name' => 'MEILISEARCH_PRESTASHOP_ACCOUNT_PASSWORD',
                        'label' => $this->l('Password'),
                    ),
                    array(
                        'type' => 'text',
                        'name' => 'MEILISEARCH_PRESTASHOP_URL',
                        'label' => $this->l('URL'),
                    ),
                    array(
                        'type' => 'text',
                        'name' => 'MEILISEARCH_PRESTASHOP_KEY',
                        'label' => $this->l('KEY'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'MEILISEARCH_PRESTASHOP_LIVE_MODE' => Configuration::get('MEILISEARCH_PRESTASHOP_LIVE_MODE', true),
            'MEILISEARCH_PRESTASHOP_ACCOUNT_EMAIL' => Configuration::get('MEILISEARCH_PRESTASHOP_ACCOUNT_EMAIL', 'contact@prestashop.com'),
            'MEILISEARCH_PRESTASHOP_ACCOUNT_PASSWORD' => Configuration::get('MEILISEARCH_PRESTASHOP_ACCOUNT_PASSWORD', null),
            'MEILISEARCH_PRESTASHOP_URL' => Configuration::get('MEILISEARCH_PRESTASHOP_URL', null),
            'MEILISEARCH_PRESTASHOP_KEY' => Configuration::get('MEILISEARCH_PRESTASHOP_KEY', null),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    /**
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookDisplayBackOfficeHeader() // TODO
    {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader() // TODO
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }

    public function hookActionProductSearchAfter($params)
    {    
        $controllerType = Dispatcher::getInstance()->getController();
        if ($controllerType === 'meilisearch') {
            return;
        }
    
        $query = Tools::getValue('s');
        if (!$query) {
            return;
        }

        $link = Context::getContext()->link->getModuleLink(
            'meilisearch_prestashop',
            'meilisearch',
            ['s' => $query]
        );
    
        Tools::redirect($link);
    }

    // public function hookProductSearchProvider($params){
    //     $query = $params['query'];
    //     if ($query->getQueryType()) {
    //         return new MeiliSearchProductSearchProvider();
    //     }
    // }
    



    public function requestCurl($url, $payload = null, $request = false){
        $authorization = 'Authorization: Bearer '.Configuration::get('MEILISEARCH_PRESTASHOP_KEY');
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
        $ch      = curl_init( $url);

        if($payload != null && $payload != []){
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        
        if($request != false){
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt_array( $ch, $options );

        $content = curl_exec( $ch );
        $err     = curl_errno( $ch );
        $errmsg  = curl_error( $ch );
        $header  = curl_getinfo( $ch );
        curl_close( $ch );
        
        $header['errno']   = $err;
        $header['errmsg']  = $errmsg;
        $header['content'] = $content;
        
        try {
            return json_decode($content);
        } catch (\Throwable $th) {
            //throw $th;
        }
        
    }

    public function getSearchProductsFacets(array $products, array $activeFilters): FacetCollection
    {
        // Initialisation des données nécessaires
        $categoriesCount = [];
        $availabilityCount = [
            'available' => 0,
            'stock' => 0,
        ];

        // Parcours unique des produits
        foreach ($products as $product) {
            // Catégorie
            $catId = (int) $product['id_category_default'];
            $categoriesCount[$catId] = isset($categoriesCount[$catId]) 
                ? $categoriesCount[$catId] + 1 
                : 1;

            // Disponibilité
            $available = (int) $product['available_for_order'];
            if ($available === 1) {
                $availabilityCount['available']++;
                if ((int)$product['quantity'] > 0) {
                    $availabilityCount['stock']++;
                }
            }

            // Fabricant
            $manufacturerId = (int) $product['id_manufacturer'];
            if ($manufacturerId > 0) {
                $manufacturersCount[$manufacturerId] = isset($manufacturersCount[$manufacturerId]) ? $manufacturersCount[$manufacturerId] + 1 : 1;
            }
        }

        $activeFiltersQueryString = implode('|', $activeFilters);
        $collection = new FacetCollection();

        /**
         * --- FACETTE CATEGORIES ---
         */
        if (!empty($categoriesCount)) {
            $facetCategory = new Facet();
            $facetCategory->setLabel($this->l('Catégories'))
                ->setType('category')
                ->setDisplayed(true)
                ->setWidgetType('checkbox')
                ->setMultipleSelectionAllowed(true);

            foreach ($categoriesCount as $categoryId => $count) {
                $filterKey = "cat-" . $categoryId;

                $currentFilters = $activeFilters;
                if (in_array($filterKey, $currentFilters)) {
                    $nextFilters = array_diff($currentFilters, [$filterKey]);
                } else {
                    $nextFilters = $currentFilters;
                    $nextFilters[] = $filterKey;
                }
                $encodedFacetsUrl = implode('|', $nextFilters);

                $category = new Category($categoryId, $this->context->language->id);

                $filter = new Filter();
                $filter->setLabel($category->name)
                    ->setDisplayed(true)
                    ->setActive(in_array($filterKey, $activeFilters))
                    ->setType('category')
                    ->setValue($categoryId)
                    ->setNextEncodedFacets($encodedFacetsUrl)
                    ->setMagnitude($count);

                $facetCategory->addFilter($filter);
            }

            $collection->addFacet($facetCategory);
        }

        /**
         * --- FACETTE DISPONIBILITÉ ---
         */
        $facetAvailability = new Facet();
        $facetAvailability->setLabel($this->l('Disponibilité'))
            ->setType('availability')
            ->setDisplayed(true)
            ->setWidgetType('checkbox')
            ->setMultipleSelectionAllowed(true);
    
        if ($availabilityCount['available'] > 0) {
            $filterKey = "avail-available";

            $currentFilters = $activeFilters;
            if (in_array($filterKey, $currentFilters)) {
                $nextFilters = array_diff($currentFilters, [$filterKey]);
            } else {
                $nextFilters = $currentFilters;
                $nextFilters[] = $filterKey;
            }
            $encodedFacetsUrl = implode('|', $nextFilters);
    
            $filter = new Filter();
            $filter->setLabel($this->l('Disponible'))
                ->setDisplayed(true)
                ->setActive(in_array($filterKey, $activeFilters))
                ->setType('availability')
                ->setValue('available')
                ->setNextEncodedFacets($encodedFacetsUrl)
                ->setMagnitude($availabilityCount['available']);
    
            $facetAvailability->addFilter($filter);
        }
    
        if ($availabilityCount['stock'] > 0) {
            $filterKey = "avail-stock";

            $currentFilters = $activeFilters;
            if (in_array($filterKey, $currentFilters)) {
                $nextFilters = array_diff($currentFilters, [$filterKey]);
            } else {
                $nextFilters = $currentFilters;
                $nextFilters[] = $filterKey;
            }
            $encodedFacetsUrl = implode('|', $nextFilters);
    
            $filter = new Filter();
            $filter->setLabel($this->l('En stock'))
                ->setDisplayed(true)
                ->setActive(in_array($filterKey, $activeFilters))
                ->setType('availability')
                ->setValue('stock')
                ->setNextEncodedFacets($encodedFacetsUrl)
                ->setMagnitude($availabilityCount['stock']);
    
            $facetAvailability->addFilter($filter);
        }
    
        if (!empty($facetAvailability->getFilters())) {
            $collection->addFacet($facetAvailability);
        }

        /**
         * --- FACETTE FABRICANTS ---
         */
        if (!empty($manufacturersCount)) {
            $facetManufacturer = new Facet();
            $facetManufacturer->setLabel($this->l('Brand'))
                ->setType('manufacturer')
                ->setDisplayed(true)
                ->setWidgetType('checkbox')
                ->setMultipleSelectionAllowed(true);

            foreach ($manufacturersCount as $manufacturerId => $count) {
                $filterKey = "manu-" . $manufacturerId;
                
                $currentFilters = $activeFilters;
                if (in_array($filterKey, $currentFilters)) {
                    $nextFilters = array_diff($currentFilters, [$filterKey]);
                } else {
                    $nextFilters = $currentFilters;
                    $nextFilters[] = $filterKey;
                }
                $encodedFacetsUrl = implode('|', $nextFilters);

                
                $manufacturer = new Manufacturer($manufacturerId, $this->context->language->id);

                $filter = new Filter();
                $filter->setLabel($manufacturer->name)
                    ->setDisplayed(true)
                    ->setActive(in_array($filterKey, $activeFilters))
                    ->setType('manufacturer')
                    ->setValue($manufacturerId)
                    ->setNextEncodedFacets($encodedFacetsUrl)
                    ->setMagnitude($count);

                $facetManufacturer->addFilter($filter);
            }

            $collection->addFacet($facetManufacturer);
        }

        return $collection;
    }


}
