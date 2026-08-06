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
 * @author    Adam Doudeau
 * @copyright Since 2016 Domadoo
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */
declare(strict_types=1);

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

if (!defined('_PS_VERSION_')) {
    exit;
}

class MeilisearchStatssearch extends \ObjectModel
{
    public $id_statssearch;
    public $query;
    public $nb_results;
    public $id_product;
    public $position;
    public $id_customer;
    public $id_cart;
    public $is_ordered;
    /** @var int */
    public $id_lang; // Default language ID, can be changed based on your needs
    public $date_add;

    /** @var array */
    public static $definition = [
        'table' => 'meilisearch_statssearch',
        'primary' => 'id_statssearch',
        'fields' => [
            'query' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 255, 'required' => true],
            'nb_results' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'required' => true],
            'id_product' => ['type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => false],
            'position' => ['type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => false],
            'id_customer' => ['type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => false],
            'id_cart' => ['type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => false],
            'is_ordered' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool', 'required' => false],
            'id_lang' => ['type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDateFormat', 'required' => true],
        ],
    ];

    public static function getMostSearchedQueries($limit = 10, $dateBegin = null, $dateEnd = null, $id_lang = 0)
    {
        if ($dateBegin && $dateEnd) {
            $where = 'AND date_add BETWEEN \'' . pSQL($dateBegin) . '\' AND \'' . pSQL($dateEnd) . '\'';
        } else {
            $where = '';
        }
        if ($id_lang && $id_lang > 0) {
            $where .= ' AND id_lang = ' . (int) $id_lang;
        }
        $sql = 'SELECT query as `label`, count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE 1 ' . $where . ' GROUP BY A.query ORDER BY value DESC LIMIT ' . (int) $limit;
        $results = \Db::getInstance()->executeS($sql);

        return $results ? $results : [];
    }

    /**
     * Suggestions de recherche par préfixe (autocomplétion) : requêtes passées les plus
     * fréquentes commençant par $prefix, hors requêtes à 0 résultat.
     *
     * @return string[] liste de requêtes (label)
     */
    public static function getSuggestionsByPrefix(string $prefix, int $limit = 5, int $id_lang = 0): array
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return [];
        }

        // Échappe les wildcards LIKE (\ % _) avant pSQL
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);

        $where = '';
        if ($id_lang && $id_lang > 0) {
            $where .= ' AND id_lang = ' . (int) $id_lang;
        }

        $sql = 'SELECT query AS `label`, count(*) AS `value`
            FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A
            WHERE nb_results > 0 AND query LIKE \'%' . pSQL($escaped) . '%\' ' . $where . '
            GROUP BY A.query ORDER BY value DESC LIMIT ' . (int) $limit;
        $results = \Db::getInstance()->executeS($sql);

