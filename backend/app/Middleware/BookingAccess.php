<?php
declare(strict_types=1);
namespace Clinica\Middleware;
final class BookingAccess
{
    public static function allowed(array $actor): bool
    {
        return CitaAccess::availability($actor) && (AuthorizationMiddleware::allows($actor,'Paciente','gestionar_citas_propias') || AuthorizationMiddleware::allowsAny($actor,['Administrador','Recepcionista'],'gestionar_citas'));
    }
}
