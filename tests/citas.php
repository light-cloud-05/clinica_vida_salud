<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Services\CitaFilters as F;
use Clinica\Services\CitaAvailability;
use Clinica\Services\CitaService;
use Clinica\Repositories\CitaStore;
use Clinica\Middleware\CitaAccess;
$checks=0;$check=static function(bool $ok,string $label)use(&$checks): void{if(!$ok){throw new RuntimeException('FALLO P5-A: '.$label);}++$checks;};
$check(F::parse([])['pagina']===1,'Paginación inicial');
foreach(['medico','paciente','especialidad','pagina'] as $field){foreach(['0',[],"1 OR 1=1",'-1','9999999999999999999999999'] as $bad){try{F::parse([$field=>$bad]);$check(false,'Filtro');}catch(InvalidArgumentException $e){$check(true,'ID/página inválidos');}}}
foreach(['fecha'=>'2026-02-30','estado'=>'pendiente','q'=>['x']] as $field=>$bad){try{F::parse([$field=>$bad]);$check(false,'Filtro inválido');}catch(InvalidArgumentException $e){$check(true,'Dato inválido');}}
$check(count(F::STATES)===5 && in_array('inasistencia',F::STATES,true),'Estados reales');
foreach(['Administrador','Recepcionista','Medico'] as $role){$user=['role'=>$role,'permissions'=>['consultar_agenda','consultar_historial_citas']];$check(CitaAccess::listing($user) && CitaAccess::agenda($user) && CitaAccess::availability($user),'Lectura del personal');$check(!CitaAccess::listing(['role'=>$role,'permissions'=>[]]),'Permiso obligatorio');}
$patient=['id'=>30,'role'=>'Paciente','permissions'=>['gestionar_citas_propias']];$check(CitaAccess::listing($patient) && !CitaAccess::agenda($patient) && CitaAccess::availability($patient),'Permisos del titular');
$doctor=['id_medico'=>1,'estado'=>'activo'];$date='2026-11-09';$now=new DateTimeImmutable('2026-11-09 08:10:00',new DateTimeZone('America/La_Paz'));
$hour=['id_medico'=>1,'dia_semana'=>1,'hora_inicio'=>'08:00:00','hora_fin'=>'12:00:00','duracion_cita_minutos'=>30,'estado'=>'activo'];
$appointment=['id_medico'=>1,'fecha'=>$date,'hora_inicio'=>'10:00:00','hora_fin'=>'10:30:00','estado'=>'programada'];$block=['id_medico'=>1,'fecha'=>$date,'hora_inicio'=>'09:00:00','hora_fin'=>'09:15:00','motivo'=>'No exponer motivo'];$engine=new CitaAvailability();
foreach(F::STATES as $state){$c=$appointment;$c['estado']=$state;$result=$engine->evaluate($doctor,$date,[$hour],[],[$c],null,$now);$slot=array_values(array_filter($result['slots'],static fn(array $s): bool=>$s['hora_inicio']==='10:00:00'))[0];$check($slot['available']===($state==='cancelada'),'Ocupación por estado '.$state);}
$result=$engine->evaluate($doctor,$date,[$hour],[$block],[$appointment],'09:00',$now);$check($result['selected']===null && str_contains($result['message'],'no está disponible'),'Rechazar hora bloqueada');
$check(!str_contains(json_encode($result),'No exponer motivo') && !isset($result['appointments']),'Sin motivos ni citas ajenas');
$result=$engine->evaluate($doctor,$date,[$hour],[$block],[$appointment],'09:30',$now);$check($result['selected']['hora_inicio']==='09:30:00','Seleccionar solo hora libre');
$check(!$result['slots'][0]['available'],'No ofrecer pasado');
foreach(['2026-11-08','2026-11-14','2026-11-23'] as $outside){$result=$engine->evaluate($doctor,$outside,[$hour],[],[],null,$now);$check($result['slots']===[] && $result['selected']===null,'No ofrecer pasado/fin de semana/fuera de ventana');}
$check(str_contains($engine->evaluate($doctor,'2026-11-10',[$hour],[],[],null,$now)['message'],'No hay atención'),'Día sin jornada');
$configured=$hour;$configured['duracion_cita_minutos']=45;$configured['hora_inicio']='08:15:00';$configured['hora_fin']='10:00:00';$check(count($engine->evaluate($doctor,$date,[$configured],[],[],null,$now)['slots'])===2,'Duración configurada de P4');
// Dos lectores pueden ver el mismo hueco; tras una primera ocupación simulada, el segundo ya no lo selecciona.
$first=$engine->evaluate($doctor,$date,[$hour],[],[],'10:00',$now);$second=$engine->evaluate($doctor,$date,[$hour],[],[],'10:00',$now);$check($first['selected']!==null && $second['selected']!==null,'Consultar no retiene un turno');
$occupied=$engine->evaluate($doctor,$date,[$hour],[],[$appointment],'10:00',$now);$check($occupied['selected']===null,'Revalidar tras ocupación simulada');
$store=new class implements CitaStore{
    public int $reads=0;
    public function count(array $actor,array $filters): int{return 60;}
    public function listing(array $actor,array $filters,int $limit,int $offset): array{++$this->reads;return array_slice(range(1,60),$offset,$limit);}
    public function find(array $actor,int $id): ?array{++$this->reads;return null;}
    public function choices(array $actor): array{return ['doctors'=>[['id_medico'=>1]],'patients'=>[['id_paciente'=>1]],'specialties'=>[['id_especialidad'=>1]]];}
    public function availabilityChoices(array $actor): array{return ['doctors'=>[['id_medico'=>1,'estado'=>'activo','especialidades'=>[1]]],'specialties'=>[['id_especialidad'=>1]]];}
    public function availabilityData(array $actor,int $doctor,string $date): array{++$this->reads;return ['hours'=>[],'blocks'=>[],'appointments'=>[]];}
};
$admin=['id'=>1,'role'=>'Administrador','permissions'=>['consultar_historial_citas','consultar_agenda'],'username'=>'memoria'];$service=new CitaService($store);$page=$service->page($admin,['pagina'=>'999']);$check($page['page']===6 && $page['total']===60 && count($page['items'])===10,'Paginación acotada');
$before=$store->reads;try{$service->page($patient,['paciente'=>'2']);$check(false,'Filtro ajeno');}catch(DomainException $e){$check($store->reads===$before,'Filtro fuera de ámbito rechazado');}
try{$service->find($patient,0);$check(false,'ID inválido');}catch(InvalidArgumentException $e){$check($store->reads===$before,'No consultar ID inválido');}
try{$service->page(['id'=>1,'role'=>'Paciente','permissions'=>[]],[]);$check(false,'Sin permiso');}catch(DomainException $e){$check($store->reads===$before,'Sin permiso no consultar');}
try{$service->availability($patient,2,1,$date,null,$now);$check(false,'Médico no permitido');}catch(DomainException $e){$check(true,'Ámbito de médico');}
try{$service->availability($patient,1,2,$date,null,$now);$check(false,'Pareja inexistente');}catch(InvalidArgumentException $e){$check(true,'Relación médico-especialidad');}
$service->availability($patient,1,1,'2026-12-07',null,$now);$check($store->reads===$before,'No consultar ocupación fuera de ventana');
$routes=require dirname(__DIR__).'/backend/routes/web.php';foreach(['/citas','/citas/detalle','/citas/agenda','/citas/disponibilidad','/mis-citas','/mis-citas/detalle','/mis-citas/disponibilidad'] as $path){$check(array_keys(array_intersect_key($routes[$path],array_flip(['GET','POST','PUT','PATCH','DELETE'])))===['GET'],'Solo GET');}
$check(!method_exists(Clinica\Repositories\CitaRepository::class,'save') && !method_exists(CitaService::class,'save'),'Sin API de escritura');
$_SESSION=['csrf'=>bin2hex(random_bytes(32))];$render=new ReflectionMethod(Clinica\Controllers\CitaController::class,'render');$render->setAccessible(true);$appointment=['id_cita'=>1,'codigo_cita'=>'<script>','fecha'=>$date,'hora_inicio'=>'10:00:00','hora_fin'=>'10:30:00','estado'=>'programada','paciente_nombre'=>'Paciente','medico_nombre'=>'Médico','especialidad_nombre'=>'Prueba'];
ob_start();$render->invoke(new Clinica\Controllers\CitaController(),'show',['user'=>$admin,'title'=>'Detalle','appointment'=>$appointment]);$html=ob_get_clean();$check(str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>'),'Salida escapada');$check(!str_contains($html,'motivo_consulta') && !str_contains($html,'documento_identidad'),'Sin campos sensibles innecesarios');
echo "$checks comprobaciones P5-A pasaron: lectura, privacidad y concurrencia solo simulada.\n";
