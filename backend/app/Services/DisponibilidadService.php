<?php
declare(strict_types=1);
namespace Clinica\Services;

/** Predicados equivalentes a validar_cita y a la exclusión de citas, sin escrituras. */
final class DisponibilidadService
{
    public static function overlaps(array $a,array $b): bool
    {
        return ScheduleValidator::time($a['hora_inicio'])<ScheduleValidator::time($b['hora_fin']) && ScheduleValidator::time($a['hora_fin'])>ScheduleValidator::time($b['hora_inicio']);
    }
    public static function covers(array $schedule,array $appointment): bool
    {
        $date=ScheduleValidator::date($appointment['fecha']);
        if(!$date || $schedule['estado']!=='activo' || (int)$schedule['id_medico']!==(int)$appointment['id_medico'] || (int)$schedule['dia_semana']!==(int)$date->format('N')){return false;}
        $start=ScheduleValidator::time($appointment['hora_inicio']);$end=ScheduleValidator::time($appointment['hora_fin']);$anchor=ScheduleValidator::time($schedule['hora_inicio']);$duration=(int)$schedule['duracion_cita_minutos']*60000000;
        return $duration>0 && $start>=$anchor && $end<=ScheduleValidator::time($schedule['hora_fin']) && $end-$start===$duration && ($start-$anchor)%$duration===0;
    }
    public static function window(\DateTimeImmutable $now): array
    {
        $days=[];$date=$now->setTimezone(new \DateTimeZone('America/La_Paz'))->setTime(0,0);
        while(count($days)<10){if((int)$date->format('N')<=5){$days[]=$date->format('Y-m-d');}$date=$date->modify('+1 day');}return $days;
    }
    public function day(array $doctor,string $date,array $schedules,array $blocks,array $appointments,?\DateTimeImmutable $now=null): array
    {
        $now=($now ?? new \DateTimeImmutable('now',new \DateTimeZone('America/La_Paz')))->setTimezone(new \DateTimeZone('America/La_Paz'));
        $day=ScheduleValidator::date($date);if(!$day){throw new \InvalidArgumentException('Fecha inválida.');}
        $hours=array_values(array_filter($schedules,static fn(array $h): bool=>(int)$h['id_medico']===(int)$doctor['id_medico'] && (int)$h['dia_semana']===(int)$day->format('N') && $h['estado']==='activo'));
        $blocks=array_values(array_filter($blocks,static fn(array $b): bool=>(int)$b['id_medico']===(int)$doctor['id_medico'] && $b['fecha']===$date));
        $appointments=array_values(array_filter($appointments,static fn(array $c): bool=>(int)$c['id_medico']===(int)$doctor['id_medico'] && $c['fecha']===$date && $c['estado']!=='cancelada'));
        $slots=[];$window=self::window($now);$nowMicros=(int)$now->format('U')*1000000+(int)$now->format('u');
        foreach($hours as $i=>$h){
            $conflict=false;foreach($hours as $j=>$other){if($i!==$j && self::overlaps($h,$other)){$conflict=true;}}
            $step=(int)$h['duracion_cita_minutos']*60000000;if($step<=0){continue;}
            for($start=ScheduleValidator::time($h['hora_inicio']),$end=ScheduleValidator::time($h['hora_fin']);$start+$step<=$end;$start+=$step){
                $slot=['hora_inicio'=>ScheduleValidator::formatTime($start),'hora_fin'=>ScheduleValidator::formatTime($start+$step)];
                $blocked=false;$occupied=false;foreach($blocks as $b){$blocked=$blocked || self::overlaps($slot,$b);}foreach($appointments as $c){$occupied=$occupied || self::overlaps($slot,$c);}
                $past=(int)$day->format('U')*1000000+$start<=$nowMicros;
                $state=$occupied && $blocked ? 'ocupada y bloqueada' : ($occupied ? 'ocupada' : ($blocked ? 'bloqueada' : ($conflict ? 'conflicto de horarios' : ($doctor['estado']!=='activo' ? 'médico inactivo' : ($past ? 'pasada' : 'disponible')))));
                $key=$slot['hora_inicio'].'-'.$slot['hora_fin'];$slots[$key]=$slot+['estado'=>$state,'dentro_ventana'=>in_array($date,$window,true)];
            }
        }
        uasort($slots,static fn(array $a,array $b): int=>strcmp($a['hora_inicio'],$b['hora_inicio']));
        return ['fecha'=>$date,'horarios'=>$hours,'bloqueos'=>$blocks,'citas'=>$appointments,'franjas'=>array_values($slots)];
    }
}
