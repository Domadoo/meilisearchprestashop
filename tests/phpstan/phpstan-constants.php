<?php

/**
 * Constantes pour PHPStan (bootstrap / analyse statique).
 *
 * @author    Domadoo (Johan VIVIEN)
 * @copyright Since 2016 Domadoo
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
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
