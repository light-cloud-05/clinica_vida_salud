<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
require dirname(__DIR__).'/tools/integration-bootstrap.php';
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Support\{IntegrationGuard,Environment};
use Clinica\Repositories\{Connection,UserRepository,CitaRepository,ReservaRepository,CitaChangeRepository,PaymentWriteRepository,PaymentRepository};
use Clinica\Services\{AuthService,ReservaService,CitaService,CitaChangeService,CitaChangePolicy,DisponibilidadService,PayPalSandboxService};
$dir=dirname(__DIR__).'/var/integration';$report=[];
function ok(bool $condition,string $label):void{global $report,$dir;if(!$condition){throw new RuntimeException('FALLO: '.$label);}$report[]=$label;file_put_contents($dir.'/results.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo 'OK: '.$label.PHP_EOL;}
function denied(callable $work,string $label):void{try{$work();}catch(DomainException|Clinica\Services\BookingConflict $e){ok(true,$label);return;}throw new RuntimeException('No se rechazó: '.$label);}
try{
    $pdo=Connection::integration(require dirname(__DIR__).'/backend/config/database.php');IntegrationGuard::check($pdo);
    $users=new UserRepository($pdo);$auth=new AuthService($users);$actors=[];
    foreach(['usuario1'=>'Administrador','usuario2'=>'Recepcionista','profesional01'=>'Medico'] as $name=>$role){$u=$users->findByLogin($name);$actors[$role]=$auth->current((int)$u['id_usuario'],hash('sha256',$u['contrasena_hash']));}
    if(!is_file($dir.'/patient.json')){
        $suffix=bin2hex(random_bytes(5));$password=bin2hex(random_bytes(10));
        IntegrationGuard::check($pdo);$pdo->beginTransaction();
        $s=$pdo->prepare("SELECT set_config('app.usuario_id',:id,true)");$s->execute(['id'=>(string)$actors['Administrador']['id']]);
        $s=$pdo->prepare("INSERT INTO public.usuarios(id_rol,nombre_usuario,correo_acceso,contrasena_hash,estado) SELECT id_rol,:name,:email,:hash,'activo' FROM public.roles WHERE nombre='Paciente' RETURNING id_usuario");
        $s->execute(['name'=>'prueba_'.$suffix,'email'=>'prueba_'.$suffix.'@example.invalid','hash'=>password_hash($password,PASSWORD_DEFAULT)]);$user=(int)$s->fetchColumn();
        $s=$pdo->prepare("INSERT INTO public.pacientes(id_usuario,codigo_paciente,documento_identidad,nombres,apellidos) VALUES(:user,:code,:doc,'Paciente','Prueba integral') RETURNING id_paciente");$s->execute(['user'=>$user,'code'=>'TEST-'.$suffix,'doc'=>'TEST-'.$suffix]);$patient=(int)$s->fetchColumn();$pdo->commit();
        file_put_contents($dir.'/patient.json',json_encode(['login'=>'prueba_'.$suffix,'password'=>$password,'user'=>$user,'patient'=>$patient]));
    }
    $fixture=json_decode(file_get_contents($dir.'/patient.json'),true);$actors['Paciente']=$auth->authenticate($fixture['login'],$fixture['password']);
    file_put_contents($dir.'/actors.json',json_encode($actors));
    ok($actors['Paciente']!==null && $fixture['user']!==$fixture['patient'],'Paciente y usuario NUEVOS, vinculados sin confundir IDs');
    $read=new CitaService(new CitaRepository($pdo));$booking=new ReservaService(new ReservaRepository($pdo),true);$changeRepo=new CitaChangeRepository($pdo);$changes=new CitaChangeService($changeRepo,true);
    $findSlot=static function()use($read,$actors):array{
        $now=new DateTimeImmutable('now',new DateTimeZone('America/La_Paz'));$choices=$read->availabilityChoices($actors['Paciente']);
        foreach(DisponibilidadService::window($now) as $day){foreach($choices['doctors'] as $doctor){if($doctor['estado']!=='activo'){continue;}$specialty=$doctor['especialidades'][0] ?? null;if(!$specialty){continue;}$data=$read->availability($actors['Paciente'],$doctor['id_medico'],$specialty,$day);
            foreach($data['slots'] as $slot){if($slot['available']){return ['medico'=>(string)$doctor['id_medico'],'especialidad'=>(string)$specialty,'fecha'=>$day,'hora'=>$slot['hora_inicio']];}}
        }}throw new RuntimeException('No hay turno de prueba disponible.');
    };
    $input=$findSlot();$saved=$booking->reserve($actors['Paciente'],$input);$id=$saved['id'];
    file_put_contents($dir.'/last-cita.json',json_encode(['id'=>$id,'input'=>$input]));
    ok($id>60,'P5-B INSERT/COMMIT real de cita nueva');
    $listing=(new CitaRepository($pdo))->listing($actors['Paciente'],[],100,0);ok(in_array($id,array_map('intval',array_column($listing,'id_cita')),true),'Cita nueva visible en agenda propia');
    $availability=$read->availability($actors['Paciente'],(int)$input['medico'],(int)$input['especialidad'],$input['fecha'],$input['hora']);ok($availability['selected']===null,'Disponibilidad descuenta cita persistida');
    denied(fn()=>$booking->reserve($actors['Paciente'],$input),'Reserva repetida rechazada, sin duplicados');
    $next=$findSlot();$c=$changeRepo->find($actors['Paciente'],$id);
    $changes->change($actors['Paciente'],'reprogramar',['id'=>(string)$id,'version'=>CitaChangePolicy::version($c),'fecha'=>$next['fecha'],'hora'=>$next['hora']]);
    $c=$changeRepo->find($actors['Paciente'],$id);ok((int)$c['cantidad_reprogramaciones']===1,'Trigger incrementa única reprogramación');
    denied(fn()=>$changes->change($actors['Paciente'],'reprogramar',['id'=>(string)$id,'version'=>CitaChangePolicy::version($c),'fecha'=>$input['fecha'],'hora'=>$input['hora']]),'Segunda reprogramación rechazada');
    $changes->change($actors['Paciente'],'cancelar',['id'=>(string)$id,'version'=>CitaChangePolicy::version($c),'motivo'=>'Prueba integral aislada']);
    ok($changeRepo->find($actors['Paciente'],$id)['estado']==='cancelada','Cancelación persistente de cita nueva');
    $s=$pdo->prepare('SELECT count(*) FROM public.historial_cambios_cita WHERE id_cita=:id');$s->execute(['id'=>$id]);ok((int)$s->fetchColumn()>=2,'Historial real registra reprogramación y cancelación');
    $s=$pdo->prepare("SELECT count(*) FROM public.registros_auditoria WHERE entidad_afectada='citas' AND id_entidad=:id AND id_usuario=:actor");$s->execute(['id'=>$id,'actor'=>$actors['Paciente']['id']]);ok((int)$s->fetchColumn()>=3,'Auditoría real conserva actor en INSERT y UPDATE');
    foreach(['Administrador','Recepcionista'] as $role){$slot=$findSlot();$slot['paciente']=(string)$fixture['patient'];$r=$booking->reserve($actors[$role],$slot);ok($r['id']>60,$role.' reserva para paciente nuevo');}
    denied(fn()=>$booking->reserve($actors['Medico'],$findSlot()),'Médico no reserva');
    denied(fn()=>$booking->reserve($actors['Paciente'],$findSlot()+['paciente'=>1]),'Paciente no suplanta titular');
    $repoPay=new PaymentWriteRepository($pdo,new PayPalSandboxService('','','',''));
    $payIds=[];
    foreach(['qr_simulado','paypal_sandbox','efectivo'] as $method){
        $slot=$findSlot();$r=$booking->reserve($actors['Paciente'],$slot);putenv('PAYMENT_ALLOWED_APPOINTMENTS='.$r['id']);
        $pid=$repoPay->start($actors['Paciente'],$r['id'],$method,'demo');$payIds[$method]=['appointment'=>$r['id'],'payment'=>$pid];
        ok($pid>0,'Inicio persistente '.$method);
        if($method==='efectivo'){denied(fn()=>$repoPay->confirm($actors['Paciente'],$r['id'],$pid),'Paciente no confirma efectivo');denied(fn()=>$repoPay->confirm($actors['Recepcionista'],$r['id'],$pid),'Caja rechaza cobro fuera de horario real');continue;}
        ok($repoPay->confirm($actors['Paciente'],$r['id'],$pid),'Confirmación persistente simulada '.$method);
        ok($repoPay->confirm($actors['Paciente'],$r['id'],$pid),'Confirmación repetida idempotente '.$method);
        $s=$pdo->prepare('SELECT count(*) FROM public.comprobantes_pago WHERE id_pago=:id');$s->execute(['id'=>$pid]);ok((int)$s->fetchColumn()===1,'Un comprobante por pago '.$method);
    }
    file_put_contents($dir.'/payments.json',json_encode($payIds));
    echo count($report).' comprobaciones persistentes completadas SOLO en el clúster de prueba.'.PHP_EOL;
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction()){$pdo->rollBack();}file_put_contents($dir.'/failure.log',(string)$e);fwrite(STDERR,($e instanceof PDOException ? 'Fallo PostgreSQL; diagnóstico privado en var/integration/failure.log.' : $e->getMessage()).PHP_EOL);exit(1);}
