<?php
declare(strict_types=1);
namespace Clinica\Middleware;

final class AttendanceAccess
{
    public static function control(array $user): bool
    {
        return AuthorizationMiddleware::allowsAny($user, ['Administrador', 'Recepcionista'], 'controlar_asistencia');
    }

    public static function attend(array $user): bool
    {
        return AuthorizationMiddleware::allows($user, 'Medico', 'marcar_cita_atendida');
    }

    public static function confirm(array $user): bool
    {
        return AuthorizationMiddleware::allows($user, 'Paciente', 'confirmar_cita_propia');
    }

    public static function view(array $user): bool
    {
        return self::control($user)
            || self::attend($user)
            || self::confirm($user)
            || CitaAccess::listing($user);
    }
}
