<?php
declare(strict_types=1);

use Clinica\Support\Environment as Env;

$config = [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => Env::get('DB_PORT', '5432'),
    'database' => Env::get('DB_DATABASE', 'clinica_vida_salud'),
    'schema' => Env::get('DB_SCHEMA', 'public'),
    'username' => Env::get('DB_USERNAME'),
    'password' => Env::get('DB_PASSWORD'),
    'sslmode' => Env::get('DB_SSLMODE', 'prefer'),
];

$databaseUrl = Env::get('DATABASE_URL');
if ($databaseUrl !== '') {
    $parts = parse_url($databaseUrl);
    if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['postgres', 'postgresql'], true)
        || !isset($parts['host'], $parts['path']) || trim($parts['path'], '/') === '') {
        throw new RuntimeException('DATABASE_URL inválida.');
    }

    parse_str($parts['query'] ?? '', $query);
    $config['host'] = $parts['host'];
    $config['port'] = (string)($parts['port'] ?? 5432);
    $config['database'] = rawurldecode(trim($parts['path'], '/'));
    $config['username'] = rawurldecode($parts['user'] ?? '');
    $config['password'] = rawurldecode($parts['pass'] ?? '');
    $config['sslmode'] = (string)($query['sslmode'] ?? Env::get('DB_SSLMODE', 'require'));
}

return $config;
