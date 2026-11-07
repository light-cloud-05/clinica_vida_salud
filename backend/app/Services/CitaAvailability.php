<?php
declare(strict_types=1);
namespace Clinica\Services;
final class CitaAvailability
{
    public function evaluate(array $doctor,string $date,array $hours,array $blocks,array $appointments,?string $selected=null,?\DateTimeImmutable $now=null): array
    {
        $now=($now ?? new \DateTimeImmutable('now',new \DateTimeZone('America/La_Paz')))->setTimezone(new \DateTimeZone('America/La_Paz'));
        $day=ScheduleValidator::date($date);if(!$day){throw new \InvalidArgumentException('Fecha inválida.');}
        $window=DisponibilidadService::window($now);$result=['date'=>$date,'window'=>$window,'slots'=>[],'selected'=>null,'message'=>''];
        if($date<$now->format('Y-m-d')){$result['message']='La fecha ya pasó. No se ofrecen franjas pasadas.';return $result;}
        if((int)$day->format('N')>5){$result['message']='No hay atención reservable en fin de semana; la ventana comprende lunes a viernes.';return $result;}
        if(!in_array($date,$window,true)){$result['message']='Fecha fuera de la ventana de diez días hábiles.';return $result;}
        $data=(new DisponibilidadService())->day($doctor,$date,$hours,$blocks,$appointments,$now);
        foreach($data['franjas'] as $slot){$result['slots'][]=['hora_inicio'=>$slot['hora_inicio'],'hora_fin'=>$slot['hora_fin'],'estado'=>$slot['estado'],'available'=>$slot['estado']==='disponible'];}
        $free=array_values(array_filter($result['slots'],static fn(array $s): bool=>$s['available']));
        $result['message']=!$data['horarios'] ? 'No hay atención configurada para este médico y día.' : (!$free ? 'No quedan franjas futuras libres: revise los horarios ocupados, bloqueados o no disponibles.' : count($free).' franjas futuras disponibles. Esta consulta no reserva ni garantiza el turno.');
        if($selected!==null){
            $time=ScheduleValidator::time($selected);if($time===null){throw new \InvalidArgumentException('Hora inválida.');}
            $normalized=ScheduleValidator::formatTime($time);
            foreach($free as $slot){if($slot['hora_inicio']===$normalized){$result['selected']=$slot;break;}}
            if($result['selected']===null){$result['message']='La hora seleccionada no está disponible. Revise las franjas y vuelva a consultar.';}
        }
        return $result;
    }
}
