<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/demo-safety.php';
require dirname(__DIR__) . '/backend/bootstrap.php';

use Clinica\Repositories\Connection;
use Clinica\Support\AuthFactory;
use Clinica\Support\Environment;

$config = require dirname(__DIR__) . '/backend/config/database.php';
if (Environment::get('DEMO_SEED_TARGET') !== 'render'
    || Environment::get('APP_ENV') !== 'production'
    || Environment::get('DATABASE_URL') === ''
    || Environment::get('DEMO_RENDER_RESOURCE') !== 'clinica-vida-salud-db'
    || Environment::get('DEMO_RENDER_DATABASE_NAME') !== $config['database']
    || Environment::get('DEMO_RENDER_DATABASE_HOST') !== $config['host']) {
    throw new RuntimeException('Destino no autorizado: se requiere la base Render clinica-vida-salud-db.');
}
$pdo = Connection::accountMaintenance($config);
if (Environment::get('DEMO_SEED_TARGET') === 'render') { demoRenderPreflight($pdo, $config); }
$users = [
    ['demo_admin', 'VERIFY_ADMIN_PASSWORD', 'Administrador'],
    ['demo_recepcion', 'VERIFY_RECEPTION_PASSWORD', 'Recepcionista'],
    ['demo_medico', 'VERIFY_DOCTOR_PASSWORD', 'Medico'],
    ['demo_paciente', 'VERIFY_PATIENT_PASSWORD', 'Paciente'],
];

if ((int)$pdo->query('SELECT count(*) FROM public.usuarios')->fetchColumn() !== 4) {
    throw new RuntimeException('Se requieren exactamente cuatro cuentas demo.');
}
$auth = AuthFactory::service();
foreach ($users as [$username, $variable, $role]) {
    $password = getenv($variable);
    if (!is_string($password) || $password === '') {
        throw new RuntimeException('Falta la variable ' . $variable . '.');
    }
    $identity = $auth->authenticate($username, $password);
    if (!$identity || $identity['role'] !== $role || $identity['permissions'] === []) {
        throw new RuntimeException('Login demo falló para ' . $role . '.');
    }
    fwrite(STDOUT, $role . ": OK\n");
}

$tables = (int)$pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_schema='public'")->fetchColumn();
if ($tables !== 21) {
    throw new RuntimeException('Se esperaban 21 tablas; se encontraron ' . $tables . '.');
}
fwrite(STDOUT, "21 tablas: OK\n");
