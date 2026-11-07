<?php
declare(strict_types=1);
namespace Clinica\Middleware;
final class ScheduleAccess
{
    public static function view(array $user): bool{return AuthorizationMiddleware::allowsAny($user,['Administrador','Recepcionista','Medico'],'consultar_agenda');}
    public static function schedules(array $user): bool{return AuthorizationMiddleware::allows($user,'Administrador','gestionar_horarios');}
    public static function blocks(array $user): bool{return AuthorizationMiddleware::allowsAny($user,['Administrador','Medico'],'gestionar_bloqueos');}
    public static function ownOnly(array $user): bool{return $user['role']==='Medico';}
}
