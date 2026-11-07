<?php
declare(strict_types=1);
namespace Clinica\Services;
final class ScheduleRules
{
    public static function conflicts(string $kind,array $data,?int $id,array $hours,array $blocks,array $appointments): array
    {
        $errors=[];$after=[];
        if($kind==='horario'){
            foreach($hours as $h){if($id!==null && (int)$h['id_horario']===$id){continue;}$after[]=$h;}
            $others=$after;$after[]=$data;
            if($data['estado']==='activo'){
                foreach($others as $h){if((int)$h['id_medico']===(int)$data['id_medico'] && (int)$h['dia_semana']===(int)$data['dia_semana'] && $h['estado']==='activo' && DisponibilidadService::overlaps($h,$data)){$errors['hora_inicio']='El intervalo se superpone con otro horario activo del médico.';}}
            }
            if($id!==null){foreach($appointments as $c){if($c['estado']==='cancelada'){continue;}$before=false;$covered=false;foreach($hours as $h){$before=$before || DisponibilidadService::covers($h,$c);}foreach($after as $h){$covered=$covered || DisponibilidadService::covers($h,$c);}if($before && !$covered){$errors['_form']='El cambio dejaría citas existentes fuera del horario o intervalo permitido. No se modificará ninguna cita.';}}}
        }else{
            foreach($blocks as $b){if($id!==null && (int)$b['id_bloqueo']===$id){continue;}if((int)$b['id_medico']===(int)$data['id_medico'] && $b['fecha']===$data['fecha'] && DisponibilidadService::overlaps($b,$data)){$errors['hora_inicio']='El intervalo se superpone con otro bloqueo del médico.';}}
            $affected=[];foreach($appointments as $c){if($c['estado']!=='cancelada' && (int)$c['id_medico']===(int)$data['id_medico'] && $c['fecha']===$data['fecha'] && DisponibilidadService::overlaps($c,$data)){$affected[]=$c['hora_inicio'].'–'.$c['hora_fin'];}}
            if($affected){$errors['_form']='El bloqueo afectaría '.count($affected).' cita(s) existente(s) en: '.implode(', ',$affected).'. Deben resolverse por el flujo autorizado de citas antes de bloquear. No se cancelan automáticamente.';}
        }
        return $errors;
    }
}
