<?php
/**
 * 2007-2026 PrestaShop
 *
 * @author    Domadoo (Johan Vivien)
 * @copyright 2007-2026 Domadoo
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
 */

namespace {
    /*
     * Stubs PrestaShop pour PHPStan (classes / fonctions non chargées en analyse).
     * @author Domadoo (Johan VIVIEN)
     * @copyright Since 2016 Domadoo
     * @license https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
     */
    if (!defined('_PS_VERSION_')) {
        exit;
    }
    if (!defined('_DB_PREFIX_')) {
        define('_DB_PREFIX_', 'ps_');
    }
    if (!defined('_PS_MAIL_DIR_')) {
        define('_PS_MAIL_DIR_', '/mails/');
    }
    if (!defined('_PS_MODULE_DIR_')) {
        define('_PS_MODULE_DIR_', '/var/www/html/modules/');
    }
    /**
     * @property string|null $type_client
     * @property string|null $firstname
     * @property string|null $lastname
     * @property string|null $email
     *
     * @param int|null $id
     */
    /**
     * @property array|null $all_groupsCustomer
     */
    class Customer
    {
        /**
         * @param int|null $id
         */
        public function __construct($id = null)
        {
        }
    }

    class Cookie
    {
        /** @var int|null */
        public $id_employee;
        /** @var int|null */
        public $meilisearch_id;
        /** @var string|null */
        public $meilisearch_query;
        /** @var int|null */
        public $meilisearch_product_id;

        /**
         * @param string $name
         * @param string|null $path
         */
        public function __construct($name, $path = null)
        {
        }
    }

    class Db
    {
        /**
         * @return Db
         */
        public static function getInstance()
        {
            return new self();
        }

        /**
         * @param string $sql
         *
         * @return string|int|false
         */
        public function getValue($sql)
        {
        }

        /**
         * @param string $sql
         *
         * @return array|false
         */
        public function getRow($sql)
        {
        }

        /**
         * @param string $sql
         *
         * @return array|false
         */
        public function executeS($sql)
        {
        }

        /**
         * @param string $sql
         *
         * @return bool
         */
        public function execute($sql)
        {
        }
    }

    if (!function_exists('pSQL')) {
        /**
         * @param string $string
         * @param bool $htmlOk
         *
         * @return string
         */
        function pSQL($string, $htmlOk = false)
        {
            return $string;
        }
    }
    if (!function_exists('bqSQL')) {
        /**
         * @param string $string
         *
         * @return string
         */
        function bqSQL($string)
        {
            return $string;
        }
    }

    /**
     * @property int $id
     * @property int $id_customer
     * @property int $id_carrier
     * @property int $current_state
     * @property string $reference
     * @property int $id_shop
     *
     * @method Language getAssociatedLanguage()
     * @method int getIdOrderCarrier()
     * @method array getCartProducts()
     */
    class Order
    {
        /**
         * @param int|null $id
         */
        public function __construct($id = null)
        {
        }
    }

    /**
     * Classe de base PrestaShop pour les modèles (stub PHPStan).
     *
     * @property array $definition
     */
    abstract class ObjectModel
    {
        public const TYPE_INT = 1;
        public const TYPE_STRING = 2;
        public const TYPE_FLOAT = 3;
        public const TYPE_DATE = 4;
        public const TYPE_HTML = 5;
        public const TYPE_NOTHING = 6;
        public const TYPE_SQL = 7;
        public const TYPE_BOOL = 8;

        /**
         * @param int|null $id
         * @param int|null $idLang
         * @param int|null $idShop
         */
        public function __construct($id = null, $idLang = null, $idShop = null)
        {
        }

        /**
         * @param bool $autoDate
         * @param bool $nullValues
         *
         * @return bool
         */
        public function add($autoDate = true, $nullValues = false)
        {
        }

        /**
         * @param bool $nullValues
         *
         * @return bool
         */
        public function update($nullValues = false)
        {
        }

        /**
         * @return bool
         */
        public function delete()
        {
        }
    }

    /**
     * @property int $id
     * @property string|array $name
     * @property int $deleted
     * @property int $send_email
     * @property string|null $template
     */
    class OrderState
    {
        /**
         * @param int|null $id
         * @param int|null $idLang
         */
        public function __construct($id = null, $idLang = null)
        {
        }
    }

