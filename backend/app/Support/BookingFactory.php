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
        return new ReservaService(new ReservaRepository($pdo,NotificationFactory::forPdo($pdo)),self::enabled());
    }
}
