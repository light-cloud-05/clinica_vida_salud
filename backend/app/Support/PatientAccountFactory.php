<?php
declare(strict_types=1);
namespace Clinica\Support;
use Clinica\Repositories\{Connection,PatientAccountRepository};
use Clinica\Services\PatientAccountService;

final class PatientAccountFactory
{
    public static function writesEnabled(): bool { return Environment::get('PATIENT_ACCOUNT_WRITES_ENABLED') === 'true'; }
    public static function service(bool $write = false): PatientAccountService
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $pdo = $write ? Connection::patientAccount($config) : Connection::readOnly($config);
        return new PatientAccountService(new PatientAccountRepository($pdo));
    }
}
