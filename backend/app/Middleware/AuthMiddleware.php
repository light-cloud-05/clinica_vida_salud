<?php
declare(strict_types=1);
namespace Clinica\Middleware;

use Clinica\Services\AuthService;
use Clinica\Services\SessionService;

final class AuthMiddleware
{
    public static function current(AuthService $auth): ?array
    {
        $session = $_SESSION['auth'] ?? null;
        if (!is_array($session) || !SessionService::fresh($session, time())) {
            unset($_SESSION['auth']);
            return null;
        }
        $user = $auth->current((int)$session['id'], (string)$session['credential_version']);
        if (!$user) {
            SessionService::logout();
            return null;
        }
        $_SESSION['auth']['last'] = time();
        return $user;
    }
}
