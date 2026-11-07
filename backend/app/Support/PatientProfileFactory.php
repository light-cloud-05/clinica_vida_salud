<?php
declare(strict_types=1);
namespace Clinica\Support;
use Clinica\Repositories\{Connection,PatientProfileRepository};
use Clinica\Services\PatientProfileService;
final class PatientProfileFactory
{
    public static function writesEnabled(): bool { return Environment::get('PATIENT_PROFILE_WRITES_ENABLED') === 'true'; }
    public static function service(bool $write = false): PatientProfileService
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $pdo = $write ? Connection::patientProfile($config) : Connection::readOnly($config);
        return new PatientProfileService(new PatientProfileRepository($pdo), $write && self::writesEnabled());
    }
}
