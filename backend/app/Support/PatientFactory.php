<?php
declare(strict_types=1);
namespace Clinica\Support;

use Clinica\Repositories\Connection;
use Clinica\Repositories\PacienteRepository;
use Clinica\Services\PacienteService;

final class PatientFactory
{
    public static function writesEnabled(): bool { return Environment::get('PATIENT_WRITES_ENABLED')==='true'; }

    public static function service(bool $write=false): PacienteService
    {
        $config=require dirname(__DIR__,2).'/config/database.php';
        $pdo=$write ? Connection::patientManagement($config) : Connection::readOnly($config);
        return new PacienteService(new PacienteRepository($pdo),$write && self::writesEnabled());
    }
}
