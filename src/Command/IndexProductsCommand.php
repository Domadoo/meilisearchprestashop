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
 * @author    Doudeau Adam, Johan Vivien
 * @copyright 2007-2025 Domadoo
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\MeiliSearch\Command;

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\Module\MeiliSearch\Service\ProductIndexer;
use Symfony\Component\Console\Command\Command;
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

        $meiliUrl = \Configuration::get('MEILISEARCHPRESTASHOP_URL');
        $prefix = \Configuration::get('MEILISEARCHPRESTASHOP_PREFIX');

        if (!$meiliUrl) {
            $io->error('MEILISEARCHPRESTASHOP_URL is not configured.');

            return 1;
        }

        $langFilter = $input->getOption('lang');
        $batchSize = max(1, (int) $input->getOption('batch-size'));

        $languages = \Language::getLanguages();

        if ($langFilter) {
            $languages = array_filter($languages, function ($l) use ($langFilter) { return $l['iso_code'] === $langFilter; });
            if (empty($languages)) {
                $io->error(sprintf('Language "%s" not found.', $langFilter));

                return 1;
            }
        }

        $indexer = new ProductIndexer();

        foreach ($languages as $language) {
            $indexName = $prefix . 'products_' . $language['iso_code'];
            $io->section(sprintf('Language: %s (id_lang: %d)', $language['iso_code'], (int) $language['id_lang']));

            $indexer->indexLanguage($language, null, true, $batchSize);

            $io->success(sprintf('Index "%s" updated successfully.', $indexName));
        }

        return 0;
    }
}
