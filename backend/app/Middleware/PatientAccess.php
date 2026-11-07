<?php
declare(strict_types=1);
namespace Clinica\Middleware;

final class PatientAccess
{
    public static function allowed(array $user): bool
    {
        return AuthorizationMiddleware::allowsAny($user, ['Administrador','Recepcionista'], 'gestionar_pacientes');
    }
}
