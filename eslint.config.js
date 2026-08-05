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
 * @author    Doudeau Adam, Johan Vivien
 * @copyright 2007-2026 Domadoo
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

import js from '@eslint/js';
import globals from 'globals';

export default [
    js.configs.recommended,
    {
        languageOptions: {
            ecmaVersion: 2021,
            sourceType: 'script',
            globals: {
                ...globals.browser,
                // PrestaShop injected globals (Media::addJsDef)
                Media: 'readonly',
                prestashop: 'readonly',
                searchPlaceholder: 'readonly',
                base_url: 'readonly',
                id_statssearch: 'readonly',
            },
        },
        rules: {
            'no-case-declarations': 'error',
            'no-unused-vars': 'warn',
            'no-undef': 'error',
        },
    },
    {
        // meilisearch_listing.js calls functions defined in meilisearch_facets.js
        files: ['views/js/front/meilisearch_listing.js'],
        languageOptions: {
            globals: {
                meilisearchShowLoader: 'readonly',
                meilisearchHideLoader: 'readonly',
                meilisearchSyncTags: 'readonly',
                meilisearchApplyFilters: 'readonly',
                meilisearchUpdateProducts: 'readonly',
                meilisearchUpdateFacetCounts: 'readonly',
                meilisearchUpdateSortUrls: 'readonly',
            },
        },
    },
];
