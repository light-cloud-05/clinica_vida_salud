<?php
declare(strict_types=1);
namespace Clinica\Support;
use Clinica\Repositories\{Connection,PasswordRecoveryRepository};
use Clinica\Services\{LocalPasswordRecoveryTransport,PasswordRecoveryService};

final class PasswordRecoveryFactory
{
    public static function enabled(): bool { return Environment::get('PASSWORD_RECOVERY_WRITES_ENABLED') === 'true'; }
    public static function service(bool $write = false): PasswordRecoveryService
    {
        if (Environment::get('PASSWORD_RESET_TRANSPORT', 'local') !== 'local') {
            throw new \RuntimeException('No hay un transporte SMTP configurado y verificado.');
        }
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $pdo = $write ? Connection::passwordRecovery($config) : Connection::readOnly($config);
        $transport = new LocalPasswordRecoveryTransport(dirname(__DIR__, 2) . '/var/private-mail');
        return new PasswordRecoveryService(new PasswordRecoveryRepository($pdo), $transport);
    }
}
