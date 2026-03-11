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
        $this->version = '1.1.3';
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
        if (!is_int(Tab::getIdFromClassName('AdminMeiliSearch'))) {
            $this->installTab('AdminMeiliSearch', 'MeiliSearch', 'CONFIGURE');
            $this->installTab('MeiliSearchConfigurationController', 'Configuration', 'AdminMeiliSearch', 'admin_meilisearchconfiguration_index');
            $this->installTab('MeiliSearchStatsController', 'Statistics', 'AdminMeiliSearch', 'admin_meilisearch_stats_index');
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

    public function isUsingNewTranslationSystem()
    {
        return false;
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitMeilisearchprestashopModule')) == true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        return $output . $this->renderForm();
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
        $helper->submit_action = 'submitMeilisearchprestashopModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
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
                        'name' => 'MEILISEARCHPRESTASHOP_LIVE_MODE',
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
                        'name' => 'MEILISEARCHPRESTASHOP_ACCOUNT_EMAIL',
                        'label' => $this->l('Email'),
                    ),
                    array(
                        'type' => 'password',
                        'name' => 'MEILISEARCHPRESTASHOP_ACCOUNT_PASSWORD',
                        'label' => $this->l('Password'),
                    ),
                    array(
                        'type' => 'text',
                        'name' => 'MEILISEARCHPRESTASHOP_URL',
                        'label' => $this->l('URL'),
                    ),
                    array(
                        'type' => 'text',
                        'name' => 'MEILISEARCHPRESTASHOP_KEY',
                        'label' => $this->l('KEY'),
                    ),
                    array(
                        'type' => 'text',
                        'name' => 'MEILISEARCHPRESTASHOP_PREFIX',
                        'label' => $this->l('PREFIX'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'name' => 'MEILISEARCHPRESTASHOP_TOKEN_CRON',
                        'desc' => $this->l('Enter a private key, for exemple').' : ' .Tools::passwdGen(32),
                        'label' => $this->l('Token for access to the cron'),
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
            'MEILISEARCHPRESTASHOP_LIVE_MODE' => Configuration::get('MEILISEARCHPRESTASHOP_LIVE_MODE'),
            'MEILISEARCHPRESTASHOP_ACCOUNT_EMAIL' => Configuration::get('MEILISEARCHPRESTASHOP_ACCOUNT_EMAIL'),
            'MEILISEARCHPRESTASHOP_ACCOUNT_PASSWORD' => Configuration::get('MEILISEARCHPRESTASHOP_ACCOUNT_PASSWORD', null),
            'MEILISEARCHPRESTASHOP_URL' => Configuration::get('MEILISEARCHPRESTASHOP_URL', null),
            'MEILISEARCHPRESTASHOP_KEY' => Configuration::get('MEILISEARCHPRESTASHOP_KEY', null),
            'MEILISEARCHPRESTASHOP_PREFIX' => Configuration::get('MEILISEARCHPRESTASHOP_PREFIX', null),
            'MEILISEARCHPRESTASHOP_TOKEN_CRON' => Configuration::get('MEILISEARCHPRESTASHOP_TOKEN_CRON', null)
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

    public function hookDisplaySearch(){
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

        $this->context->controller->addJS($this->_path.'/views/js/front/meilisearch_searchbar.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front/meilisearch_searchbar.css');
    }

    public function hookActionPresentProduct()
    {
        unset($this->context->cookie->meilisearch_query);
        unset($this->context->cookie->meilisearch_id);
        if(Tools::getValue('id_meilisearch_statssearch')) {
            $id_meilisearch_statssearch = (int)Tools::getValue('id_meilisearch_statssearch');
            $search = new MeilisearchStatssearch($id_meilisearch_statssearch);
            if(Validate::isLoadedObject($search)) {
                
                $this->context->cookie->meilisearch_id = $id_meilisearch_statssearch;
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

        try {
            return json_decode($content);
        } catch (\Throwable $th) {
            //throw $th;
        }
    }

    public function getSearchProductsFacets(array $products, array $activeFilters)
    {
        $activeFiltersQueryString = implode('|', $activeFilters);
        $collection = new FacetCollection();

        // Compteurs
        $manufacturerCount = [];
        $availabilityCount = [
            'available' => 0,
            'stock' => 0,
        ];
        $technologyCount = [];    // feature 7
        $compatibilityCount = []; // feature 31
        $typeCount = [];          // feature 6
        $functionCount = [];      // feature 12

        foreach ($products as $product) {
            // Categories
            $catId = $product['id_category_default'];
            $categoriesCount[$catId] = ($categoriesCount[$catId] ?? 0) + 1;

            // Manufacturer
            $manuId = $product['id_manufacturer'];
            $manufacturerCount[$manuId] = ($manufacturerCount[$manuId] ?? 0) + 1;

            // Availability
            $available = (int)$product['available_for_order'];
            if ($available === 1) {
                $availabilityCount['available']++;
                if (!empty($product['quantity']) && $product['quantity'] > 0) {
                    $availabilityCount['stock']++;
                }
            }

            // Features (feature_values as ["7-36", "31-43", ...])
            if (!empty($product['feature_values']) && is_array($product['feature_values'])) {
                foreach ($product['feature_values'] as $fv) {
                    if (strpos($fv, '6-') === 0) {
                        $id = (int)explode('-', $fv)[1];
                        $typeCount[$id] = ($typeCount[$id] ?? 0) + 1;
                    } elseif (strpos($fv, '7-') === 0) {
                        $id = (int)explode('-', $fv)[1];
                        $technologyCount[$id] = ($technologyCount[$id] ?? 0) + 1;
                    } elseif (strpos($fv, '12-') === 0) {
                        $id = (int)explode('-', $fv)[1];
                        $functionCount[$id] = ($functionCount[$id] ?? 0) + 1;
                    } elseif (strpos($fv, '31-') === 0) {
                        $id = (int)explode('-', $fv)[1];
                        $compatibilityCount[$id] = ($compatibilityCount[$id] ?? 0) + 1;
                    }
                }
            }    
        }

        // ------------- Création des facettes -------------

        // Facette fabricants
        if ($manufacturerCount) {
            $facet = new Facet();
            $facet->setLabel($this->l('Manufacturers'))
                ->setType('manufacturer')
                ->setDisplayed(true)
                ->setWidgetType('checkbox')
                ->setMultipleSelectionAllowed(true);

            foreach ($manufacturerCount as $manuId => $count) {
                $filterKey = "manu-$manuId";

                $currentFilters = $activeFilters;
                if (in_array($filterKey, $currentFilters)) {
                    $nextFilters = array_diff($currentFilters, [$filterKey]);
                } else {
                    $nextFilters = $currentFilters;
                    $nextFilters[] = $filterKey;
                }
                $encodedFacetsUrl = implode('|', $nextFilters);

                $manufacturer = new Manufacturer($manuId, $this->context->language->id);

                $filter = new Filter();
                $filter->setLabel($manufacturer->name)
                    ->setValue($manuId)
                    ->setType('manufacturer')
                    ->setMagnitude($count)
                    ->setActive(in_array($filterKey, $activeFilters))
                    ->setNextEncodedFacets($encodedFacetsUrl);
                $facet->addFilter($filter);
            }
            $collection->addFacet($facet);
        }

        // Facette disponibilité — ici on ne gère que 'available' et 'stock'
        if ($availabilityCount['available'] > 0) {
            $facet = new Facet();
            $facet->setLabel($this->l('Availability'))
                ->setType('availability')
                ->setDisplayed(true)
                ->setWidgetType('checkbox')
                ->setMultipleSelectionAllowed(true);

            // "Available" filtre
            $filterKey = 'avail-available';
            $currentFilters = $activeFilters;
            if (in_array($filterKey, $currentFilters)) {
                $nextFilters = array_diff($currentFilters, [$filterKey]);
            } else {
                $nextFilters = $currentFilters;
                $nextFilters[] = $filterKey;
            }
            $encodedFacetsUrl = implode('|', $nextFilters);
            $filter = new Filter();
            $filter->setLabel($this->l('Available'))
                ->setValue('available')
                ->setType('availability')
                ->setMagnitude($availabilityCount['available'])
                ->setActive(in_array($filterKey, $activeFilters))
                ->setNextEncodedFacets($encodedFacetsUrl);
            $facet->addFilter($filter);

            // "In stock" filtre
            if ($availabilityCount['stock'] > 0) {
                $filterKey = 'avail-stock';
                $currentFilters = $activeFilters;
                if (in_array($filterKey, $currentFilters)) {
                    $nextFilters = array_diff($currentFilters, [$filterKey]);
                } else {
                    $nextFilters = $currentFilters;
                    $nextFilters[] = $filterKey;
                }
                $encodedFacetsUrl = implode('|', $nextFilters);
                $filter = new Filter();
                $filter->setLabel($this->l('In stock'))
                    ->setValue('stock')
                    ->setType('availability')
                    ->setMagnitude($availabilityCount['stock'])
                    ->setActive(in_array($filterKey, $activeFilters))
                    ->setNextEncodedFacets($encodedFacetsUrl);
                $facet->addFilter($filter);
            }

            $collection->addFacet($facet);
        }

        // Facette features

        $typeFacet = $this->createFeatureFacet('Type', 'type', $typeCount, $activeFilters, $activeFiltersQueryString);
        if ($typeFacet) {
            $collection->addFacet($typeFacet);
        }

        $functionFacet = $this->createFeatureFacet('Function', 'function', $functionCount, $activeFilters, $activeFiltersQueryString);
        if ($functionFacet) {
            $collection->addFacet($functionFacet);
        }

        $technologyFacet = $this->createFeatureFacet('Technology', 'technology', $technologyCount, $activeFilters, $activeFiltersQueryString);
        if ($technologyFacet) {
            $collection->addFacet($technologyFacet);
        }

        $compatibilityFacet = $this->createFeatureFacet('Compatibility', 'compatibility', $compatibilityCount, $activeFilters, $activeFiltersQueryString);
        if ($compatibilityFacet) {
            $collection->addFacet($compatibilityFacet);
        }

        return $collection;
    }

    /**
     * Méthode utilitaire pour créer une facette basée sur une feature (feature_values).
     */
    protected function createFeatureFacet(
        string $label,
        string $type,
        array $featureCount,
        array $activeFilters,
        string $activeFiltersQueryString
    ): ?Facet {
        if (empty($featureCount)) {
            return null;
        }

        $facet = new Facet();
        $facet->setLabel($this->l($label))
            ->setType($type)
            ->setDisplayed(true)
            ->setWidgetType('checkbox')
            ->setMultipleSelectionAllowed(true);

        foreach ($featureCount as $valueId => $count) {
            $filterKey = "$type-$valueId";

            $currentFilters = $activeFilters;
            if (in_array($filterKey, $currentFilters)) {
                $nextFilters = array_diff($currentFilters, [$filterKey]);
            } else {
                $nextFilters = $currentFilters;
                $nextFilters[] = $filterKey;
            }
            $encodedFacetsUrl = implode('|', $nextFilters);

            $featureValue = new FeatureValue($valueId, $this->context->language->id);
            if (!Validate::isLoadedObject($featureValue)) {
                continue;
            }

            $filter = new Filter();
            $filter->setLabel($featureValue->value)
                ->setValue($valueId)
                ->setType($type)
                ->setMagnitude($count)
                ->setActive(in_array($filterKey, $activeFilters))
                ->setNextEncodedFacets($encodedFacetsUrl);

            $facet->addFilter($filter);
        }

        return $facet;
    }
}
