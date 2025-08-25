<?php
/**
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License version 3.0
* that is bundled with this package in the file LICENSE.txt
* It is also available through the world-wide-web at this URL:
* https://opensource.org/licenses/AFL-3.0
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade this module to a newer
* versions in the future. If you wish to customize this module for your needs
* please refer to CustomizationPolicy.txt file inside our module for more information.
*
* @author Adam Doudeau
* @copyright Since 2016 Domadoo
*/

namespace PrestaShop\Module\Classes;
use ObjectModel;
use Db;

class MeilisearchStatssearch extends ObjectModel
{
    public $id_statssearch;
    public $query;
    public $nb_results;
    public $id_product;
    public $position;
    public $id_customer;
    public $isAddedToCart;
    public $isOrdered;
    public $id_lang; // Default language ID, can be changed based on your needs
    public $date_add;

    public static $definition = array(
        'table'     => 'meilisearch_statssearch',
        'primary'   => 'id_statssearch',
        'fields'    => array(
            'query' => array('type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 255, 'required' => true),
            'nb_results' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'required' => true),
            'id_product' => array('type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => false),
            'position' => array('type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => false),
            'id_customer' => array('type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => false),
            'isAddedToCart' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool', 'required' => false),
            'isOrdered' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool', 'required' => false),
            'id_lang' => array('type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => true),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDateFormat', 'required' => true),
        )
    );

    public static function getMostSearchedQueries($limit = 10, $dateBegin = null, $dateEnd = null, $id_lang = 0)
    {
        if($dateBegin && $dateEnd) {
            $where = 'AND date_add BETWEEN \'' . pSQL($dateBegin) . '\' AND \'' . pSQL($dateEnd) . '\'';
        }else {
            $where = '';
        }
        if($id_lang && $id_lang > 0) {
            $where .= ' AND id_lang = ' . (int)$id_lang;
        }
        $sql = 'SELECT query as `label`, count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE 1 '.$where.' GROUP BY A.query ORDER BY value DESC LIMIT ' . (int)$limit;
        $results = Db::getInstance()->executeS($sql);

        return $results ? $results : [];
    }

    public static function getMostSearchedEmptyQueries($limit = 10, $dateBegin = null, $dateEnd = null, $id_lang = 0)
    {
        if($dateBegin && $dateEnd) {
            $where = 'AND date_add BETWEEN \'' . pSQL($dateBegin) . '\' AND \'' . pSQL($dateEnd) . '\'';
        }else {
            $where = '';
        }
        if($id_lang && $id_lang > 0) {
            $where .= ' AND id_lang = ' . (int)$id_lang;
        }

        $sql = 'SELECT query as `label`, count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE nb_results = 0 '.$where.' GROUP BY A.query ORDER BY value DESC LIMIT ' . (int)$limit;
        $results = Db::getInstance()->executeS($sql);

        return $results ? $results : [];
    }

    public static function getMostClickedProducts($limit = 10, $dateBegin = null, $dateEnd = null, $id_lang = 0)
    {
        if($dateBegin && $dateEnd) {
            $where = 'AND date_add BETWEEN \'' . pSQL($dateBegin) . '\' AND \'' . pSQL($dateEnd) . '\'';
        }else {
            $where = '';
        }

        if($id_lang && $id_lang > 0) {
            $where .= ' AND A.id_lang = ' . (int)$id_lang;
        }

        $sql = 'SELECT name as `label`, count(*) as `value`, A.id_product 
        FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A
        JOIN ' . _DB_PREFIX_ . 'product_lang P ON (A.id_product = P.id_product)
        WHERE (A.id_product IS NOT NULL AND A.id_product != 0) AND P.id_lang = 1 '.$where.'
        GROUP BY A.id_product
        ORDER BY value DESC 
        LIMIT ' . (int)$limit;
        $results = Db::getInstance()->executeS($sql);

        return $results ? $results : [];
    }

    public function getCTR($dateBegin = null, $dateEnd = null, $id_lang = 0)
    {
        if($dateBegin && $dateEnd) {
            $where = 'AND date_add BETWEEN \'' . pSQL($dateBegin) . '\' AND \'' . pSQL($dateEnd) . '\'';
        }else {
            $where = '';
        }

        if($id_lang && $id_lang > 0) {
            $where .= ' AND id_lang = ' . (int)$id_lang;
        }

        $sql = 'SELECT count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE A.id_product IS NOT NULL AND A.id_product != 0 '.$where;
        $totalClicks = Db::getInstance()->getValue($sql);

        $sql = 'SELECT count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE A.nb_results > 0 '.$where;
        $totalSearches = Db::getInstance()->getValue($sql);

        return ($totalSearches > 0) ? round(($totalClicks / $totalSearches) * 100, 2) : 0;
    }
}
