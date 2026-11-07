<?php
declare(strict_types=1);
require __DIR__ . '/demo-safety.php';
require dirname(__DIR__) . '/backend/bootstrap.php';
$config = require dirname(__DIR__) . '/backend/config/database.php';
if (getenv('APP_ENV') !== 'production' || getenv('DEMO_SEED_TARGET') !== 'render'
    || getenv('DEMO_RENDER_RESOURCE') !== 'clinica-vida-salud-db'
    || getenv('DEMO_RENDER_DATABASE_NAME') !== 'clinica_vida_salud_db') {
    throw new RuntimeException('Destino no autorizado.');
}
$pdo = Clinica\Repositories\Connection::readOnly($config);
demoRenderPreflight($pdo, $config);
foreach ($pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
    if ((int)$pdo->query('SELECT count(*) FROM public."' . $table . '"')->fetchColumn() !== 0) {
        throw new RuntimeException('Existen datos previos.');
    }
}
fwrite(STDOUT, "Todas las tablas sin datos previos: OK\n");
