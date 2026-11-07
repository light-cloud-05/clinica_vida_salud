<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Services\ScheduleValidator as V;
use Clinica\Services\DisponibilidadService as A;
use Clinica\Services\ScheduleRules as R;
use Clinica\Services\HorarioService;
use Clinica\Services\ScheduleValidation;
use Clinica\Repositories\ScheduleStore;
use Clinica\Middleware\ScheduleAccess;
$n=0;$check=static function(bool $ok,string $label) use(&$n): void{if(!$ok){throw new RuntimeException('FALLO P4: '.$label);}++$n;};
$input=['id_medico'=>'1','dia_semana'=>'1','hora_inicio'=>'08:00','hora_fin'=>'12:00','duracion_cita_minutos'=>'30','estado'=>'activo'];
$hour=V::validate('horario',$input)['data']+['id_horario'=>1,'updated_at'=>'v1'];
$block=['id_medico'=>1,'fecha'=>'2026-11-09','hora_inicio'=>'09:00:00','hora_fin'=>'09:15:00','motivo'=>null,'id_bloqueo'=>1];
$appointment=['id_medico'=>1,'fecha'=>'2026-11-09','hora_inicio'=>'10:00:00','hora_fin'=>'10:30:00','estado'=>'confirmada'];
$doctor=['id_medico'=>1,'estado'=>'activo'];
$check(!V::validate('horario',$input)['errors'],'Horario válido');
$default=$input;unset($default['duracion_cita_minutos']);$check(V::validate('horario',$default)['data']['duracion_cita_minutos']===30,'Predeterminado real 30');
foreach(['dia_semana'=>['0','6','7',[]],'duracion_cita_minutos'=>['0','-1','2.5','2147483648'],'estado'=>['cancelado',''],'id_medico'=>['0',[],"1 OR 1=1"],'hora_inicio'=>['25:00','10:99','08:00:60','24:00:01']] as $field=>$values){foreach($values as $value){$bad=$input;$bad[$field]=$value;$check(isset(V::validate('horario',$bad)['errors'][$field]),'Valor inválido '.$field);}}
foreach(['07:00','08:00'] as $end){$bad=$input;$bad['hora_fin']=$end;$check(isset(V::validate('horario',$bad)['errors']['hora_fin']),'Fin posterior');}
foreach(['2026-02-30','0000-01-01','hoy',[]] as $date){$check(V::date($date)===null,'Fecha inválida');}
$check(V::time('24:00')===86400000000 && V::time('08:00:00.123456')===28800123456,'Precisión y límite time de PostgreSQL');
$check(V::formatTime(V::time('08:00:00.123456'))==='08:00:00.123456','Formato exacto');
$check(V::displayTime('08:00:00')==='08:00','Presentación breve sin perder precisión');
$check(V::displayTime('08:00:30')==='08:00:30' && V::displayTime('08:00:00.123456')==='08:00:00.123456','No ocultar segundos ni microsegundos');
$versionBlock=V::version('bloqueo',$block);$changedBlock=$block;$changedBlock['motivo']='Cambio simulado';
$check($versionBlock!==V::version('bloqueo',$changedBlock),'Huella detecta edición de bloqueo sin updated_at');
$check(isset(V::validate('bloqueo',['id_medico'=>'1','fecha'=>'2026-11-09','hora_inicio'=>'08:00','hora_fin'=>'09:00','estado'=>'activo'])['errors']['_form']),'No inventar estado en bloqueos');
$check(!R::conflicts('horario',$hour,1,[$hour],[],[$appointment]),'Conservar horario compatible');
$adjacent=$hour;$adjacent['hora_inicio']='12:00:00';$adjacent['hora_fin']='13:00:00';$check(!R::conflicts('horario',$adjacent,null,[$hour],[],[]),'Adyacencia permitida');
$overlap=$hour;$overlap['hora_inicio']='11:59:59';$overlap['hora_fin']='13:00:00';$check(isset(R::conflicts('horario',$overlap,null,[$hour],[],[])['hora_inicio']),'Solapamiento detectado');
foreach(['estado'=>'inactivo','duracion_cita_minutos'=>15,'hora_inicio'=>'08:15:00','hora_fin'=>'10:00:00','id_medico'=>2] as $field=>$value){$change=$hour;$change[$field]=$value;$check(isset(R::conflicts('horario',$change,1,[$hour],[],[$appointment])['_form']),'Protección cita '.$field);}
$cancelled=$appointment;$cancelled['estado']='cancelada';$change=$hour;$change['estado']='inactivo';$check(!R::conflicts('horario',$change,1,[$hour],[],[$cancelled]),'Cita cancelada no ocupa');
$overlapBlock=$block;$overlapBlock['hora_fin']='09:30:00';$check(isset(R::conflicts('bloqueo',$overlapBlock,null,[],[$block],[])['hora_inicio']),'Bloqueo superpuesto');
$affected=$block;$affected['hora_inicio']='10:00:00';$affected['hora_fin']='11:00:00';$check(isset(R::conflicts('bloqueo',$affected,null,[],[],[$appointment])['_form']),'Bloqueo no invalida cita');
$check(!R::conflicts('bloqueo',$block,null,[$hour],[],[]),'Bloqueo sobre horario es excepción válida');
$now=new DateTimeImmutable('2026-11-09 08:10:00',new DateTimeZone('America/La_Paz'));$availability=new A();
$day=$availability->day($doctor,'2026-11-09',[$hour],[$block],[$appointment,$cancelled],$now);$states=[];foreach($day['franjas'] as $slot){$states[$slot['hora_inicio']]=$slot['estado'];}
$check(count($states)===8,'Ocho franjas de 30 minutos');$check($states['08:00:00']==='pasada','No ofrecer pasado');$check($states['09:00:00']==='bloqueada','Bloqueo parcial ocupa franja');$check($states['10:00:00']==='ocupada','Cita ocupa');$check($states['09:30:00']==='disponible','Límite de bloqueo no ocupa siguiente');
$check($availability->day($doctor,'2026-11-14',[$hour],[],[],$now)['franjas']===[],'Sin horarios de fin de semana');
$inactive=$doctor;$inactive['estado']='inactivo';$check(!array_filter($availability->day($inactive,'2026-11-09',[$hour],[],[],$now)['franjas'],static fn(array $s): bool=>$s['estado']==='disponible'),'Médico inactivo no disponible');
$fortyFive=$hour;$fortyFive['hora_inicio']='08:15:00';$fortyFive['hora_fin']='10:00:00';$fortyFive['duracion_cita_minutos']=45;$slots=$availability->day($doctor,'2026-11-09',[$fortyFive],[],[],$now)['franjas'];$check(count($slots)===2 && $slots[1]['hora_inicio']==='09:00:00','Duración configurada y ancla; sin sobrante');
$check(!array_filter($availability->day($doctor,'2026-11-09',[$hour,$hour+['otro'=>1]],[],[],$now)['franjas'],static fn(array $s): bool=>$s['estado']==='disponible'),'Datos heredados solapados no disponibles');
$check(A::covers($hour,$appointment),'Cobertura equivalente a trigger');$misaligned=$appointment;$misaligned['hora_inicio']='10:01:00';$misaligned['hora_fin']='10:31:00';$check(!A::covers($hour,$misaligned),'Ancla exacta');
$window=A::window($now);$check(count($window)===10 && $window[0]==='2026-11-09' && $window[9]==='2026-11-20','Ventana incluye hoy hábil');
$weekend=A::window(new DateTimeImmutable('2026-11-14',new DateTimeZone('America/La_Paz')));$check($weekend[0]==='2026-11-16','Ventana omite fin de semana');
$far=$availability->day($doctor,'2026-12-07',[$hour],[],[],$now);$check($far['franjas'][0]['estado']==='disponible' && !$far['franjas'][0]['dentro_ventana'],'No confundir gestión administrativa con reserva');
$store=new class($hour,$appointment) implements ScheduleStore{
    public int $writes=0;public array $hours;public array $citas;public array $blocks=[];
    public function __construct(array $hour,array $appointment){$this->hours=[$hour,$hour+[]];$this->hours[1]['id_horario']=2;$this->hours[1]['id_medico']=2;$this->citas=[$appointment];}
    public function doctors(?int $userId): array{$all=[['id_medico'=>1,'estado'=>'activo','especialidades'=>[1]],['id_medico'=>2,'estado'=>'activo','especialidades'=>[2]]];return $userId===null ? $all : ($userId===7 ? [$all[0]] : []);}
    public function specialties(): array{return [['id_especialidad'=>1,'nombre'=>'Prueba']];}
    public function hours(array $doctors): array{return array_values(array_filter($this->hours,static fn(array $h): bool=>in_array((int)$h['id_medico'],$doctors,true)));}
    public function blocks(array $doctors,?string $from=null,?string $to=null): array{return $this->blocks;}
    public function appointments(array $doctors,?string $from=null,?string $to=null): array{return $this->citas;}
    public function find(string $kind,int $id,array $doctors): ?array{foreach($this->hours($doctors) as $h){if($kind==='horario' && (int)$h['id_horario']===$id){return $h;}}return null;}
    public function save(string $kind,array $data,?int $id,?string $version,array $actor): int{++$this->writes;return $id ?? 99;}
};
$admin=['id'=>1,'role'=>'Administrador','permissions'=>['consultar_agenda','gestionar_horarios','gestionar_bloqueos'],'username'=>'simulado'];$physician=['id'=>7,'role'=>'Medico','permissions'=>['consultar_agenda']];$service=new HorarioService($store,true);
$check(count($service->catalog($admin)['hours'])===2 && count($service->catalog($physician)['hours'])===1,'Ámbito real de lectura');
try{$service->catalog($physician,2);$check(false,'IDOR');}catch(DomainException $e){$check(true,'No consultar otro médico');}
$check($service->find('horario',2,$physician)===null,'Consulta por ID también limitada');
foreach(['Paciente','Recepcionista','Medico'] as $role){$actor=['id'=>7,'role'=>$role,'permissions'=>['consultar_agenda']];try{$service->save('horario',$input,null,$actor);$check(false,'Escritura ajena');}catch(RuntimeException $e){$check($store->writes===0,'No escritura sin permiso');}}
try{(new HorarioService($store))->save('horario',$input,null,$admin);$check(false,'Bloqueo');}catch(RuntimeException $e){$check($store->writes===0,'Indicador desactivado');}
$new=$input;$new['hora_inicio']='13:00';$new['hora_fin']='16:00';$check($service->save('horario',$new,null,$admin)===99,'Alta simulada');
$edit=$input+['version'=>V::version('horario',$hour)];$check($service->save('horario',$edit,1,$admin)===1,'Edición simulada compatible');
$newBlock=['id_medico'=>'1','fecha'=>'2026-11-10','hora_inicio'=>'08:00','hora_fin'=>'09:00'];$check($service->save('bloqueo',$newBlock,null,$admin)===99,'Bloqueo simulado');
$ownManager=$physician;$ownManager['permissions'][]='gestionar_bloqueos';$foreign=$newBlock;$foreign['id_medico']='2';$check(isset($service->validate('bloqueo',$foreign,null,$ownManager)['errors']['id_medico']),'Médico con permiso sigue limitado a sí mismo');
$check(!ScheduleAccess::blocks($physician) && ScheduleAccess::view($physician),'Permisos reales separados');
$routes=require dirname(__DIR__).'/backend/routes/web.php';foreach(['/horarios','/horarios/agenda','/bloqueos'] as $route){$check($routes[$route]['permission']==='consultar_agenda','Rutas de lectura');}$check($routes['/horarios/nuevo']['permission']==='gestionar_horarios' && $routes['/bloqueos/nuevo']['permission']==='gestionar_bloqueos','Rutas de escritura');
$_SESSION=['csrf'=>bin2hex(random_bytes(32))];$render=new ReflectionMethod(Clinica\Controllers\HorarioController::class,'render');$render->setAccessible(true);$controller=new Clinica\Controllers\HorarioController();$values=$input;$values['hora_inicio']='<script>alert(1)</script>';
ob_start();$render->invoke($controller,'form',['user'=>$admin,'title'=>'Prueba','doctors'=>[['id_medico'=>1,'nombres'=>'<script>','apellidos'=>'Prueba']],'kind'=>'horario','editing'=>false,'id'=>null,'values'=>$values,'version'=>'','errors'=>[],'writesEnabled'=>false]);$html=ob_get_clean();$check(str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>alert(1)'),'Escape en formulario');$check(str_contains($html,'name="csrf"') && str_contains($html,'disabled'),'CSRF y bloqueo visibles');
echo "$n comprobaciones P4 pasaron: disponibilidad, protección y escrituras solo en memoria.\n";
