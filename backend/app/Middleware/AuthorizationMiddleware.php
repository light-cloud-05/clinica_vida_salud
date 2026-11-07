<?php
declare(strict_types=1);
namespace Clinica\Middleware;

final class AuthorizationMiddleware
{
    public static function allows(array $user, string $role, ?string $permission = null): bool
    {
        return $user['role'] === $role && ($permission === null || in_array($permission, $user['permissions'], true));
    }

    public static function allowsAny(array $user, array $roles, string $permission): bool
    {
        foreach ($roles as $role) {
            if (self::allows($user, $role, $permission)) { return true; }
        }
        return false;
    }
}