        return $results ? array_column($results, 'label') : [];
    }

    public static function getMostSearchedEmptyQueries($limit = 10, $dateBegin = null, $dateEnd = null, $id_lang = 0)
    {
        if ($dateBegin && $dateEnd) {
            $where = 'AND date_add BETWEEN \'' . pSQL($dateBegin) . '\' AND \'' . pSQL($dateEnd) . '\'';
        } else {
            $where = '';
        }
        if ($id_lang && $id_lang > 0) {
            $where .= ' AND id_lang = ' . (int) $id_lang;
        }

        $sql = 'SELECT query as `label`, count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE nb_results = 0 ' . $where . ' GROUP BY A.query ORDER BY value DESC LIMIT ' . (int) $limit;
        $results = \Db::getInstance()->executeS($sql);

        return $results ? $results : [];
    }

    public static function getMostClickedProducts($limit = 10, $dateBegin = null, $dateEnd = null, $id_lang = 0)
    {
        if ($dateBegin && $dateEnd) {
            $where = 'AND date_add BETWEEN \'' . pSQL($dateBegin) . '\' AND \'' . pSQL($dateEnd) . '\'';
        } else {
            $where = '';
        }

        if ($id_lang && $id_lang > 0) {
            $where .= ' AND A.id_lang = ' . (int) $id_lang;
        }

        $sql = 'SELECT name as `label`, count(*) as `value`, A.id_product 
        FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A
        JOIN ' . _DB_PREFIX_ . 'product_lang P ON (A.id_product = P.id_product)
        WHERE (A.id_product IS NOT NULL AND A.id_product != 0) AND P.id_lang = 1 ' . $where . '
        GROUP BY A.id_product
        ORDER BY value DESC 
        LIMIT ' . (int) $limit;
        $results = \Db::getInstance()->executeS($sql);

        return $results ? $results : [];
    }

    public static function getCTR($dateBegin = null, $dateEnd = null, $id_lang = 0)
    {
        if ($dateBegin && $dateEnd) {
            $where = 'AND date_add BETWEEN \'' . pSQL($dateBegin) . '\' AND \'' . pSQL($dateEnd) . '\'';
        } else {
            $where = '';
        }

        if ($id_lang && $id_lang > 0) {
            $where .= ' AND id_lang = ' . (int) $id_lang;
        }

        $sql = 'SELECT count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE A.id_product IS NOT NULL AND A.id_product != 0 ' . $where;
        $totalClicks = \Db::getInstance()->getValue($sql);

        $sql = 'SELECT count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE A.nb_results > 0 ' . $where;
        $totalSearches = \Db::getInstance()->getValue($sql);

        return ($totalSearches > 0) ? round(((int) $totalClicks / (int) $totalSearches) * 100, 2) : 0;
    }

    public static function getAddedToCartRate($dateBegin = null, $dateEnd = null, $id_lang = 0)
    {
        if ($dateBegin && $dateEnd) {
            $where = 'AND date_add BETWEEN \'' . pSQL($dateBegin) . '\' AND \'' . pSQL($dateEnd) . '\'';
        } else {
            $where = '';
        }

        if ($id_lang && $id_lang > 0) {
            $where .= ' AND id_lang = ' . (int) $id_lang;
        }

        $sql = 'SELECT count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE A.id_cart != 0  ' . $where;
        $totalAddedToCart = \Db::getInstance()->getValue($sql);

        $sql = 'SELECT count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE A.nb_results > 0 ' . $where;
        $totalSearches = \Db::getInstance()->getValue($sql);

        return ($totalSearches > 0) ? round(((int) $totalAddedToCart / (int) $totalSearches) * 100, 2) : 0;
    }

    public static function getConversionRate($dateBegin = null, $dateEnd = null, $id_lang = 0)
    {
        if ($dateBegin && $dateEnd) {
            $where = 'AND date_add BETWEEN \'' . pSQL($dateBegin) . '\' AND \'' . pSQL($dateEnd) . '\'';
        } else {
            $where = '';
        }

        if ($id_lang && $id_lang > 0) {
            $where .= ' AND id_lang = ' . (int) $id_lang;
        }

        $sql = 'SELECT count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE A.is_ordered = 1  ' . $where;
        $totalOrdered = \Db::getInstance()->getValue($sql);

        $sql = 'SELECT count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE A.nb_results > 0 ' . $where;
        $totalSearches = \Db::getInstance()->getValue($sql);

        return ($totalSearches > 0) ? round(((int) $totalOrdered / (int) $totalSearches) * 100, 2) : 0;
    }

    public static function getSearchesByIdCart($id_cart)
    {
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE A.id_cart = ' . (int) $id_cart;
        $results = \Db::getInstance()->executeS($sql);

        return $results ? $results : [];
    }

    public function getTotalSearches($dateBegin = null, $dateEnd = null, $id_lang = 0)
    {
        if ($dateBegin && $dateEnd) {
            $where = 'AND date_add BETWEEN \'' . pSQL($dateBegin) . '\' AND \'' . pSQL($dateEnd) . '\'';
        } else {
            $where = '';
        }

        if ($id_lang && $id_lang > 0) {
            $where .= ' AND id_lang = ' . (int) $id_lang;
        }

        $sql = 'SELECT count(*) as `value` FROM ' . _DB_PREFIX_ . 'meilisearch_statssearch A WHERE 1  ' . $where;
        $totalSearches = \Db::getInstance()->getValue($sql);

        return $totalSearches ? $totalSearches : 0;
    }
}
