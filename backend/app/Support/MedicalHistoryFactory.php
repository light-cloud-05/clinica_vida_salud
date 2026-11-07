<?php
declare(strict_types=1);

namespace Clinica\Support;

use Clinica\Repositories\{Connection, MedicalHistoryRepository};
use Clinica\Services\MedicalHistoryService;

final class MedicalHistoryFactory
{
    public static function enabled(): bool
    {
        return Environment::get('MEDICAL_RECORD_WRITES_ENABLED') === 'true';
    }

    public static function service(bool $write = false): MedicalHistoryService
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $pdo = $write
            ? Connection::medicalRecordManagement($config)
            : Connection::readOnly($config);
        return new MedicalHistoryService(new MedicalHistoryRepository($pdo), $write && self::enabled());
    }
}
