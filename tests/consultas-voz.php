<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Services\{VoiceIntent,VoiceQueryService};
use Clinica\Repositories\VoiceQueryStore;
use Clinica\Middleware\VoiceAccess;

final class VoiceStoreFake implements VoiceQueryStore
{
    public array $calls=[];
    public array $items=[['codigo_cita'=>'CVS-PRUEBA','fecha'=>'2026-11-09','hora_inicio'=>'10:00:00','hora_fin'=>'10:45:00','estado'=>'programada','medico'=>'Profesional autorizado','especialidad'=>'Pediatría']];
    public array $payments=[];
    public function appointments(array $actor,bool $today,DateTimeImmutable $now):array{$this->calls[]=['appointments',$actor['id'],$today,$now->format('Y-m-d')];return $this->items;}
    public function choices(array $actor):array{$this->calls[]=['choices',$actor['id']];return ['doctors'=>[['id_medico'=>7,'nombre'=>'Profesional autorizado','especialidades'=>[3]]],'specialties'=>[['id_especialidad'=>3,'nombre'=>'Pediatría']]];}
    public function availability(array $actor,?int $specialty,?string $date,DateTimeImmutable $now):array{$this->calls[]=['availability',$actor['id'],$specialty,$date];return [['fecha'=>'2026-11-09','hora_inicio'=>'11:00:00','hora_fin'=>'11:45:00','medico'=>'Profesional autorizado','especialidad'=>'Pediatría']];}
    public function payments(array $actor):array{$this->calls[]=['payments',$actor['id']];return $this->payments;}
}
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok){throw new RuntimeException('FALLO P10: '.$label);}++$n;};
$reject=static function(callable $work,string $type)use($check):void{try{$work();$check(false,'Debía rechazar');}catch(Throwable $e){$check($e instanceof $type,'Error esperado '.$type);}};
$examples=['¿Cuáles son mis próximas citas?'=>'proximas','MIS PRÓXIMAS CITAS.'=>'proximas','¿Qué médicos atienden pediatría?'=>'medicos','¿Qué horarios hay disponibles?'=>'horarios','Muéstrame mi agenda de hoy.'=>'agenda','¿Cuál es el estado de mi pago?'=>'pagos','horarios disponibles de pediatría'=>'horarios'];
foreach($examples as $text=>$intent){$check(VoiceIntent::parse($text)['intent']===$intent,'Interpreta '.$text);}
foreach([null,[],17,'',str_repeat('x',241),"agenda\0hoy",'SELECT * FROM usuarios','Cancela mi cita','Reserva una cita','Paga mi cita','Reprograma mis próximas citas','Muéstrame la agenda del médico 99','Mis próximas citas y cancela la primera','<script>alert(1)</script>',"\xff"] as $bad){$reject(fn()=>VoiceIntent::parse($bad),InvalidArgumentException::class);}
$patient=['id'=>22,'role'=>'Paciente','permissions'=>['realizar_consultas_voz','gestionar_citas_propias','consultar_pagos_propios']];
$doctor=['id'=>7,'role'=>'Medico','permissions'=>['realizar_consultas_voz','consultar_agenda']];
$staff=['id'=>2,'role'=>'Recepcionista','permissions'=>['realizar_consultas_voz','consultar_agenda','consultar_pagos_autorizados']];
$admin=$staff;$admin['role']='Administrador';
foreach([$patient,$doctor,$staff,$admin] as $actor){foreach(['proximas','agenda','medicos','horarios'] as $intent){$check(VoiceAccess::query($actor,$intent),'Ámbito consulta '.$actor['role']);}}
$check(!VoiceAccess::query($doctor,'pagos'),'Médico sin pagos');$check(VoiceAccess::query($admin,'pagos'),'Administrador consulta pagos');
$store=new VoiceStoreFake();$service=new VoiceQueryService($store);$now=new DateTimeImmutable('2026-11-09T00:30:00Z');
$answer=$service->answer($patient,'Muéstrame mi agenda de hoy','',$now);
$check($store->calls[0]===['appointments',22,true,'2026-11-08'],'Hoy en La Paz, no UTC ni navegador');
$check($answer['rows'][0][3]==='10:45:00','Duración de registro existente');
$check(!str_contains($answer['spoken'],'CVS-PRUEBA') && !str_contains($answer['spoken'],'Profesional'),'Lectura solo resumen');
$calls=count($store->calls);$reject(fn()=>$service->answer($doctor,'¿Cuál es el estado de mi pago?'),DomainException::class);$check(count($store->calls)===$calls,'Deniega antes de leer repositorio');
$limited=$patient;$limited['permissions']=['realizar_consultas_voz'];$reject(fn()=>$service->answer($limited,'Mis próximas citas'),DomainException::class);
$noVoice=$patient;$noVoice['permissions']=['gestionar_citas_propias'];$reject(fn()=>$service->answer($noVoice,'Mis próximas citas'),DomainException::class);
$invalid=$patient;$invalid['id']=0;$check(!VoiceAccess::allowed($invalid),'Sin usuario autenticado');
$now=new DateTimeImmutable('2026-11-09T08:00:00-04:00');
$answer=$service->answer($patient,'Que medicos atienden pediatria',null,$now);$check(count($answer['rows'])===1,'Especialidad real del catálogo simulado');
$answer=$service->answer($patient,'Que medicos atienden especialidad inexistente',null,$now);$check(!$answer['rows'] && str_contains($answer['message'],'No se encontró'),'No inventa especialidades');
$answer=$service->answer($doctor,'Que horarios hay disponibles de pediatria','2026-11-10',$now);$check(end($store->calls)===['availability',7,3,'2026-11-10'],'Filtros de especialidad y fecha validados');
foreach(['2026-02-30','2026-11-08','2027-01-01',[]] as $date){$reject(fn()=>$service->answer($patient,'Que horarios hay disponibles',$date,$now),InvalidArgumentException::class);}
$answer=$service->answer($patient,'Cuál es el estado de mi pago',null,$now);$check(!$answer['rows'] && str_contains($answer['message'],'ensayos locales de P9'),'Pagos PostgreSQL vacíos no inventan pago demo');
$store->payments=[['codigo_cita'=>'PAGO-PROPIO','fecha'=>'2026-11-09','metodo'=>'efectivo','estado'=>'pendiente','reserva_estado'=>'pendiente','importe'=>'50.00','moneda'=>'BOB','referencia'=>'NO-PRONUNCIAR']];
$answer=$service->answer($patient,'mis pagos',null,$now);$check($answer['rows'][0][3]==='pendiente' && !str_contains($answer['spoken'],'50.00') && !str_contains(json_encode($answer),'NO-PRONUNCIAR'),'Proyección mínima pagos');
$store->items=[];$answer=$service->answer($patient,'mis proximas citas',null,$now);$check(!$answer['rows'] && str_contains($answer['message'],'No hay'),'Resultado vacío explícito');
$routes=require dirname(__DIR__).'/backend/routes/web.php';$check(isset($routes['/consultas-voz']['GET'],$routes['/consultas-voz/consultar']['POST']) && $routes['/consultas-voz']['permission']==='realizar_consultas_voz','Rutas protegidas con permiso real');
echo "$n comprobaciones P10 de interpretación, servicios y permisos; sin conexión a PostgreSQL.\n";
