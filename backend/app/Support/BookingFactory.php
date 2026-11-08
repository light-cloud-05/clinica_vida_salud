<?php
declare(strict_types=1);
namespace Clinica\Support;
use Clinica\Repositories\Connection;
use Clinica\Repositories\ReservaRepository;
use Clinica\Services\ReservaService;
final class BookingFactory
{
    public static function enabled(): bool{return Environment::get('APPOINTMENT_WRITES_ENABLED')==='true';}
    public static function service(): ReservaService
    {
        $pdo=Connection::appointmentCreation(require dirname(__DIR__,2).'/config/database.php');
        $notifications=Environment::get('NOTIFICATION_WRITES_ENABLED')==='true' ? NotificationFactory::forPdo($pdo) : null;
        return new ReservaService(new ReservaRepository($pdo,$notifications),self::enabled());
    }
}
