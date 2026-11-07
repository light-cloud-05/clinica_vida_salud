<?php
declare(strict_types=1);
namespace Clinica\Repositories;

use Clinica\Middleware\VoiceAccess;
use Clinica\Services\{CitaService,CitaAvailability,DisponibilidadService};

final class VoiceQueryRepository implements VoiceQueryStore
{
    public function __construct(private \PDO $pdo){}
    private function guard(array $actor,string $intent):void
    {
        if(!VoiceAccess::query($actor,$intent)){throw new \DomainException('Consulta no autorizada para su rol y permisos.');}
    }
    public function appointments(array $actor,bool $today,\DateTimeImmutable $now):array
    {
        $this->guard($actor,$today ? 'agenda' : 'proximas');
        $params=[];$where=[];
        if($actor['role']==='Paciente'){$where[]='p.id_usuario=:actor';$params['actor']=$actor['id'];}
        elseif($actor['role']==='Medico'){$where[]='m.id_usuario=:actor';$params['actor']=$actor['id'];}
        if($today){$where[]='c.fecha=:day';$params['day']=$now->format('Y-m-d');}
        else{$where[]="c.estado IN ('programada','confirmada') AND c.fecha+c.hora_inicio>=CAST(:now AS timestamp)";$params['now']=$now->format('Y-m-d H:i:s');}
        // Proyección mínima: sin motivo clínico, documento, teléfono ni identificadores de usuario.
        $sql="SELECT c.codigo_cita,c.fecha,c.hora_inicio,c.hora_fin,c.estado,m.nombres||' '||m.apellidos AS medico,e.nombre AS especialidad FROM public.citas c JOIN public.pacientes p ON p.id_paciente=c.id_paciente JOIN public.medicos m ON m.id_medico=c.id_medico JOIN public.especialidades e ON e.id_especialidad=c.id_especialidad WHERE ".implode(' AND ',$where).' ORDER BY c.fecha,c.hora_inicio,c.id_cita LIMIT 10';
        $s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll();
    }
    public function choices(array $actor):array
    {
        $this->guard($actor,'medicos');
        $choices=(new CitaService(new CitaRepository($this->pdo)))->availabilityChoices($actor);
        $active=$this->pdo->query("SELECT id_especialidad FROM public.especialidades WHERE estado='activo'")->fetchAll(\PDO::FETCH_COLUMN);
        $ids=array_map('intval',$active);
        $choices['specialties']=array_values(array_filter($choices['specialties'],static fn(array $s):bool=>in_array((int)$s['id_especialidad'],$ids,true)));
        $choices['doctors']=array_values(array_filter($choices['doctors'],static fn(array $d):bool=>$d['estado']==='activo' && (bool)array_intersect($d['especialidades'],$ids)));
        return $choices;
    }
    public function availability(array $actor,?int $specialty,?string $date,\DateTimeImmutable $now):array
    {
        $this->guard($actor,'horarios');$choices=$this->choices($actor);
        $doctors=array_values(array_filter($choices['doctors'],static fn(array $d):bool=>$specialty===null || in_array($specialty,$d['especialidades'],true)));
        if(!$doctors){return [];}
        $days=$date===null ? DisponibilidadService::window($now) : [$date];
        $repo=new HorarioRepository($this->pdo);$ids=array_column($doctors,'id_medico');
        $hours=$repo->hours($ids);$blocks=$repo->blocks($ids,min($days),max($days));$appointments=$repo->appointments($ids,min($days),max($days));
        $names=[];foreach($choices['specialties'] as $s){$names[(int)$s['id_especialidad']]=$s['nombre'];}
        $rows=[];
        foreach($days as $day){foreach($doctors as $doctor){
            $data=(new CitaAvailability())->evaluate($doctor,$day,$hours,$blocks,$appointments,null,$now);
            foreach($data['slots'] as $slot){if(!$slot['available']){continue;}
                $labels=[];foreach($doctor['especialidades'] as $sid){if(isset($names[$sid]) && ($specialty===null || $specialty===$sid)){$labels[]=$names[$sid];}}
                $rows[]=['fecha'=>$day,'hora_inicio'=>$slot['hora_inicio'],'hora_fin'=>$slot['hora_fin'],'medico'=>$doctor['nombre'],'especialidad'=>implode(', ',$labels)];
                if(count($rows)===10){return $rows;}
            }
        }}return $rows;
    }
    public function payments(array $actor):array
    {
        $this->guard($actor,'pagos');
        return array_slice((new PaymentRepository($this->pdo))->listing($actor),0,10);
    }
}
