<?php
declare(strict_types=1);
namespace Clinica\Support;

use Clinica\Repositories\Connection;
use Clinica\Repositories\UserAdminRepository;
use Clinica\Services\UserAdminService;

final class UserAdminFactory
{
    public static function writesEnabled(): bool { return Environment::get('USER_WRITES_ENABLED') === 'true'; }
    public static function service(bool $write = false): UserAdminService
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';
        $pdo = $write ? Connection::userManagement($config) : Connection::readOnly($config);
        return new UserAdminService(new UserAdminRepository($pdo), $write && self::writesEnabled());
    }
}
