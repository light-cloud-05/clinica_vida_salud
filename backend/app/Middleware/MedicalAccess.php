<?php
declare(strict_types=1);
namespace Clinica\Middleware;

final class MedicalAccess
{
    public static function allowed(array $user): bool
    {
        return AuthorizationMiddleware::allows($user,'Administrador','gestionar_medicos');
    }
}
