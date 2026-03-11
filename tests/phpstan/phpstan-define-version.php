<?php

/**
 * Définit _PS_VERSION_ pour le bootstrap PHPStan si absent (contexte module seul).
 *
 * @author    Domadoo (Johan VIVIEN)
 * @copyright Since 2016 Domadoo
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.0.0');
}
