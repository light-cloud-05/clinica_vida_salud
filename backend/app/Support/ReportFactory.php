<?php
declare(strict_types=1);
namespace Clinica\Support;

use Clinica\Repositories\Connection;
use Clinica\Repositories\ReportRepository;
use Clinica\Services\ReportService;

final class ReportFactory
{
    public static function service(): ReportService
    {
        return new ReportService(new ReportRepository(Connection::readOnly(require dirname(__DIR__,2).'/config/database.php')));
    }
}
