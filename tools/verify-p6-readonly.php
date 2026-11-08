<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0');
set_exception_handler(static function(Throwable $e): void { fwrite(STDERR,"Verificación P6 detenida; no se muestran datos privados ni errores de conexión.\n"); exit(1); });
require dirname(__DIR__).'/backend/bootstrap.php';
$config=require dirname(__DIR__).'/backend/config/database.php';
if (!in_array($config['host'],['127.0.0.1','localhost','::1'],true) || $config['database']!=='clinica_vida_salud') { throw new RuntimeException('Solo origen local autorizado.'); }
$pdo=Clinica\Repositories\Connection::readOnly($config);
$pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
try {
    if ($pdo->query('SELECT current_database()')->fetchColumn()!=='clinica_vida_salud' || $pdo->query('SHOW transaction_read_only')->fetchColumn()!=='on') { throw new RuntimeException('Solo lectura requerida.'); }
    $n=0; $check=static function(bool $ok)use(&$n): void { if(!$ok) { throw new RuntimeException('Cálculo P6 incorrecto.'); } ++$n; };
    $range=$pdo->query('SELECT min(fecha) AS desde,max(fecha) AS hasta FROM public.citas')->fetch();
    $from=$range['desde']??date('Y-m-d'); $to=$range['hasta']??$from;
    $base=['fecha_inicio'=>$from,'fecha_fin'=>$to];
    $choices=(new Clinica\Repositories\ReportRepository($pdo))->choices();
    $scenarios=[$base,$base+['estado'=>'atendida'],$base+['estado'=>'inasistencia'],$base+['estado'=>'cancelada']];
    if($choices['specialties']) { $scenarios[]=$base+['especialidad'=>(string)$choices['specialties'][0]['id_especialidad']]; }
    if($choices['doctors']) { $scenarios[]=$base+['medico'=>(string)$choices['doctors'][0]['id_medico']]; }
    $scenarios[]=['fecha_inicio'=>'1900-01-01','fecha_fin'=>'1900-01-01'];
    foreach($scenarios as $query) {
        $f=Clinica\Services\ReportFilters::parse($query);
        $r=(new Clinica\Repositories\ReportRepository($pdo))->dashboard($f);
        $where=['fecha BETWEEN :from AND :to']; $params=['from'=>$f['fecha_inicio'],'to'=>$f['fecha_fin']];
        foreach(['medico'=>'id_medico','especialidad'=>'id_especialidad','estado'=>'estado'] as $filter=>$column) { if($f[$filter]!==null) { $where[]=$column.'=:'.$filter; $params[$filter]=$f[$filter]; } }
        $condition=implode(' AND ',$where);
        $aggregate=$pdo->prepare('SELECT count(*) AS total,count(*) FILTER (WHERE estado=\'atendida\') AS atendidas,count(*) FILTER (WHERE estado=\'inasistencia\') AS inasistencias FROM public.citas WHERE '.$condition);
        $aggregate->execute($params); $a=$aggregate->fetch();
        $check((int)$a['total']===count($r['appointments']) && (int)$a['atendidas']===(int)$r['attendance']['atendidas'] && (int)$a['inasistencias']===(int)$r['attendance']['inasistencias']);
        foreach($r['specialties'] as $row) {
            $s=$pdo->prepare('SELECT count(*) FROM public.citas WHERE '.$condition.' AND id_especialidad=:expected_specialty');
            $s->execute($params+['expected_specialty'=>$row['id_especialidad']]);
            $check((int)$s->fetchColumn()===(int)$row['citas']);
        }
        foreach(['workload'=>'id_medico','specialty_workload'=>'id_especialidad'] as $collection=>$column) {
            foreach($r[$collection] as $row) {
                $s=$pdo->prepare("SELECT COALESCE(sum(EXTRACT(EPOCH FROM (hora_fin-hora_inicio))/3600) FILTER (WHERE estado<>'cancelada'),0) AS occupied,COALESCE(sum(EXTRACT(EPOCH FROM (hora_fin-hora_inicio))/3600) FILTER (WHERE estado='atendida'),0) AS attended FROM public.citas WHERE ".$condition.' AND '.$column.'=:expected_id');
                $s->execute($params+['expected_id'=>$row[$column]]); $expected=$s->fetch();
                $check(abs((float)$expected['occupied']-(float)$row['horas_ocupadas'])<.011 && abs((float)$expected['attended']-(float)$row['horas_atendidas'])<.011);
            }
        }
        foreach($r['workload'] as $row) {
            $s=$pdo->prepare("SELECT COALESCE(sum(EXTRACT(EPOCH FROM(h.hora_fin-h.hora_inicio))/3600),0) FROM public.horarios_atencion h JOIN generate_series(CAST(:from AS date)::timestamp,CAST(:to AS date)::timestamp,interval '1 day') d ON EXTRACT(ISODOW FROM d)=h.dia_semana WHERE h.id_medico=:doctor AND h.estado='activo'");
            $s->execute(['from'=>$f['fecha_inicio'],'to'=>$f['fecha_fin'],'doctor'=>$row['id_medico']]);
            // The local fixture has no overlapping schedules; union behaviour is tested in memory.
            $overlap=$pdo->prepare("SELECT count(*) FROM public.horarios_atencion a JOIN public.horarios_atencion b ON a.id_medico=b.id_medico AND a.dia_semana=b.dia_semana AND a.id_horario<b.id_horario AND a.hora_inicio<b.hora_fin AND a.hora_fin>b.hora_inicio WHERE a.id_medico=:doctor AND a.estado='activo' AND b.estado='activo'");
            $overlap->execute(['doctor'=>$row['id_medico']]);
            $check((int)$overlap->fetchColumn()===0 && abs((float)$s->fetchColumn()-(float)$row['horas_programadas_periodo'])<.011);
            $free=$pdo->prepare("SELECT COALESCE(sum(EXTRACT(EPOCH FROM (slot.fin-slot.inicio))/3600),0) FROM (SELECT DISTINCT g AS inicio,g+make_interval(mins=>h.duracion_cita_minutos) AS fin FROM public.horarios_atencion h JOIN public.medicos m USING(id_medico) CROSS JOIN generate_series(CAST(:from AS date)::timestamp,CAST(:to AS date)::timestamp,interval '1 day') d CROSS JOIN LATERAL generate_series(d::date+h.hora_inicio,d::date+h.hora_fin-make_interval(mins=>h.duracion_cita_minutos),make_interval(mins=>h.duracion_cita_minutos)) g WHERE h.id_medico=:doctor AND h.estado='activo' AND m.estado='activo' AND EXTRACT(ISODOW FROM d)=h.dia_semana AND g>now() AT TIME ZONE 'America/La_Paz' AND NOT EXISTS(SELECT 1 FROM public.citas c WHERE c.id_medico=h.id_medico AND c.fecha=d::date AND c.estado<>'cancelada' AND c.fecha+c.hora_inicio<g+make_interval(mins=>h.duracion_cita_minutos) AND c.fecha+c.hora_fin>g) AND NOT EXISTS(SELECT 1 FROM public.bloqueos_horario b WHERE b.id_medico=h.id_medico AND b.fecha=d::date AND b.fecha+b.hora_inicio<g+make_interval(mins=>h.duracion_cita_minutos) AND b.fecha+b.hora_fin>g)) slot");
            $free->execute(['from'=>$f['fecha_inicio'],'to'=>$f['fecha_fin'],'doctor'=>$row['id_medico']]);
            $check(abs((float)$free->fetchColumn()-(float)$row['horas_disponibles'])<.011);
        }
        $csv=Clinica\Services\ReportExport::csv($r); $pdf=Clinica\Services\ReportExport::pdf($r,$f);
        $check(str_starts_with($csv,"\xEF\xBB\xBF") && str_contains($csv,'CARGA HORARIA') && str_starts_with($pdf,'%PDF-'));
        if($query===$base && in_array('--preview',$argv,true)) {
            $data=['filters'=>$f,'choices'=>$choices,'report'=>$r];
            $escape=static fn(mixed $value): string=>htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $title='Reportes administrativos';
            ob_start(); require dirname(__DIR__).'/frontend/views/reportes/index.php'; $html=ob_get_clean();
            $dir=dirname(__DIR__).'/var/visual-review'; if(!is_dir($dir)) { mkdir($dir,0775,true); }
            file_put_contents($dir.'/reportes.html','<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Vista previa local P6</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><style>'.file_get_contents(dirname(__DIR__).'/public/assets/css/app.css').'</style><main class="patient-main">'.$html.'</main></html>');
        }
    }
    foreach(['Paciente'=>['pacientes','id_paciente'],'Medico'=>['medicos','id_medico']] as $role=>[$table,$key]) {
        $id=$pdo->query('SELECT id_usuario FROM public.'.$table.' WHERE id_usuario IS NOT NULL ORDER BY '.$key.' LIMIT 1')->fetchColumn();
        if($id===false) {continue;}
        $actor=['id'=>(int)$id,'role'=>$role,'permissions'=>['generar_reportes','exportar_reportes']];
        $service=new Clinica\Services\ReportService(new Clinica\Repositories\ReportRepository($pdo));
        $result=$service->dashboard($actor,$base+['_scope'=>['role'=>'Administrador']]);
        $r=$result['report'];
        $expected=$pdo->prepare('SELECT count(*) FROM public.citas WHERE fecha BETWEEN :from AND :to AND '.$key.' IN (SELECT '.$key.' FROM public.'.$table.' WHERE id_usuario=:user)');
        $expected->execute(['from'=>$from,'to'=>$to,'user'=>$id]);
        $check(count($r['appointments'])===(int)$expected->fetchColumn());
        $export=$service->dashboard($actor,$base,'exportar_reportes');
        $check(array_column($r['appointments'],'id_cita')===array_column($export['report']['appointments'],'id_cita'));
        $check($r['scope_label']==='Resultados personales');
        if($role==='Paciente') {$check($r['blocks']===[] && $r['workload']===[]);}
        else {$own=$pdo->prepare('SELECT id_medico FROM public.medicos WHERE id_usuario=:user');$own->execute(['user'=>$id]);$allowed=array_map('intval',$own->fetchAll(PDO::FETCH_COLUMN));foreach($r['workload'] as $row){$check(in_array((int)$row['id_medico'],$allowed,true));}}
    }    $pdo->rollBack();
    echo "$n comprobaciones P6 contra PostgreSQL local: conteos, especialidades, horas programadas/ocupadas/atendidas/libres, filtros y CSV/PDF OK. Sesión READ ONLY; sin escrituras.\n";
} finally { if($pdo->inTransaction()) { $pdo->rollBack(); } }
