<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Middleware\BookingAccess;
use Clinica\Support\Input;
final class CitaChangePolicy
{
    public static function input(array $actor,array $input,string $action):array
    {
        if(($actor['id'] ?? 0)<1 || !BookingAccess::allowed($actor)){throw new \DomainException('No tiene permiso para modificar citas.');}
        if(!in_array($action,['reprogramar','cancelar'],true)){throw new \InvalidArgumentException('Operación inválida.');}
        $allowed=$action==='reprogramar' ? ['id','version','csrf','fecha','hora'] : ['id','version','csrf','motivo'];
        if(array_diff(array_keys($input),$allowed)){throw new \InvalidArgumentException('Hay campos no permitidos.');}
        $id=Input::positiveId($input['id'] ?? null);$version=$input['version'] ?? null;
        if($id===null || !is_string($version) || !preg_match('/^[a-f0-9]{64}$/D',$version)){throw new \InvalidArgumentException('Identificador o versión inválidos.');}
        $result=['id'=>$id,'version'=>$version];
        if($action==='reprogramar'){
            $time=ScheduleValidator::time($input['hora'] ?? null);
            if(!ScheduleValidator::date($input['fecha'] ?? null) || $time===null){throw new \InvalidArgumentException('Seleccione fecha y hora válidas.');}
            $result+=['fecha'=>$input['fecha'],'hora'=>ScheduleValidator::formatTime($time)];
        }else{
            $reason=$input['motivo'] ?? '';
            if(!is_string($reason) || mb_strlen($reason)>2000 || str_contains($reason,"\0")){throw new \InvalidArgumentException('El motivo admite hasta 2000 caracteres.');}
            $result['motivo']=trim($reason)==='' ? null : trim($reason);
        }
        return $result;
    }
    public static function version(array $c):string
    {
        return hash('sha256',json_encode(array_map(static fn(string $key):string=>(string)$c[$key],['id_cita','id_paciente','id_medico','id_especialidad','fecha','hora_inicio','hora_fin','estado','cantidad_reprogramaciones','updated_at']),JSON_THROW_ON_ERROR));
    }
    public static function check(array $c,string $action,\DateTimeImmutable $now):void
    {
        if(!in_array($c['estado'],['programada','confirmada'],true)){throw new BookingConflict('El estado actual no permite esta operación.');}
        $date=new \DateTimeImmutable($c['fecha'].' '.$c['hora_inicio'],new \DateTimeZone('America/La_Paz'));
        if($date<=$now){throw new BookingConflict('Solo se permiten cambios en citas futuras.');}
        if($action==='reprogramar' && (int)$c['cantidad_reprogramaciones']>=1){throw new BookingConflict('La cita ya utilizó su única reprogramación.');}
    }
}
