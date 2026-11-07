<?php
declare(strict_types=1);
namespace Clinica\Support;

use Clinica\Repositories\AuditRepository;
use Clinica\Repositories\Connection;
use Clinica\Services\AuditService;

final class AuditFactory
{
    public static function service(): AuditService
    {
        return new AuditService(new AuditRepository(Connection::readOnly(require dirname(__DIR__, 2).'/config/database.php')));
    }
}
