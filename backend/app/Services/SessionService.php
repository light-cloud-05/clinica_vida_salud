<?php
declare(strict_types=1);
namespace Clinica\Services;

final class SessionService
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { return; }
        $path = dirname(__DIR__, 3) . '/var/sessions';
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Sesiones no disponibles.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', '28800');
        session_save_path($path);
        session_name('CVS_SESSION');
        session_set_cookie_params(['lifetime'=>0, 'path'=>'/', 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly'=>true, 'samesite'=>'Lax']);
        if (!session_start()) { throw new \RuntimeException('Sesiones no disponibles.'); }
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function validCsrf(mixed $token): bool
    {
        return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    public static function login(array $user): void
    {
        if (!session_regenerate_id(true)) { throw new \RuntimeException('No se pudo renovar la sesión.'); }
        $_SESSION = ['auth'=>['id'=>$user['id'], 'credential_version'=>$user['credential_version'], 'created'=>time(), 'last'=>time()], 'csrf'=>bin2hex(random_bytes(32))];
    }

    public static function fresh(array $auth, int $now): bool
    {
        return isset($auth['created'], $auth['last']) && $now >= $auth['created'] && $now >= $auth['last'] && $now - $auth['last'] < 1800 && $now - $auth['created'] < 28800;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires'=>time()-3600, 'path'=>$params['path'], 'secure'=>$params['secure'], 'httponly'=>true, 'samesite'=>'Lax']);
        session_destroy();
        session_id('');
    }
}
