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
    public $date_add;

    public static $definition = array(
        'table'     => 'meilisearch_statssearch',
        'primary'   => 'id_statssearch',
        'fields'    => array(
            'query' => array('type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 255, 'required' => true),
            'nb_results' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'required' => true),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDateFormat', 'required' => true),
        )
    );

    public static function getMostSearchedQueries($limit = 10)
    {
        $sql = 'SELECT query as `label`, count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A GROUP BY A.query ORDER BY value DESC LIMIT ' . (int)$limit;
        $results = Db::getInstance()->executeS($sql);

        return $results ? $results : [];
    }

    public static function getMostSearchedEmptyQueries($limit = 10)
    {
        $sql = 'SELECT query as `label`, count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE nb_results = 0 GROUP BY A.query ORDER BY value DESC LIMIT ' . (int)$limit;
        $results = Db::getInstance()->executeS($sql);

        return $results ? $results : [];
    }
}
