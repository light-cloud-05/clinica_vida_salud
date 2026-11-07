<?php
declare(strict_types=1);
namespace Clinica\Support;

use Clinica\Repositories\{AttendanceRepository, Connection};
use Clinica\Services\AttendanceService;

final class AttendanceFactory
{
    public static function reader(): AttendanceRepository
    {
        return new AttendanceRepository(Connection::readOnly(require dirname(__DIR__, 2).'/config/database.php'));
    }

    public static function writer(): AttendanceService
    {
        return new AttendanceService(
            new AttendanceRepository(Connection::appointmentAttendance(require dirname(__DIR__, 2).'/config/database.php')),
            true
        );
    }

    public static function enabled(): bool
    {
        return AttendanceService::enabledByEnvironment();
    }
}
