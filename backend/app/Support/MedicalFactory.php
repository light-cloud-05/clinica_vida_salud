<?php
declare(strict_types=1);
namespace Clinica\Support;
use Clinica\Repositories\Connection;
use Clinica\Repositories\MedicoRepository;
use Clinica\Services\MedicoService;
final class MedicalFactory
{
    public static function writesEnabled(): bool{return Environment::get('MEDICAL_WRITES_ENABLED')==='true';}
    public static function service(bool $write=false): MedicoService
    {
        $config=require dirname(__DIR__,2).'/config/database.php';
        return new MedicoService(new MedicoRepository($write ? Connection::medicalManagement($config) : Connection::readOnly($config)),$write && self::writesEnabled());
    }
}
