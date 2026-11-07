<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
require dirname(__DIR__) . '/vendor/autoload.php';
\Clinica\Support\Environment::load(dirname(__DIR__) . '/.env');
date_default_timezone_set('America/La_Paz');
