<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__).'/tools/integration-bootstrap.php';
require dirname(__DIR__).'/backend/bootstrap.php';

use Clinica\Repositories\{Connection,UserRepository,PasswordRecoveryRepository,PatientProfileRepository,UserAdminRepository,PacienteRepository,MedicoRepository,HorarioRepository,AttendanceRepository,MedicalHistoryRepository,NotificationRepository,CitaRepository,ReservaRepository};
use Clinica\Services\{AuthService,PatientAccountService,PasswordRecoveryService,LocalPasswordRecoveryTransport,PatientProfileService,UserAdminService,MedicoService,HorarioService,AttendanceService,AttendancePolicy,MedicalHistoryService,NotificationService,LocalMailTransport,CitaService,DisponibilidadService,ReservaService};
use Clinica\Support\{IntegrationGuard,PatientAccountFactory,PasswordRecoveryFactory,PatientProfileFactory,UserAdminFactory,PatientFactory,MedicalFactory,ScheduleFactory,AttendanceFactory,MedicalHistoryFactory,NotificationFactory};

$dir=dirname(__DIR__).'/var/integration';
$report=[];
$pdo=Connection::integration(require dirname(__DIR__).'/backend/config/database.php');
IntegrationGuard::check($pdo);

foreach([
    'PATIENT_ACCOUNT_WRITES_ENABLED','PASSWORD_RECOVERY_WRITES_ENABLED','PATIENT_PROFILE_WRITES_ENABLED',
    'USER_WRITES_ENABLED','PATIENT_WRITES_ENABLED','MEDICAL_WRITES_ENABLED','SCHEDULE_WRITES_ENABLED',
    'ATTENDANCE_WRITES_ENABLED','MEDICAL_RECORD_WRITES_ENABLED','NOTIFICATION_WRITES_ENABLED',
] as $flag){putenv($flag.'=true');}
putenv('PASSWORD_RESET_TRANSPORT=local');
putenv('MAIL_TRANSPORT=local');

