<?php
declare(strict_types=1);
namespace Clinica\Support;
use Clinica\Repositories\{Connection,CitaChangeRepository};
use Clinica\Services\CitaChangeService;
final class CitaChangeFactory
{
    public static function enabled():bool{return Environment::get('APPOINTMENT_CHANGES_ENABLED')==='true';}
    public static function reader():CitaChangeRepository{return new CitaChangeRepository(Connection::readOnly(require dirname(__DIR__,2).'/config/database.php'));}
    public static function writer():CitaChangeService
    {
        $pdo=Connection::appointmentChanges(require dirname(__DIR__,2).'/config/database.php');
        return new CitaChangeService(new CitaChangeRepository($pdo,NotificationFactory::forPdo($pdo)),true);
    }
}
