<?php
declare(strict_types=1);

// Ninguna ejecución automática, ni desde navegador, ni como script de Composer.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc === 2 && $argv[1] === '--help') {
    echo "Uso (terminal interactiva Windows):\n  php tools/account.php password NOMBRE_USUARIO\n  php tools/account.php activate NOMBRE_USUARIO\nLa contraseña se introduce oculta dos veces. 'password' NO activa la cuenta.\n'activate' solo admite las cuatro cuentas de demostración y exige un hash válido previo.\nCada operación exige confirmación explícita. No pasar contraseñas como argumentos.\n";
    exit;
}

use Clinica\Repositories\Connection;
use Clinica\Services\PasswordService;
use Clinica\Support\ConsoleSecret;
use Clinica\Support\Environment;

try {
    require dirname(__DIR__) . '/backend/bootstrap.php';
    if ($argc !== 3 || !in_array($argv[1], ['password','activate'], true) || !stream_isatty(STDIN) || PHP_OS_FAMILY !== 'Windows' || Environment::get('APP_ENV') !== 'local') {
        throw new RuntimeException('Uso no permitido. Consulte --help y use una terminal Windows local interactiva.');
    }
    $action = $argv[1];
    $username = $argv[2];
    if (!preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $username)) { throw new RuntimeException('Nombre de usuario inválido.'); }
    $demos = ['usuario1'=>'Administrador','usuario2'=>'Recepcionista','profesional01'=>'Medico','paciente01'=>'Paciente'];
    if ($action === 'activate' && !isset($demos[$username])) { throw new RuntimeException('La activación se limita a las cuatro cuentas de demostración.'); }
    $pdo = Connection::accountMaintenance(require dirname(__DIR__) . '/backend/config/database.php');
    if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'clinica_vida_salud') {
        throw new RuntimeException('La conexión no apunta a la base autorizada.');
    }
    $select = $pdo->prepare('SELECT u.id_usuario,u.id_rol,u.estado,u.contrasena_hash,r.nombre AS rol FROM public.usuarios u JOIN public.roles r ON r.id_rol=u.id_rol WHERE u.nombre_usuario=:username');
    $select->execute(['username'=>$username]);
    $account = $select->fetch();
    if (!$account || (isset($demos[$username]) && $account['rol'] !== $demos[$username])) { throw new RuntimeException('Cuenta no encontrada o rol inesperado.'); }
    echo 'Cuenta: ' . $username . ' | Rol: ' . $account['rol'] . ' | Estado: ' . $account['estado'] . PHP_EOL;
    if ($action === 'activate' && ($account['estado'] !== 'inactivo' || !is_string($account['contrasena_hash']) || password_get_info($account['contrasena_hash'])['algo'] === null)) {
        throw new RuntimeException('Solo se activa una cuenta inactiva con hash válido ya configurado.');
    }
    $hash = null;
    if ($action === 'password') {
        $first = ConsoleSecret::read('Nueva contraseña (12 a 72 bytes, entrada oculta): ');
        $second = ConsoleSecret::read('Repita la contraseña (entrada oculta): ');
        if (!hash_equals($first, $second)) { throw new RuntimeException('Las contraseñas no coinciden. No se guardó ningún cambio.'); }
        $hash = PasswordService::hash($first);
        unset($first, $second);
    }
    $confirmation = ($action === 'password' ? 'CONFIGURAR ' : 'ACTIVAR ') . $username;
    echo 'Para confirmar escriba exactamente: ' . $confirmation . PHP_EOL;
    if (trim((string)fgets(STDIN)) !== $confirmation) { throw new RuntimeException('Operación cancelada.'); }
    $pdo->beginTransaction();
    // Bloquear y revalidar la cuenta; abortar si cambió durante la entrada interactiva.
    $lock = $pdo->prepare('SELECT id_usuario,id_rol,estado,contrasena_hash FROM public.usuarios WHERE nombre_usuario=:username FOR UPDATE');
    $lock->execute(['username'=>$username]);
    $current = $lock->fetch();
    if (!$current || $current['id_usuario'] !== $account['id_usuario'] || $current['id_rol'] !== $account['id_rol'] || $current['estado'] !== $account['estado'] || $current['contrasena_hash'] !== $account['contrasena_hash']) {
        throw new RuntimeException('La cuenta cambió durante la operación; vuelva a intentarlo.');
    }
    $stmt = $action === 'password'
        ? $pdo->prepare('UPDATE public.usuarios SET contrasena_hash=:hash WHERE id_usuario=:id RETURNING id_usuario')
        : $pdo->prepare("UPDATE public.usuarios SET estado='activo' WHERE id_usuario=:id AND estado='inactivo' RETURNING id_usuario");
    $parameters = ['id'=>$account['id_usuario']];
    if ($action === 'password') { $parameters['hash'] = $hash; }
    $stmt->execute($parameters);
    if (!$stmt->fetchColumn()) { throw new RuntimeException('No se pudo completar la operación.'); }
    $pdo->commit();
    unset($hash, $parameters, $account, $current);
    echo $action === 'password' ? "Contraseña configurada. El estado de la cuenta no se cambió.\n" : "Cuenta de demostración activada.\n";
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    // Nunca imprimir excepciones PDO ni trazas que puedan contener parámetros.
    fwrite(STDERR, $error instanceof PDOException ? "Operación fallida en PostgreSQL. No se confirmaron cambios; detalles omitidos.\n" : ($error instanceof RuntimeException || $error instanceof InvalidArgumentException ? $error->getMessage() . PHP_EOL : "Operación fallida. Detalles omitidos por seguridad.\n"));
    exit(1);
}
