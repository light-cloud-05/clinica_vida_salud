<?php
declare(strict_types=1);
// Fixtures privadas de sesión para HTTP: CLI, sin contraseñas y sin escrituras SQL.
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__,2).'/backend/bootstrap.php';
use Clinica\Repositories\Connection;
use Clinica\Repositories\UserRepository;
use Clinica\Services\AuthService;
use Clinica\Services\SessionService;
use Clinica\Support\Environment;

$path=dirname(__DIR__,2).'/var/patient-http-fixtures.json';
function snapshots(PDO $pdo): array {
    $result=[];
    $tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as $table) {
        $identifier='public."'.str_replace('"','""',$table).'"';
        $result[$table]=hash('sha256',(string)$pdo->query('SELECT COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text)::text,\'[]\') FROM '.$identifier.' t')->fetchColumn());
    }
    $result['sequences']=hash('sha256',(string)$pdo->query("SELECT json_agg(s ORDER BY sequencename)::text FROM (SELECT sequencename,last_value FROM pg_sequences WHERE schemaname='public') s")->fetchColumn());
    return $result;
}
try {
    if(Environment::get('APP_ENV')!=='local' || $argc!==2 || !in_array($argv[1],['create','cleanup'],true)) {throw new RuntimeException();}
    $pdo=Connection::readOnly(require dirname(__DIR__,2).'/backend/config/database.php');
    if($pdo->query('SELECT current_database()')->fetchColumn()!=='clinica_vida_salud') {throw new RuntimeException();}
    if($argv[1]==='create') {
        if(is_file($path)) {throw new RuntimeException();}
        $fixtures=['snapshot'=>snapshots($pdo),'sessions'=>[], 'patient_id'=>(int)$pdo->query('SELECT min(id_paciente) FROM public.pacientes')->fetchColumn()];
        $fixtures['doctor_id']=(int)$pdo->query('SELECT min(id_medico) FROM public.medicos')->fetchColumn();
        $fixtures['specialty_id']=(int)$pdo->query('SELECT min(id_especialidad) FROM public.especialidades')->fetchColumn();
        $fixtures['schedule']=$pdo->query('SELECT id_horario,id_medico,dia_semana,hora_inicio,hora_fin,duracion_cita_minutos,estado,updated_at FROM public.horarios_atencion ORDER BY id_horario LIMIT 1')->fetch();
        $stmt=$pdo->prepare('SELECT id_especialidad FROM public.medico_especialidad WHERE id_medico=:id ORDER BY id_especialidad');$stmt->execute(['id'=>$fixtures['doctor_id']]);
        $fixtures['doctor_specialties']=array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt=$pdo->prepare('SELECT count(*) FROM public.medico_especialidad WHERE id_especialidad=:id');$stmt->execute(['id'=>$fixtures['specialty_id']]);$fixtures['filtered_count']=(int)$stmt->fetchColumn();
        file_put_contents($path,json_encode($fixtures,JSON_THROW_ON_ERROR),LOCK_EX);
        $repository=new UserRepository($pdo);$auth=new AuthService($repository);
        foreach(['usuario1'=>'Administrador','usuario2'=>'Recepcionista','profesional01'=>'Medico','paciente01'=>'Paciente'] as $username=>$role) {
            $row=$repository->findByLogin($username);
            if(!$row || $row['estado']!=='activo' || !is_string($row['contrasena_hash']) || $row['rol']!==$role) {throw new RuntimeException();}
            $identity=$auth->current((int)$row['id_usuario'],hash('sha256',$row['contrasena_hash']));
            if(!$identity) {throw new RuntimeException();}
            SessionService::start(); SessionService::login($identity);
            $fixtures['sessions'][$role]=['id'=>session_id(),'csrf'=>SessionService::csrf()];
            $citaRepo=new Clinica\Repositories\CitaRepository($pdo);
            $rows=$citaRepo->listing($identity,[],100,0);
            $fixtures['appointments'][$role]=['total'=>$citaRepo->count($identity,[]),'ids'=>array_column($rows,'id_cita'),'codes'=>array_column($rows,'codigo_cita'),'sample'=>$rows ? array_intersect_key($rows[0],array_flip(['id_cita','codigo_cita','id_medico','id_paciente','id_especialidad','fecha','estado'])) : null];
            if($role==='Paciente'){
                $payRepo=new Clinica\Repositories\PaymentRepository($pdo);
                $fixtures['payment_appointments']=[];
                foreach($payRepo->candidates($identity) as $candidate){
                    $c=$payRepo->appointment($identity,(int)$candidate['id_cita']);
                    $fixtures['payment_appointments'][]=array_intersect_key($c,array_flip(['id_cita','fecha_registro','fecha','hora_inicio','hora_fin','precio_consulta','id_paciente']));
                }
            }
            if(in_array($role,['Medico','Paciente'],true)){
                $alias=$role==='Medico' ? 'm' : 'p';
                $stmt=$pdo->prepare('SELECT c.id_cita,c.id_paciente FROM public.citas c JOIN public.medicos m ON m.id_medico=c.id_medico JOIN public.pacientes p ON p.id_paciente=c.id_paciente WHERE '.$alias.'.id_usuario<>:id OR '.$alias.'.id_usuario IS NULL ORDER BY c.id_cita LIMIT 1');$stmt->execute(['id'=>$identity['id']]);$fixtures['appointments'][$role]['foreign']=$stmt->fetch();
            }
            $choices=$citaRepo->availabilityChoices($identity);$m=$choices['doctors'][0];$date=Clinica\Services\DisponibilidadService::window(new DateTimeImmutable('now',new DateTimeZone('America/La_Paz')))[1];
            $availability=(new Clinica\Services\CitaService($citaRepo))->availability($identity,(int)$m['id_medico'],(int)$m['especialidades'][0],$date);
            $free=array_values(array_filter($availability['slots'],static fn(array $s): bool=>$s['available']));
            $fixtures['availability'][$role]=['doctor'=>$m['id_medico'],'specialty'=>$m['especialidades'][0],'date'=>$date,'time'=>$free[0]['hora_inicio'] ?? null];
            if($role==='Medico'){
                $stmt=$pdo->prepare('SELECT id_medico FROM public.medicos WHERE id_usuario=:id');$stmt->execute(['id'=>$identity['id']]);$fixtures['physician_doctor']=(int)$stmt->fetchColumn();
                $stmt=$pdo->prepare('SELECT min(id_medico) FROM public.medicos WHERE id_medico<>:id');$stmt->execute(['id'=>$fixtures['physician_doctor']]);$fixtures['other_doctor']=(int)$stmt->fetchColumn();
            }
            session_write_close(); session_id('');
            file_put_contents($path,json_encode($fixtures,JSON_THROW_ON_ERROR),LOCK_EX);
        }
        echo "Sesiones privadas de prueba preparadas; ninguna cuenta modificada.\n";
    } elseif(is_file($path)) {
        $fixtures=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        $same=$fixtures['snapshot']===snapshots($pdo);
        foreach($fixtures['sessions'] as $session) {
            if(!preg_match('/^[a-zA-Z0-9,-]+$/D',$session['id'])) {throw new RuntimeException();}
            $file=dirname(__DIR__,2).'/var/sessions/sess_'.$session['id'];
            if(is_file($file)) {unlink($file);}
        }
        unlink($path);
        if(!$same) {throw new RuntimeException();}
        echo "Fixtures eliminadas: tablas protegidas, auditoría y secuencias sin cambios.\n";
    }
} catch(Throwable $e) {
    if(session_status()===PHP_SESSION_ACTIVE) {session_write_close();}
    fwrite(STDERR,"Falló la preparación/verificación HTTP. Verifique que las cuatro cuentas de prueba estén activas y que no haya cambios concurrentes. Detalles omitidos.\n");exit(1);
}
