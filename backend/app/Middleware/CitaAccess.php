<?php
declare(strict_types=1);
namespace Clinica\Middleware;
final class CitaAccess
{
    public static function personal(array $user): bool{return $user['role']==='Paciente';}
    public static function listing(array $user): bool
    {
        return self::personal($user) ? AuthorizationMiddleware::allows($user,'Paciente','gestionar_citas_propias') : AuthorizationMiddleware::allowsAny($user,['Administrador','Recepcionista','Medico'],'consultar_historial_citas');
    }
    public static function agenda(array $user): bool{return AuthorizationMiddleware::allowsAny($user,['Administrador','Recepcionista','Medico'],'consultar_agenda');}
    public static function availability(array $user): bool{return self::personal($user) ? self::listing($user) : self::agenda($user);}
    public static function base(array $user): string{return self::personal($user) ? '/mis-citas' : '/citas';}
}
