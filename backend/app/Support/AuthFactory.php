<?php
declare(strict_types=1);
namespace Clinica\Support;

use Clinica\Repositories\Connection;
use Clinica\Repositories\UserRepository;
use Clinica\Services\AuthService;

final class AuthFactory
{
    public static function service(): AuthService
    {
        return new AuthService(new UserRepository(Connection::readOnly(require dirname(__DIR__, 2) . '/config/database.php')));
    }
}
