<?php

declare(strict_types=1);

namespace PrestaShop\Module\MeiliSearch\Command;

use Configuration;
use Db;
use Language;
use Shop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class IndexProductsCommand extends Command
{
    protected static $defaultName = 'meilisearch:index-products';

    protected function configure(): void
    {
        $this
            ->setDescription('Index all active products into Meilisearch')
            ->setHelp('This command indexes all active PrestaShop products into Meilisearch for each language.')
            ->addOption(
                'lang',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Only index a specific language (ISO code, e.g. "fr")'
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Number of products per batch sent to Meilisearch',
                200
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Meilisearch — Product indexation');

        $meiliUrl = Configuration::get('MEILISEARCHPRESTASHOP_URL');
        $prefix = Configuration::get('MEILISEARCHPRESTASHOP_PREFIX');

        if (!$meiliUrl) {
            $io->error('MEILISEARCHPRESTASHOP_URL is not configured.');
            return Command::FAILURE;
        }

        $langFilter = $input->getOption('lang');
        $batchSize = max(1, (int) $input->getOption('batch-size'));

        $languages = Language::getLanguages();

        if ($langFilter) {
            $languages = array_filter($languages, fn($l) => $l['iso_code'] === $langFilter);
            if (empty($languages)) {
                $io->error(sprintf('Language "%s" not found.', $langFilter));
                return Command::FAILURE;
            }
        }

        $typeMap = [
            'id_product'                => 'int',
            'id_supplier'               => 'int',
            'id_manufacturer'           => 'int',
            'id_category_default'       => 'int',
            'id_shop_default'           => 'int',
            'id_tax_rules_group'        => 'int',
            'on_sale'                   => 'bool',
            'online_only'               => 'bool',
            'low_stock_alert'           => 'bool',
            'quantity'                  => 'int',
            'minimal_quantity'          => 'int',
            'price'                     => 'float',
            'wholesale_price'           => 'float',
            'unit_price_ratio'          => 'float',
            'additional_shipping_cost'  => 'float',
            'width'                     => 'float',
            'height'                    => 'float',
            'depth'                     => 'float',
            'weight'                    => 'float',
            'out_of_stock'              => 'int',
            'additional_delivery_times' => 'bool',
            'quantity_discount'         => 'bool',
            'customizable'              => 'bool',
            'uploadable_files'          => 'bool',
            'text_fields'               => 'bool',
            'active'                    => 'bool',
            'id_type_redirected'        => 'int',
            'available_for_order'       => 'bool',
            'show_condition'            => 'bool',
            'show_price'                => 'bool',
            'indexed'                   => 'bool',
            'cache_is_pack'             => 'bool',
            'cache_has_attachments'     => 'bool',
            'is_virtual'                => 'bool',
            'cache_default_attribute'   => 'int',
            'advanced_stock_management' => 'bool',
            'pack_stock_type'           => 'int',
            'state'                     => 'int',
            'atoosync'                  => 'bool',
            'id_shop'                   => 'int',
            'final_price'               => 'float',
            'id_lang'                   => 'int',
        ];

        foreach ($languages as $language) {
            $idLang = (int) $language['id_lang'];
            $isoCode = $language['iso_code'];
            $indexName = $prefix . 'products_' . $isoCode;

            $io->section(sprintf('Language: %s (id_lang: %d)', $isoCode, $idLang));

            $sql = '
                SELECT p.*, product_shop.*, pl.*,
                    m.`name` AS manufacturer_name,
                    s.`name` AS supplier_name
                FROM `' . _DB_PREFIX_ . 'product` p
                ' . Shop::addSqlAssociation('product', 'p') . '
                LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                    ON (p.`id_product` = pl.`id_product` ' . Shop::addSqlRestrictionOnLang('pl') . ')
                LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
                    ON (m.`id_manufacturer` = p.`id_manufacturer`)
                LEFT JOIN `' . _DB_PREFIX_ . 'supplier` s
                    ON (s.`id_supplier` = p.`id_supplier`)
                WHERE pl.`id_lang` = ' . $idLang . '
                AND product_shop.`active` = 1
            ';

            $products = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

            if (empty($products)) {
                $io->warning(sprintf('No active products found for language "%s".', $isoCode));
                continue;
            }

            $io->text(sprintf('%d products found.', count($products)));

            // Features
            $productIds = array_column($products, 'id_product');
            $productIdsStr = implode(',', array_map('intval', $productIds));

            $featuresSql = '
                SELECT id_product, id_feature, id_feature_value
                FROM `' . _DB_PREFIX_ . 'feature_product`
                WHERE id_product IN (' . $productIdsStr . ')
            ';
            $featureResults = Db::getInstance()->executeS($featuresSql);

            $productFeatureValues = [];
            foreach ($featureResults as $row) {
                $idProduct = (int) $row['id_product'];
                $featureKey = (int) $row['id_feature'] . '-' . (int) $row['id_feature_value'];
                $productFeatureValues[$idProduct][] = $featureKey;
            }

            // Cast types and add feature_values
            foreach ($products as &$product) {
                foreach ($typeMap as $field => $type) {
                    if (array_key_exists($field, $product) && $product[$field] !== null) {
                        switch ($type) {
                            case 'int':   $product[$field] = (int)   $product[$field]; break;
                            case 'float': $product[$field] = (float) $product[$field]; break;
                            case 'bool':  $product[$field] = (bool)  $product[$field]; break;
                        }
                    }
                }
                $product['feature_values'] = $productFeatureValues[$product['id_product']] ?? [];
            }
            unset($product);

            // Create index
            $this->requestMeilisearch(
                $meiliUrl . 'indexes',
                json_encode(['uid' => $indexName, 'primaryKey' => 'id_product'])
            );

            // Send documents in batches
            $chunks = array_chunk($products, $batchSize);
            $progressBar = new ProgressBar($output, count($chunks));
            $progressBar->start();

            foreach ($chunks as $chunk) {
                $this->requestMeilisearch(
                    $meiliUrl . 'indexes/' . $indexName . '/documents',
                    json_encode($chunk)
                );
                $progressBar->advance();
            }

            $progressBar->finish();
            $output->writeln('');

            // Settings
            $this->requestMeilisearch(
                $meiliUrl . 'indexes/' . $indexName . '/settings/sortable-attributes',
                json_encode(['name', 'price']),
                'PUT'
            );
            $this->requestMeilisearch(
                $meiliUrl . 'indexes/' . $indexName . '/settings/ranking-rules',
                json_encode(['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness']),
                'PUT'
            );
            $this->requestMeilisearch(
                $meiliUrl . 'indexes/' . $indexName . '/settings/filterable-attributes',
                json_encode(['id_manufacturer', 'out_of_stock', 'condition', 'id_category_default', 'quantity', 'feature_values', 'visibility', 'available_for_order']),
                'PUT'
            );

            $io->success(sprintf('Index "%s" updated successfully.', $indexName));
        }

        return Command::SUCCESS;
    }

    private function requestMeilisearch(string $url, string $payload = null, string $method = null): void
    {
        $authorization = 'Authorization: Bearer ' . Configuration::get('MEILISEARCHPRESTASHOP_KEY');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 120,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', $authorization],
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        if ($method !== null) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        curl_exec($ch);
        curl_close($ch);
    }
}
