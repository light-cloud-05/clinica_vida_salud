<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';
use Clinica\Middleware\LocalDiagnostic;
use Clinica\Support\Environment;

$count = 0;
$check = static function (bool $condition, string $label) use (&$count): void {
    if (!$condition) {
        throw new RuntimeException('FALLO: ' . $label);
    }
    ++$count;
    echo "OK: $label\n";
};
$token = str_repeat('a', 32);
$local = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_DIAGNOSTIC_TOKEN' => $token];
$check(LocalDiagnostic::allowed($local, 'local', 'true', $token), 'Diagnóstico local autorizado');
$check(!LocalDiagnostic::allowed($local, 'production', 'true', $token), 'Bloqueado fuera de local');
$check(!LocalDiagnostic::allowed($local, 'local', 'false', $token), 'Desactivado por defecto');
$check(!LocalDiagnostic::allowed(['REMOTE_ADDR' => '192.168.1.2', 'HTTP_X_DIAGNOSTIC_TOKEN' => $token], 'local', 'true', $token), 'Bloqueada red externa');
$check(!LocalDiagnostic::allowed(['REMOTE_ADDR' => '127.0.0.1'], 'local', 'true', $token), 'Bloqueado sin token');
$check(!LocalDiagnostic::allowed($local, 'local', 'true', 'incorrecto'), 'Bloqueado token incorrecto');
$check(!LocalDiagnostic::allowed(['REMOTE_ADDR' => '192.168.1.2', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1', 'HTTP_X_DIAGNOSTIC_TOKEN' => $token], 'local', 'true', $token), 'No confía en cabeceras proxy');
$check(date_default_timezone_get() === 'America/La_Paz', 'Zona horaria');
ob_start();
(new Clinica\Controllers\HomeController())->index();
$html = ob_get_clean();
$check(str_contains($html, 'Clínica Vida Salud') && str_contains($html, 'bootstrap@5'), 'Vista inicial y Bootstrap');
$temporary = tempnam(sys_get_temp_dir(), 'cvs');
try {
    putenv('CVS_TEST_EXISTING=externo');
    file_put_contents($temporary, "# comentario\nCVS_TEST_EXISTING=archivo\nCVS_TEST_VALUE=\"valor con espacios\"\n");
    Environment::load($temporary);
    $check(Environment::get('CVS_TEST_EXISTING') === 'externo', 'Entorno externo tiene prioridad');
    $check(Environment::get('CVS_TEST_VALUE') === 'valor con espacios', 'Lectura de valores entre comillas');
} finally {
    unlink($temporary);
    putenv('CVS_TEST_EXISTING');
    putenv('CVS_TEST_VALUE');
}
echo "$count comprobaciones pasaron. No se consultó la base de datos.\n";
