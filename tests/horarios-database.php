<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\Connection;
use Clinica\Repositories\HorarioRepository;
use Clinica\Repositories\UserRepository;
use Clinica\Services\HorarioService;
use Clinica\Services\DisponibilidadService as A;
use Clinica\Services\ScheduleRules;
try{
    $pdo=Connection::readOnly(require dirname(__DIR__).'/backend/config/database.php');$pdo->beginTransaction();
    if($pdo->query('SELECT current_database()')->fetchColumn()!=='clinica_vida_salud' || $pdo->query('SHOW transaction_read_only')->fetchColumn()!=='on'){throw new RuntimeException();}
    $snapshot=static function()use($pdo): array{$a=[];foreach(['horarios_atencion','bloqueos_horario','citas','medicos','registros_auditoria'] as $table){$a[$table]=hash('sha256',(string)$pdo->query("SELECT COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text)::text,'[]') FROM public.$table t")->fetchColumn());}return $a;};$before=$snapshot();
    $repo=new HorarioRepository($pdo);$service=new HorarioService($repo);$admin=['id'=>1,'role'=>'Administrador','permissions'=>['consultar_agenda']];$all=$service->catalog($admin);
    if(count($all['hours'])!==90 || count($all['doctors'])!==18 || count($all['blocks'])!==0){throw new RuntimeException();}
    echo "PostgreSQL: 90 horarios, 18 médicos relacionados y 0 bloqueos.\n";
    $grants=$pdo->query("SELECT r.nombre AS rol,p.nombre AS permiso FROM public.roles r JOIN public.rol_permiso rp ON rp.id_rol=r.id_rol JOIN public.permisos p ON p.id_permiso=rp.id_permiso WHERE p.nombre IN ('consultar_agenda','gestionar_horarios','gestionar_bloqueos') ORDER BY r.nombre,p.nombre")->fetchAll();
    $actual=[];foreach($grants as $grant){$actual[$grant['rol']][]=$grant['permiso'];}
    if($actual!==['Administrador'=>['consultar_agenda','gestionar_bloqueos','gestionar_horarios'],'Medico'=>['consultar_agenda'],'Recepcionista'=>['consultar_agenda']]){throw new RuntimeException();}
    echo "Permisos actuales: Administrador gestiona; Recepcionista consulta; Médico consulta su propio ámbito.\n";
    foreach(range(1,5) as $day){if(count($service->catalog($admin,null,null,$day)['hours'])!==18){throw new RuntimeException();}}
    foreach($all['doctors'] as $doctor){if(count($service->catalog($admin,(int)$doctor['id_medico'])['hours'])!==5){throw new RuntimeException();}}
    foreach($repo->specialties() as $specialty){$stmt=$pdo->prepare('SELECT count(*) FROM public.horarios_atencion h WHERE EXISTS (SELECT 1 FROM public.medico_especialidad me WHERE me.id_medico=h.id_medico AND me.id_especialidad=:id)');$stmt->execute(['id'=>$specialty['id_especialidad']]);if(count($service->catalog($admin,null,(int)$specialty['id_especialidad'])['hours'])!==(int)$stmt->fetchColumn()){throw new RuntimeException();}}
    $users=new UserRepository($pdo);$row=$users->findByLogin('profesional01');$physician=['id'=>(int)$row['id_usuario'],'role'=>'Medico','permissions'=>$users->permissions((int)$row['id_rol'])];$own=$service->catalog($physician);if(count($own['doctors'])!==1 || count($own['hours'])!==5){throw new RuntimeException();}
    $other=array_values(array_diff(array_column($all['doctors'],'id_medico'),array_column($own['doctors'],'id_medico')))[0];try{$service->catalog($physician,(int)$other);throw new RuntimeException();}catch(DomainException $expected){}
    echo "Relaciones, 5 días, 18 filtros de médico y 9 de especialidad: correctos. Médico restringido a 5 horarios propios.\n";
    $now=new DateTimeImmutable($pdo->query("SELECT (now() AT TIME ZONE 'America/La_Paz')::text")->fetchColumn(),new DateTimeZone('America/La_Paz'));$dates=A::window($now);
    $window=$pdo->query("SELECT d::date::text FROM generate_series((now() AT TIME ZONE 'America/La_Paz')::date::timestamp,((now() AT TIME ZONE 'America/La_Paz')::date+20)::timestamp,INTERVAL '1 day') d WHERE EXTRACT(ISODOW FROM d) BETWEEN 1 AND 5 ORDER BY d LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);if($dates!==$window){throw new RuntimeException();}
    $ids=array_map('intval',array_column($all['doctors'],'id_medico'));$appointments=$repo->appointments($ids);$engine=new A();$free=0;$verified=0;
    $sql="WITH slots AS (SELECT h.id_horario,h.id_medico,h.hora_inicio AS anchor,h.hora_fin AS finish,h.duracion_cita_minutos,s AS inicio,s+make_interval(mins=>h.duracion_cita_minutos) AS fin FROM public.horarios_atencion h JOIN public.medicos m ON m.id_medico=h.id_medico CROSS JOIN LATERAL generate_series(CAST(:date1 AS date)+h.hora_inicio,CAST(:date2 AS date)+h.hora_fin-make_interval(mins=>h.duracion_cita_minutos),make_interval(mins=>h.duracion_cita_minutos)) s WHERE h.id_medico=:doctor AND h.estado='activo' AND m.estado='activo' AND h.dia_semana=EXTRACT(ISODOW FROM CAST(:date3 AS date))) SELECT inicio::time::text AS hora_inicio,fin::time::text AS hora_fin FROM slots s WHERE inicio>(now() AT TIME ZONE 'America/La_Paz') AND NOT EXISTS (SELECT 1 FROM public.bloqueos_horario b WHERE b.id_medico=s.id_medico AND b.fecha=inicio::date AND inicio::time<b.hora_fin AND fin::time>b.hora_inicio) AND NOT EXISTS (SELECT 1 FROM public.citas c WHERE c.id_medico=s.id_medico AND c.fecha=inicio::date AND c.estado<>'cancelada' AND inicio::time<c.hora_fin AND fin::time>c.hora_inicio) AND NOT EXISTS (SELECT 1 FROM public.horarios_atencion h2 WHERE h2.id_medico=s.id_medico AND h2.id_horario<>s.id_horario AND h2.estado='activo' AND h2.dia_semana=EXTRACT(ISODOW FROM inicio) AND h2.hora_inicio<s.finish AND h2.hora_fin>s.anchor) ORDER BY inicio";
    $stmt=$pdo->prepare($sql);
    foreach($all['doctors'] as $doctor){foreach($dates as $date){$result=$engine->day($doctor,$date,$all['hours'],$all['blocks'],$appointments,$now);$php=[];foreach($result['franjas'] as $slot){if($slot['estado']==='disponible'){$php[]=$slot['hora_inicio'].'-'.$slot['hora_fin'];}}$stmt->execute(['date1'=>$date,'date2'=>$date,'date3'=>$date,'doctor'=>$doctor['id_medico']]);$database=array_map(static fn(array $r): string=>$r['hora_inicio'].'-'.$r['hora_fin'],$stmt->fetchAll());if($php!==$database){throw new RuntimeException();}$free+=count($php);++$verified;}}
    echo "Disponibilidad PHP contrastada con SELECT independiente para $verified médico-días: $free franjas libres al momento de la prueba. Ventana idéntica al trigger; sin reservar.\n";
    $protected=0;foreach($all['hours'] as $h){$deactivate=$h;$deactivate['estado']='inactivo';if(isset(ScheduleRules::conflicts('horario',$deactivate,(int)$h['id_horario'],$all['hours'],$all['blocks'],$appointments)['_form'])){++$protected;}}
    if($protected===0 || $before!==$snapshot()){throw new RuntimeException();}$pdo->rollBack();echo "Protección detectada al simular desactivación en $protected horarios con citas. Tablas sin cambios; ROLLBACK de lectura.\n";
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,"Falló la integración P4. Detalles omitidos para proteger datos.\n");exit(1);}
