<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Tests\Prestaflow\Suites\MeilisearchSuite;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();

$suite = new MeilisearchSuite();
$suite->init();
$suite->run(cli: true);
