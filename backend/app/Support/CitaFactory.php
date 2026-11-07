<?php
declare(strict_types=1);
namespace Clinica\Support;
use Clinica\Repositories\Connection;
use Clinica\Repositories\CitaRepository;
use Clinica\Services\CitaService;
final class CitaFactory
{
    public static function service(): CitaService{return new CitaService(new CitaRepository(Connection::readOnly(require dirname(__DIR__,2).'/config/database.php')));}
}
