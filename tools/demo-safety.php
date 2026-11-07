<?php
declare(strict_types=1);

// CLI only: never emit exception messages, traces, arguments or connection strings.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, "Operación demo detenida. Revisar cliente, destino, esquema, vacío y credenciales; detalles privados suprimidos.\n");
    exit(1);
});

function demoRenderPreflight(PDO $pdo, array $config): void
{
    if ($config['database'] !== 'clinica_vida_salud_db'
        || $config['host'] !== getenv('DEMO_RENDER_DATABASE_HOST')
        || !preg_match('/^dpg-[a-z0-9-]+\.[a-z0-9-]+-postgres\.render\.com$/D', $config['host'])
        || !in_array($config['sslmode'], ['require', 'verify-ca', 'verify-full'], true)) {
        throw new RuntimeException('Destino Render inválido.');
    }
    $identity = $pdo->query("SELECT current_database() AS db, current_setting('server_version_num')::int AS version, (SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls")->fetch();
    if (!$identity || $identity['db'] !== 'clinica_vida_salud_db'
        || (int)$identity['version'] < 180000 || (int)$identity['version'] >= 190000
        || !in_array($identity['tls'], [true, 't', 1, '1'], true)) {
        throw new RuntimeException('Identidad, versión o TLS incorrectos.');
    }
    $expected = ['bloqueos_horario', 'citas', 'comprobantes_pago', 'especialidades',
        'historial_cambios_cita', 'horarios_atencion', 'medico_especialidad', 'medicos',
        'notas_medicas', 'notificaciones', 'pacientes', 'pagos', 'permisos',
        'recuperacion_contrasena', 'registros_auditoria', 'reservas_provisionales',
        'rol_permiso', 'roles', 'transacciones_pago', 'tratamientos_recetas', 'usuarios'];
    $actual = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
    if ($actual !== $expected) { throw new RuntimeException('Las 21 tablas no coinciden.'); }
    fwrite(STDOUT, "Destino Render, PostgreSQL 18, TLS y 21 tablas esperadas: OK\n");
}
