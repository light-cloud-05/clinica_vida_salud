<?php
declare(strict_types=1);
namespace Clinica\Middleware;

final class VoiceAccess
{
    public static function allowed(array $actor):bool
    {
        return ($actor['id'] ?? 0)>0 && AuthorizationMiddleware::allowsAny($actor,['Paciente','Medico','Recepcionista','Administrador'],'realizar_consultas_voz');
    }
    public static function query(array $actor,string $intent):bool
    {
        if(!self::allowed($actor)){return false;}
        return match($intent){
            'proximas','agenda'=> $actor['role']==='Paciente' ? CitaAccess::listing($actor) : CitaAccess::agenda($actor),
            'medicos','horarios'=>CitaAccess::availability($actor),
            'pagos'=>PaymentAccess::view($actor),
            default=>false,
        };
    }
}
