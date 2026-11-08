<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\ReportRepository;
use Clinica\Services\ReportFilters;
final class P6ReadSimulation extends PDO {
    public array $queries=[];
    public array $hours=[];
    public array $appointments=[];
    public array $blocks=[];
    public function __construct() {}
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false { $s=$this->prepare($query); $s->execute(); return $s; }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if (!preg_match('/^\s*SELECT /',$query)) { throw new RuntimeException('Solo SELECT permitido en esta simulación.'); }
        $this->queries[]=$query;
        return new P6ReadStatement($this,$query);
    }
}
final class P6ReadStatement extends PDOStatement {
    public function __construct(private P6ReadSimulation $db,private string $sql) {}
    public function execute(?array $params=null): bool { return true; }
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR): bool { return true; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        if (str_contains($this->sql,'FROM public.medicos ORDER')) { return [['id_medico'=>1,'nombre'=>'Profesional de prueba','estado'=>'activo']]; }
        if (str_contains($this->sql,'FROM public.especialidades ORDER')) { return [['id_especialidad'=>1,'nombre'=>'Especialidad A'],['id_especialidad'=>2,'nombre'=>'Especialidad B']]; }
        if (str_contains($this->sql,'FROM public.medico_especialidad')) { return [['id_medico'=>1,'id_especialidad'=>1],['id_medico'=>1,'id_especialidad'=>2]]; }
        if (str_contains($this->sql,'FROM public.horarios_atencion')) { return $this->db->hours; }
        if (str_contains($this->sql,'FROM public.bloqueos_horario')) { return $this->db->blocks; }
        if (str_contains($this->sql,'FROM public.citas')) { return $this->db->appointments; }
        throw new RuntimeException('Consulta no prevista por la simulación P6.');
    }
}
$n=0;
$check=static function(bool $ok,string $label)use(&$n): void { if (!$ok) { throw new RuntimeException('FALLO P6: '.$label); } ++$n; };
$db=new P6ReadSimulation(); $repo=new ReportRepository($db);
$hours=new ReflectionMethod($repo,'hoursForSchedules'); $hours->setAccessible(true);
$check($hours->invoke($repo,[['hora_inicio'=>'08:00','hora_fin'=>'12:00','id_medico'=>1,'dia_semana'=>1],['hora_inicio'=>'10:00','hora_fin'=>'14:00','id_medico'=>1,'dia_semana'=>1]])===6.0,'Unión de intervalos superpuestos');
$check($hours->invoke($repo,[['hora_inicio'=>'08:00','hora_fin'=>'12:00','dia_semana'=>1],['hora_inicio'=>'08:00','hora_fin'=>'12:00','dia_semana'=>2]])===8.0,'No unir distintos días');
$check($hours->invoke($repo,[])===0.0,'Horas vacías');
$day=(new DateTimeImmutable('next monday',new DateTimeZone('America/La_Paz')))->format('Y-m-d');
$db->hours=[['id_horario'=>1,'id_medico'=>1,'dia_semana'=>1,'hora_inicio'=>'08:00:00','hora_fin'=>'12:00:00','duracion_cita_minutos'=>30,'estado'=>'activo']];
$db->appointments=[['id_cita'=>1,'id_medico'=>1,'id_especialidad'=>1,'fecha'=>$day,'hora_inicio'=>'08:00:00','hora_fin'=>'08:30:00','estado'=>'atendida'],['id_cita'=>2,'id_medico'=>1,'id_especialidad'=>2,'fecha'=>$day,'hora_inicio'=>'08:30:00','hora_fin'=>'09:00:00','estado'=>'programada'],['id_cita'=>3,'id_medico'=>1,'id_especialidad'=>1,'fecha'=>$day,'hora_inicio'=>'09:00:00','hora_fin'=>'09:30:00','estado'=>'cancelada'],['id_cita'=>4,'id_medico'=>1,'id_especialidad'=>1,'fecha'=>$day,'hora_inicio'=>'09:30:00','hora_fin'=>'10:00:00','estado'=>'inasistencia']];
$plan=new ReflectionMethod($repo,'planning'); $plan->setAccessible(true);
$f=ReportFilters::parse(['fecha_inicio'=>$day,'fecha_fin'=>$day]);
$r=$plan->invoke($repo,$f,[]);
$check($r['workload'][0]['horas_programadas_periodo']===4.0,'Capacidad periodo');
$check($r['workload'][0]['horas_ocupadas']===1.5 && $r['workload'][0]['horas_atendidas']===.5,'Ocupación sin canceladas y atención real');
$check($r['workload'][0]['horas_disponibles']===2.5,'Cancelación libera franja');
$check(array_sum(array_column($r['specialty_workload'],'horas_programadas_periodo'))===4.0,'Reparto no duplica capacidad');
$check($r['specialty_workload'][0]['horas_ocupadas']===1.0 && $r['specialty_workload'][1]['horas_ocupadas']===.5,'Horas reales por especialidad');
$filtered=$plan->invoke($repo,array_replace($f,['especialidad'=>1,'estado'=>'atendida']),[]);
$check(count($filtered['specialty_workload'])===1,'Filtro especialidad');
$check($filtered['workload'][0]['horas_ocupadas']===.5 && $filtered['workload'][0]['citas_programadas']===1,'Filtro de estado y especialidad en carga médica');
$check($filtered['specialty_workload'][0]['citas_programadas']===1 && $filtered['specialty_workload'][0]['horas_atendidas']===.5,'Filtro de estado en carga especialidad');
$check($filtered['workload'][0]['horas_disponibles']===2.5,'Disponibilidad respeta toda la agenda pese al filtro');
$db->blocks=[['id_bloqueo'=>1,'id_medico'=>1,'fecha'=>$day,'hora_inicio'=>'10:00:00','hora_fin'=>'10:30:00','motivo'=>'Prueba']];
$r=$plan->invoke($repo,$f,[]);
$check($r['workload'][0]['horas_disponibles']===2.0 && $r['daily'][0]['inasistencias']===1,'Bloqueo resta capacidad, no suma inasistencia');
$db->appointments=[]; $db->blocks=[];
$r=$plan->invoke($repo,$f,[]);
$check($r['workload'][0]['horas_ocupadas']===0.0 && $r['workload'][0]['horas_atendidas']===0.0 && $r['workload'][0]['horas_disponibles']===4.0,'Cero citas con horario real');
$range=new ReflectionMethod($repo,'planningRange'); $range->setAccessible(true);
$check($range->invoke($repo,ReportFilters::parse(['fecha_fin'=>'2026-01-01']))===['2026-01-01','2026-01-01'],'Un extremo temporal válido');
$check(count($db->queries)>0 && !array_filter($db->queries,static fn(string $q): bool=>!str_starts_with($q,'SELECT ')),'Solo consultas de lectura');
echo "$n regresiones P6 de cálculo pasaron con PDO simulado, sin PostgreSQL.\n";
