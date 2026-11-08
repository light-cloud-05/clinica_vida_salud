<?php
declare(strict_types=1);
namespace Clinica\Support;

use Clinica\Repositories\{Connection,NotificationRepository};
use Clinica\Services\{LocalMailTransport,MailTransport,NotificationService,SmtpMailTransport};

final class NotificationFactory
{
    public static function forPdo(\PDO $pdo): NotificationService
    {
        return new NotificationService(new NotificationRepository($pdo), self::transport(), self::retries());
    }

    public static function reader(): NotificationRepository
    {
        return new NotificationRepository(Connection::readOnly(require dirname(__DIR__, 2).'/config/database.php'));
    }

    public static function writer(): NotificationService
    {
        return self::forPdo(Connection::notificationManagement(require dirname(__DIR__, 2).'/config/database.php'));
    }

    private static function transport(): MailTransport
    {
        if (Environment::get('MAIL_TRANSPORT', 'local') !== 'smtp') {
            return new LocalMailTransport();
        }
        $host = Environment::get('MAIL_HOST');
        $from = Environment::get('MAIL_FROM');
        $port = Environment::get('MAIL_PORT', '587');
        if ($host === '' || $from === '' || !ctype_digit($port)) {
            return new LocalMailTransport();
        }
        return new SmtpMailTransport($host, (int)$port, Environment::get('MAIL_USERNAME'), Environment::get('MAIL_PASSWORD'), $from, Environment::get('MAIL_ENCRYPTION', 'tls'));
    }

    private static function retries(): int
    {
        $value = Environment::get('NOTIFICATION_MAX_RETRIES', '2');
        return ctype_digit($value) ? max(1, min(5, (int)$value)) : 2;
    }
}