    /**
     * @property int $id_order_history
     * @property int $id_order
     * @property int $id_order_state
     * @property int $id_employee
     *
     * @method bool addWithemail(bool $templateVars)
     */
    class OrderHistory
    {
        /**
         * @param int|null $id
         */
        public function __construct($id = null)
        {
        }

        /**
         * @param int $newOrderState
         * @param int $idOrder
         * @param bool $useExistingPayment
         *
         * @return bool
         */
        public function changeIdOrderState($newOrderState, $idOrder, $useExistingPayment = false)
        {
        }

        /**
         * @param bool $autodate
         * @param bool $nullValues
         *
         * @return bool
         */
        public function add($autodate = true, $nullValues = false)
        {
        }
    }

    class Validate
    {
        /**
         * @param object $object
         *
         * @return bool
         */
        public static function isLoadedObject($object)
        {
        }

        /**
         * @param string $url
         *
         * @return bool
         */
        public static function isUrl($url)
        {
        }

        /**
         * @param string $email
         *
         * @return bool
         */
        public static function isEmail($email)
        {
        }
    }

    class PrestaShopLogger
    {
        /**
         * @param string $message
         * @param int $severity
         * @param int|null $errorCode
         * @param string|null $objectType
         * @param int|null $objectId
         * @param bool $allowDuplicate
         * @param int|null $idEmployee
         *
         * @return bool
         */
        public static function addLog($message, $severity, $errorCode, $objectType, $objectId, $allowDuplicate, $idEmployee = null)
        {
        }
    }

    class Configuration
    {
        /**
         * @param string $key
         * @param int|null $idLang
         *
         * @return mixed
         */
        public static function get($key, $idLang = null)
        {
        }

        /**
         * @param string $key
         * @param mixed $values
         * @param bool $html
         *
         * @return bool
         */
        public static function updateValue($key, $values, $html = false)
        {
        }
    }

    class WebserviceOutputBuilderCore
    {
    }

    /**
     * @property string $method
     *
     * @method bool getOutputEnabled()
     */
    class WebserviceRequestCore
    {
    }

    interface WebserviceSpecificManagementInterface
    {
    }

    /**
     * @param string $message
     * @param int $code
     */
    class WebserviceException extends Exception
    {
    }

    class OrderCarrier
    {
        /**
         * @param int|null $id
         */
        public function __construct($id = null)
        {
        }

        /** @var string|null */
        public $tracking_number;
    }

    /**
     * @property string|null $url
     */
    class Carrier
    {
        /**
         * @param int|null $id
         * @param int|null $idLang
         */
        public function __construct($id = null, $idLang = null)
        {
        }
    }

    class Context
    {
        /**
         * @return Context
         */
        public static function getContext()
        {
        }

        /**
         * @return Translator
         */
        public function getTranslator()
        {
        }
    }

    class Translator
    {
        /**
         * @param string $id
         * @param array $parameters
         * @param string|null $domain
         *
         * @return string
         */
        public function trans($id, $parameters = [], $domain = null)
        {
        }
    }

    class Mail
    {
        /**
         * @param int $idLang
         * @param string $template
         * @param string $subject
         * @param array $templateVars
         * @param string $to
         * @param string|null $toName
         * @param string|null $from
         * @param string|null $fromName
         * @param string|null $fileAttachment
         * @param string|null $modeSmtp
         * @param string|null $templatePath
         * @param bool $die
         * @param int|null $idShop
         *
         * @return bool
         */
        public static function Send($idLang, $template, $subject, $templateVars, $to, $toName = null, $from = null, $fromName = null, $fileAttachment = null, $modeSmtp = null, $templatePath = null, $die = false, $idShop = null)
        {
        }

        /**
         * @param string $string
         * @param int $idLang
         *
         * @return string
         */
        public static function l($string, $idLang)
        {
        }
    }

    class Tools
    {
        /**
         * @param mixed $data
         *
         * @return string|false
         */
        public static function jsonEncode($data)
        {
        }

        /**
         * @param string $key
         * @param mixed $default
         *
         * @return mixed
         */
        public static function getValue($key, $default = false)
        {
        }

        /**
         * @param string $tab
         *
         * @return string
         */
        public static function getAdminTokenLite($tab)
        {
            return '';
        }

        /**
         * @param string $submit
         *
         * @return bool
         */
        public static function isSubmit($submit)
        {
            return false;
        }

        /**
         * @param string $url
         * @param string|null $baseUri
         * @param bool $headers
         *
         * @return void
         */
        public static function redirectAdmin($url, $baseUri = null, $headers = false)
        {
        }

