<?php
declare(strict_types=1);

namespace Clinica\Middleware;

final class MedicalHistoryAccess
{
    public static function view(array $user): bool
    {
        if ($user['role'] === 'Paciente') {
            return AuthorizationMiddleware::allows($user, 'Paciente', 'consultar_informacion_medica_propia');
        }

        return AuthorizationMiddleware::allowsAny(
            $user,
            ['Administrador', 'Recepcionista', 'Medico'],
            'consultar_historial_citas'
        );
    }

    public static function clinical(array $user): bool
    {
        return AuthorizationMiddleware::allows($user, 'Medico', 'gestionar_notas_medicas')
            || AuthorizationMiddleware::allows($user, 'Paciente', 'consultar_informacion_medica_propia');
    }

    public static function write(array $user): bool
    {
        return AuthorizationMiddleware::allows($user, 'Medico', 'gestionar_notas_medicas');
    }
}
