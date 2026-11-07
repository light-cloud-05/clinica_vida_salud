<?php
declare(strict_types=1);

use Clinica\Support\Environment as Env;

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => Env::get('DB_PORT', '5432'),
    'database' => Env::get('DB_DATABASE', 'clinica_vida_salud'),
    'schema' => Env::get('DB_SCHEMA', 'public'),
    'username' => Env::get('DB_USERNAME'),
    'password' => Env::get('DB_PASSWORD'),
    'sslmode' => Env::get('DB_SSLMODE', 'prefer'),
];