        /**
         * @param string $json
         * @param bool $assoc
         *
         * @return mixed
         */
        public static function jsonDecode($json, $assoc = false)
        {
        }

        /**
         * @param string $str
         * @param int $start
         * @param int|null $length
         *
         * @return string
         */
        public static function substr($str, $start, $length = null)
        {
        }

        /**
         * @param string $str
         *
         * @return int
         */
        public static function strlen($str)
        {
        }
    }

    class Language
    {
        /**
         * @return int
         */
        public function getId()
        {
        }

        /**
         * @param bool $active
         * @param bool $idShop
         *
         * @return array
         */
        public static function getLanguages($active = true, $idShop = false)
        {
            return [];
        }
    }

    /**
     * @property bool $bootstrap
     * @property bool|string $display
     * @property string $meta_title
     * @property string $table
     * @property string $toolbar_title
     * @property string $controller_name
     * @property string $identifier
     * @property string $page_header_toolbar_title
     * @property string $content
     * @property object $context
     * @property object $module
     * @property bool $list_no_link
     */
    abstract class ModuleAdminController
    {
        public function __construct()
        {
        }

        /**
         * @param string $string
         * @param string|null $specific
         * @param string|null $class
         *
         * @return string
         */
        public function l($string, $specific = null, $class = null)
        {
            return $string;
        }

        public function initContent()
        {
        }

        public function postProcess()
        {
        }
    }

    /**
     * @property bool $show_toolbar
     * @property string $table
     * @property object $module
     * @property int $default_form_language
     * @property bool $allow_employee_form_lang
     * @property string $identifier
     * @property string $submit_action
     * @property string $currentIndex
     * @property string $token
     * @property array $tpl_vars
     */
    class HelperForm
    {
        /**
         * @param array $fieldsForm
         *
         * @return string
         */
        public function generateForm($fieldsForm = [])
        {
            return '';
        }
    }

    /**
     * @property object $module
     * @property string $list_id
     * @property string $token
     * @property string $currentIndex
     * @property string $table
     * @property string $identifier
     * @property string $title
     * @property string $orderBy
     * @property string $orderWay
     * @property bool $show_toolbar
     * @property bool $simple_header
     * @property bool $no_link
     * @property int $page
     * @property int $_default_pagination
     * @property array $_pagination
     * @property int $listTotal
     * @property string $shopLinkType
     */
    class HelperList
    {
        /**
         * @param array $list
         * @param array|null $fieldsList
         *
         * @return string
         */
        public function generateList($list = [], $fieldsList = null)
        {
            return '';
        }
    }

    class Employee
    {
        /**
         * @param bool $activeOnly
         *
         * @return array
         */
        public static function getEmployees($activeOnly = true)
        {
            return [];
        }
    }

    /**
     * @property string $name
     * @property string $tab
     * @property string $version
     * @property string $author
     * @property bool|int $need_instance
     * @property bool $bootstrap
     * @property string $displayName
     * @property string $description
     * @property string $confirmUninstall
     * @property array $ps_versions_compliancy
     * @property object $context
     */
    abstract class Module
    {
        public function __construct()
        {
        }

        /**
         * @param string $string
         * @param string|null $specific
         * @param string|null $class
         *
         * @return string
         */
        public function l($string, $specific = null, $class = null)
        {
            return $string;
        }

        /**
         * @param string $hookName
         *
         * @return bool
         */
        public function registerHook($hookName)
        {
            return true;
        }

        public function install()
        {
            return true;
        }

        public function uninstall()
        {
            return true;
        }

        /**
         * @param string $url
         * @param string|null $payload
         * @param bool|string $request
         *
         * @return mixed
         */
        public function requestCurl($url, $payload = null, $request = false)
        {
        }
    }

    /**
     * @property bool $active
     * @property string $class_name
     * @property array $name
     * @property string $icon
     * @property int $id_parent
     * @property string $module
     */
    class Tab
    {
        /**
         * @param string $className
         *
         * @return int|false
         */
        public static function getIdFromClassName($className)
        {
            return 0;
        }

        /**
         * @param string $moduleName
         *
         * @return array
         */
        public static function getCollectionFromModule($moduleName)
        {
            return [];
        }

        /** @return bool */
        public function add()
        {
            return true;
        }
    }

    abstract class ModuleFrontController
    {
        public function __construct()
        {
        }
    }
}
