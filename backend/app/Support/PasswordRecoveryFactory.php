<?php
declare(strict_types=1);
namespace Clinica\Support;
use Clinica\Repositories\{Connection,PasswordRecoveryRepository};
use Clinica\Services\{LocalPasswordRecoveryTransport,PasswordRecoveryService,SmtpMailTransport,SmtpPasswordRecoveryTransport};

final class PasswordRecoveryFactory
{
    public static function enabled(): bool { return Environment::get('PASSWORD_RECOVERY_WRITES_ENABLED') === 'true'; }
    public static function service(bool $write = false): PasswordRecoveryService
    {
        if (Environment::get('PASSWORD_RESET_TRANSPORT', 'local') === 'smtp') {
            $transport = new SmtpPasswordRecoveryTransport(new SmtpMailTransport(
                Environment::get('MAIL_HOST'), (int)Environment::get('MAIL_PORT','587'),
                Environment::get('MAIL_USERNAME'), Environment::get('MAIL_PASSWORD'),
                Environment::get('MAIL_FROM'), Environment::get('MAIL_ENCRYPTION','tls')
            ), Environment::get('APP_URL'));
        } elseif (Environment::get('APP_ENV') !== 'production' && Environment::get('PASSWORD_RESET_TRANSPORT','local') === 'local') {
            $transport = new LocalPasswordRecoveryTransport(dirname(__DIR__, 3) . '/var/private-mail');
        } else {
            throw new \RuntimeException('Configurar SMTP antes de habilitar recuperacion en produccion.');
        }
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $pdo = $write ? Connection::passwordRecovery($config) : Connection::readOnly($config);
        return new PasswordRecoveryService(new PasswordRecoveryRepository($pdo), $transport);
    }
}