$ok=static function(bool $condition,string $label)use(&$report,$dir):void{
    if(!$condition){throw new RuntimeException('FALLO: '.$label);}
    $report[]=$label;
    file_put_contents($dir.'/new-modules-results.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo 'OK: '.$label.PHP_EOL;
};
$actor=static function(PDO $db,string $login):array{
    $row=(new UserRepository($db))->findByLogin($login);
    if(!$row){throw new RuntimeException('Actor de prueba no disponible.');}
    return ['id'=>(int)$row['id_usuario'],'role'=>$row['rol'],'permissions'=>(new UserRepository($db))->permissions((int)$row['id_rol'])];
};
$admin=$actor($pdo,'usuario1');
$reception=$actor($pdo,'usuario2');
$doctor=$actor($pdo,'profesional01');

try {
    IntegrationGuard::check($pdo);

    $suffix='TEST-'.strtoupper(bin2hex(random_bytes(5)));
    $patientInput=[
        'nombre_usuario'=>'paciente_'.strtolower($suffix),
        'correo_acceso'=>strtolower($suffix).'@example.invalid',
        'codigo_paciente'=>$suffix,
        'documento_identidad'=>'DOC-'.$suffix,
        'nombres'=>'Paciente',
        'apellidos'=>'Integracion',
        'contrasena'=>'Test-Paciente-2026!',
        'contrasena_confirmacion'=>'Test-Paciente-2026!',
    ];
    $accountService=PatientAccountFactory::service(true);
    $before=(int)$pdo->query('SELECT count(*) FROM public.usuarios')->fetchColumn();
    $patientUser=$accountService->register($patientInput);
    $row=$pdo->prepare('SELECT u.id_usuario,u.id_rol,r.nombre AS rol,p.id_paciente,p.codigo_paciente FROM public.usuarios u JOIN public.roles r ON r.id_rol=u.id_rol JOIN public.pacientes p ON p.id_usuario=u.id_usuario WHERE u.id_usuario=:id');
    $row->execute(['id'=>$patientUser]);$patient=$row->fetch();
    $ok((int)$patientUser>0 && $patient && $patient['rol']==='Paciente' && $patient['codigo_paciente']===$suffix,'CU15 cuenta y paciente TEST persistidos con rol Paciente');
    $ok((int)$pdo->query('SELECT count(*) FROM public.usuarios')->fetchColumn()===$before+1,'CU15 SELECT posterior confirma una cuenta nueva');
    try{$accountService->register($patientInput);throw new RuntimeException('Duplicado aceptado.');}catch(Clinica\Services\PatientValidation $e){$ok(true,'CU15 duplicados rechazados por validación');}

    $transport=new LocalPasswordRecoveryTransport($dir.'/private-mail');
    $recovery=new PasswordRecoveryService(new PasswordRecoveryRepository($pdo),$transport);
    $recovery->request($patientInput['correo_acceso']);
    $mailFiles=glob($dir.'/private-mail/message-*.txt') ?: [];
    $message='';
    foreach($mailFiles as $mailFile){$candidate=file_get_contents($mailFile);if(str_contains($candidate,$patientInput['correo_acceso'])){$message=$candidate;break;}}
    preg_match('/token=([a-f0-9]{64})/',$message,$match);
    $token=$match[1] ?? '';
    $beforeHash=(string)$pdo->query('SELECT contrasena_hash FROM public.usuarios WHERE id_usuario='.(int)$patientUser)->fetchColumn();
    $ok(strlen($token)===64,'CU16 transporte local generó token temporal sin correo externo');
    $ok($recovery->reset($token,'Test-Nueva-2026!','Test-Nueva-2026!'),'CU16 cambio de contraseña persistente confirmado');
    $afterHash=(string)$pdo->query('SELECT contrasena_hash FROM public.usuarios WHERE id_usuario='.(int)$patientUser)->fetchColumn();
    $ok($beforeHash!==$afterHash && password_verify('Test-Nueva-2026!',$afterHash),'CU16 SELECT confirma hash nuevo');
    $ok(!$recovery->reset($token,'Test-Nueva-2026!','Test-Nueva-2026!'),'CU16 token reutilizado rechazado');

    $profile=PatientProfileFactory::service(true);
    $profile->update((int)$patientUser,['correo_acceso'=>$patientInput['correo_acceso'],'telefono'=>'70000001','correo_contacto'=>'contacto_'.$suffix.'@example.invalid']);
    $profileRow=(new PatientProfileRepository($pdo))->findByUser((int)$patientUser);
    $ok($profileRow && $profileRow['telefono']==='70000001','CU20 perfil TEST persistido y consultable');
    $foreign=(int)$pdo->query('SELECT id_usuario FROM public.pacientes WHERE id_usuario IS NOT NULL AND id_usuario<>'.(int)$patientUser.' ORDER BY id_paciente LIMIT 1')->fetchColumn();
    $foreignBefore=(new PatientProfileRepository($pdo))->findByUser($foreign);
    $ok($foreignBefore!==null && (new PatientProfileRepository($pdo))->findByUser((int)$patientUser)['id_paciente']!==(string)$foreign,'CU20 pertenencia no confunde otro paciente');

    $userService=UserAdminFactory::service(true);
    $userInput=['nombre_usuario'=>'admin_'.strtolower($suffix),'correo_acceso'=>'admin_'.$suffix.'@example.invalid','id_rol'=>'2','estado'=>'inactivo','contrasena'=>'','contrasena_confirmacion'=>''];
    $testUser=$userService->save($userInput,null,$admin);
    $testUserRow=(new UserAdminRepository($pdo))->find($testUser);
    $ok($testUserRow && $testUserRow['nombre_usuario']===$userInput['nombre_usuario'] && $testUserRow['estado']==='inactivo','P7 usuario TEST no privilegiado persistido');
    $edited=array_replace($userInput,['correo_acceso'=>'edited_'.$suffix.'@example.invalid','estado'=>'activo','contrasena'=>'Test-Admin-2026!','contrasena_confirmacion'=>'Test-Admin-2026!']);
    $userService->save($edited,$testUser,$admin);
    $testUserRow=(new UserAdminRepository($pdo))->find($testUser);
    $ok($testUserRow && $testUserRow['estado']==='activo' && $testUserRow['correo_acceso']===$edited['correo_acceso'],'P7 edición de usuario TEST persistida');

    $patientService=PatientFactory::service(true);
    $patientData=['codigo_paciente'=>$suffix.'-P2','documento_identidad'=>'DOC-'.$suffix.'-P2','nombres'=>'Paciente','apellidos'=>'Administrado','fecha_nacimiento'=>'1990-01-01','sexo'=>'F','telefono'=>'70000002','correo_contacto'=>'p2_'.$suffix.'@example.invalid','direccion'=>'Prueba','contacto_emergencia_nombre'=>'','contacto_emergencia_parentesco'=>'','contacto_emergencia_telefono'=>'','estado'=>'activo'];
    $patientId=$patientService->save($patientData,null,$admin);
    $patientRow=(new PacienteRepository($pdo))->find($patientId);
    $ok($patientRow && $patientRow['codigo_paciente']===$patientData['codigo_paciente'],'P2 paciente TEST persistido');
    $patientVersion=$patientRow['updated_at'];
    $patientData['telefono']='70000003';
    $patientData['version']=$patientVersion;
    $patientService->save($patientData,$patientId,$admin);
    $ok((new PacienteRepository($pdo))->find($patientId)['telefono']==='70000003','P2 edición TEST persistida');

    $medical=MedicalFactory::service(true);
    $specialtyInput=['nombre'=>'Especialidad '.$suffix,'descripcion'=>'Especialidad de prueba','precio_consulta'=>'125.00','estado'=>'activo'];
    $specialtyId=$medical->save('especialidad',$specialtyInput,null,$admin);
    $specialtyRow=(new MedicoRepository($pdo))->find('especialidad',$specialtyId);
    $ok($specialtyRow && $specialtyRow['nombre']===$specialtyInput['nombre'],'P3 especialidad TEST persistida');
    $doctorInput=['codigo_medico'=>'MED-'.$suffix,'registro_profesional'=>'REG-'.$suffix,'nombres'=>'Medico','apellidos'=>'Integracion','telefono'=>'70000004','correo_contacto'=>'med_'.$suffix.'@example.invalid','estado'=>'activo','especialidades'=>[(string)$specialtyId]];
    $testDoctorId=$medical->save('medico',$doctorInput,null,$admin);
    $testDoctor=(new MedicoRepository($pdo))->find('medico',$testDoctorId);
    $ok($testDoctor && count($testDoctor['especialidades'])===1 && (int)$testDoctor['especialidades'][0]['id_especialidad']===$specialtyId,'P3 médico y asociación TEST persistidos');

    $schedule=ScheduleFactory::service(true);
    $scheduleUser=['id'=>$admin['id'],'role'=>'Administrador','permissions'=>$admin['permissions']];
    $scheduleInput=['id_medico'=>(string)$testDoctorId,'dia_semana'=>'5','hora_inicio'=>'19:00','hora_fin'=>'20:00','duracion_cita_minutos'=>'30','estado'=>'activo'];
    $scheduleId=$schedule->save('horario',$scheduleInput,null,$scheduleUser);
    $scheduleRow=(new HorarioRepository($pdo))->find('horario',$scheduleId,[$testDoctorId]);
    $ok($scheduleRow && (int)$scheduleRow['id_medico']===$testDoctorId,'P4 horario TEST persistido');
    try{$schedule->save('horario',array_replace($scheduleInput,['hora_inicio'=>'19:30','hora_fin'=>'20:30']),null,$scheduleUser);throw new RuntimeException('Cruce de horario aceptado.');}catch(Clinica\Services\ScheduleValidation $e){$ok(true,'P4 cruce de horario rechazado');}
    $blockDate=(new DateTimeImmutable('next friday',new DateTimeZone('America/La_Paz')))->format('Y-m-d');
    $blockInput=['id_medico'=>(string)$testDoctorId,'fecha'=>$blockDate,'hora_inicio'=>'19:00','hora_fin'=>'19:30','motivo'=>'Bloqueo TEST'];
    $blockId=$schedule->save('bloqueo',$blockInput,null,$scheduleUser);
    $blockRow=(new HorarioRepository($pdo))->find('bloqueo',$blockId,[$testDoctorId]);
    $ok($blockRow && $blockRow['motivo']==='Bloqueo TEST','P4 bloqueo TEST persistido');

    $patientActor=(new AuthService(new UserRepository($pdo)))->authenticate($patientInput['nombre_usuario'],'Test-Paciente-2026!');
    if(!$patientActor){$patientActor=(new AuthService(new UserRepository($pdo)))->authenticate($patientInput['nombre_usuario'],'Test-Nueva-2026!');}
    if(!$patientActor){throw new RuntimeException('No se pudo autenticar paciente TEST.');}
    $patientActor['permissions']=(new UserRepository($pdo))->permissions((int)$patient['id_rol']);
    $citaService=new CitaService(new CitaRepository($pdo));
    $doctorRow=$pdo->prepare('SELECT m.id_medico,me.id_especialidad FROM public.medicos m JOIN public.medico_especialidad me ON me.id_medico=m.id_medico WHERE m.id_usuario=:u ORDER BY m.id_medico,me.id_especialidad LIMIT 1');
    $doctorRow->execute(['u'=>$doctor['id']]);$assigned=$doctorRow->fetch();
    if(!$assigned){throw new RuntimeException('Médico demo sin asociación.');}
    $slot=null;$now=new DateTimeImmutable('now',new DateTimeZone('America/La_Paz'));
    foreach(DisponibilidadService::window($now) as $day){$available=$citaService->availability($patientActor,(int)$assigned['id_medico'],(int)$assigned['id_especialidad'],$day);foreach($available['slots'] as $candidate){if($candidate['available']){$slot=['medico'=>(string)$assigned['id_medico'],'especialidad'=>(string)$assigned['id_especialidad'],'fecha'=>$day,'hora'=>$candidate['hora_inicio']];break 2;}}}
    if(!$slot){throw new RuntimeException('No existe una franja TEST válida para CU11.');}
    putenv('APPOINTMENT_WRITES_ENABLED=true');
    $appointment=(new ReservaService(new ReservaRepository($pdo),true))->reserve($patientActor,$slot);
    $appointmentId=(int)$appointment['id'];
    $attendance=AttendanceFactory::writer();
    $attendanceRow=(new AttendanceRepository($pdo))->find($patientActor,$appointmentId);
    $attendance->change($patientActor,'confirmar',['id'=>$appointmentId,'version'=>AttendancePolicy::version($attendanceRow)]);
    $doctorAttendanceRow=(new AttendanceRepository($pdo))->find($doctor,$appointmentId);
    $attendance->change($doctor,'atender',['id'=>$appointmentId,'version'=>AttendancePolicy::version($doctorAttendanceRow)]);
    $stored=(new AttendanceRepository($pdo))->find($doctor,$appointmentId);
    $ok($stored && $stored['estado']==='atendida','CU11 transición confirmada y atendida persistida');
    $historyRows=(new AttendanceRepository($pdo))->history($doctor,$appointmentId);
    $ok(count($historyRows)>=1,'CU11 historial de cambios consultable');

    $history=MedicalHistoryFactory::service(true);
    $noteId=$history->addNote($doctor,$appointmentId,'Observacion clinica TEST');
    $treatmentId=$history->addTreatment($doctor,$appointmentId,'Tratamiento TEST','Indicaciones TEST');
    $clinical=$history->history($doctor,(int)$patient['id_paciente']);
    $ok($noteId>0 && $treatmentId>0 && count($clinical['notes'])>=1 && count($clinical['treatments'])>=1,'Historial clínico nota y tratamiento persistidos');
    $receptionHistory=$history->history($reception,(int)$patient['id_paciente']);
    $ok($receptionHistory!==null && $receptionHistory['clinical']===false && $receptionHistory['notes']===[] && $receptionHistory['treatments']===[],'Historial clínico oculta notas y tratamientos a recepción');

    $notification=NotificationFactory::writer();
    $notificationId=$notification->appointment($appointmentId,'registro_cita');
    $duplicate=$notification->appointment($appointmentId,'registro_cita');
    $notificationRow=(new NotificationRepository($pdo))->listing($patientActor,100,0);
    $ok($notificationId!==null && $duplicate===null,'CU22 notificación TEST persistida y deduplicada');
    $reminders=$notification->reminders(new DateTimeImmutable('now',new DateTimeZone('America/La_Paz')),10080);
    $ok(is_array($reminders),'CU22 recordatorios procesados con transporte local');

    IntegrationGuard::check($pdo);
    file_put_contents($dir.'/new-modules-results.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo count($report)." comprobaciones persistentes nuevas completadas SOLO en la copia aislada.".PHP_EOL;
} catch(Throwable $e) {
    if($pdo->inTransaction()){$pdo->rollBack();}
    file_put_contents($dir.'/new-modules-failure.log',(string)$e);
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(1);
}
