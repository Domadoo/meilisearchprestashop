<?php

$config = new PrestaShop\CodingStandards\CsFixer\Config();

/** @var \Symfony\Component\Finder\Finder $finder */
$finder = $config->setUsingCache(true)->getFinder();
$finder->in(__DIR__)->exclude([
    'var',
    'vendor',
    'tests',
]);
// ParallelConfigFactory::detect() n'existe que sur php-cs-fixer >= 3.48 : garde de compat.
$parallelFactory = 'PhpCsFixer\Runner\Parallel\ParallelConfigFactory';
if (method_exists($parallelFactory, 'detect')) {
    $config->setParallelConfig($parallelFactory::detect());
}

return $config;
