<?php
declare(strict_types=1);
namespace Clinica\Support;
use Clinica\Repositories\Connection;
use Clinica\Repositories\HorarioRepository;
use Clinica\Services\HorarioService;
final class ScheduleFactory
{
    public static function writesEnabled(): bool{return Environment::get('SCHEDULE_WRITES_ENABLED')==='true';}
    public static function service(bool $write=false): HorarioService
    {
        $config=require dirname(__DIR__,2).'/config/database.php';return new HorarioService(new HorarioRepository($write ? Connection::scheduleManagement($config) : Connection::readOnly($config)),$write && self::writesEnabled());
    }
}
