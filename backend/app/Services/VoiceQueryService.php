<?php
declare(strict_types=1);
namespace Clinica\Services;

use Clinica\Middleware\VoiceAccess;
use Clinica\Repositories\VoiceQueryStore;

final class VoiceQueryService
{
    public function __construct(private VoiceQueryStore $store){}
    public function answer(array $actor,mixed $text,mixed $date=null,?\DateTimeImmutable $now=null):array
    {
        if(!VoiceAccess::allowed($actor)){throw new \DomainException('No tiene permiso para consultas por voz o texto.');}
        $command=VoiceIntent::parse($text);$intent=$command['intent'];
        if(!VoiceAccess::query($actor,$intent)){throw new \DomainException('Su rol no tiene permiso para esa consulta.');}
        $now=($now ?? new \DateTimeImmutable('now',new \DateTimeZone('America/La_Paz')))->setTimezone(new \DateTimeZone('America/La_Paz'));
        if($intent==='horarios' && $date!==null && $date!==''){
            if(!is_string($date) || ScheduleValidator::date($date)===null || !in_array($date,DisponibilidadService::window($now),true)){throw new \InvalidArgumentException('Seleccione una fecha dentro de los próximos diez días hábiles de atención, incluido hoy cuando corresponda.');}
        }else{$date=null;}
        $columns=[];$rows=[];$message='';$title='';
        if(in_array($intent,['proximas','agenda'],true)){
            $today=$intent==='agenda';$items=$this->store->appointments($actor,$today,$now);
            $title=$today ? 'Agenda autorizada de hoy · '.$now->format('d/m/Y') : 'Próximas citas autorizadas';
            $columns=['Código','Fecha','Inicio','Fin','Estado','Médico','Especialidad'];
            foreach($items as $r){$rows[]=[$r['codigo_cita'],$r['fecha'],$r['hora_inicio'],$r['hora_fin'],$r['estado'],$r['medico'],$r['especialidad']];}
            $message=$rows ? 'Se muestran '.count($rows).' citas autorizadas, como máximo diez.' : 'No hay citas '.($today ? 'para hoy' : 'futuras programadas o confirmadas').' dentro de su ámbito autorizado.';
        }elseif($intent==='pagos'){
            $title='Estado de pagos en PostgreSQL';$columns=['Cita','Fecha de cita','Método','Estado de pago','Reserva','Importe','Moneda'];
            foreach($this->store->payments($actor) as $r){$rows[]=[$r['codigo_cita'],$r['fecha'],$r['metodo'],$r['estado'],$r['reserva_estado'] ?? 'Sin reserva provisional',$r['importe'],$r['moneda']];}
            $message=$rows ? 'Se muestran '.count($rows).' pagos autorizados, como máximo diez. El estado de pago y el de reserva se muestran por separado.' : 'No hay pagos registrados en PostgreSQL dentro de su ámbito autorizado. Esto no indica que una cita esté pagada. Los ensayos locales de P9 se consultan en Pagos de demostración.';
        }else{
            $choices=$this->store->choices($actor);$specialty=null;$specialtyName=null;
            if($command['specialty']!==null){
                foreach($choices['specialties'] as $s){if(VoiceIntent::normalize($s['nombre'])===$command['specialty']){$specialty=(int)$s['id_especialidad'];$specialtyName=$s['nombre'];break;}}
                if($specialty===null){return $this->result('Especialidad sin resultados','No se encontró esa especialidad activa en el catálogo autorizado. Pruebe con el nombre de una especialidad registrada.',[],[],$intent,$now);}
            }
            if($intent==='medicos'){
                $title='Médicos autorizados por especialidad';$columns=['Médico','Especialidad'];
                foreach($choices['doctors'] as $d){if(in_array($specialty,$d['especialidades'],true)){$rows[]=[$d['nombre'],$specialtyName];if(count($rows)===10){break;}}}
                $message=$rows ? 'Se muestran '.count($rows).' médicos activos autorizados, como máximo diez.' : 'No hay médicos activos de esa especialidad dentro de su ámbito autorizado.';
            }else{
                $title='Horarios disponibles · '.($date ?? 'próximos diez días hábiles');$columns=['Fecha','Inicio','Fin','Médico','Especialidad'];
                foreach($this->store->availability($actor,$specialty,$date,$now) as $r){$rows[]=[$r['fecha'],$r['hora_inicio'],$r['hora_fin'],$r['medico'],$r['especialidad']];}
                $message=($rows ? 'Se muestran '.count($rows).' franjas disponibles, como máximo diez.' : 'No se encontraron franjas disponibles en la fecha o ventana consultada dentro de su ámbito autorizado.').' Se consideran horarios, duración médica, citas y bloqueos. Esta consulta no reserva ni garantiza un turno.';
            }
        }
        return $this->result($title,$message,$columns,$rows,$intent,$now);
    }
    private function result(string $title,string $message,array $columns,array $rows,string $intent,\DateTimeImmutable $now):array
    {
        // La lectura se limita al resumen, sin nombres de pacientes, códigos ni importes.
        return ['title'=>$title,'message'=>$message,'spoken'=>$message,'columns'=>$columns,'rows'=>$rows,'intent'=>$intent,'source'=>'PostgreSQL · solo lectura','at'=>$now->format('Y-m-d H:i:s').' America/La_Paz'];
    }
}
