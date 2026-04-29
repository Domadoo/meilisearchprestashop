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
