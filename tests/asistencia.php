<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';

use Clinica\Middleware\AttendanceAccess;
use Clinica\Services\{AttendancePolicy,AttendanceService,BookingConflict};
use Clinica\Repositories\AttendanceStore;

$checks=0;
$check=static function(bool $ok)use(&$checks):void{if(!$ok){throw new RuntimeException('Fallo en asistencia.');}$checks++;};
$reject=static function(callable $call,string $type)use($check):void{try{$call();$check(false);}catch(Throwable $e){$check($e instanceof $type);}};
$patient=['id'=>22,'role'=>'Paciente','permissions'=>['confirmar_cita_propia','gestionar_citas_propias']];
$doctor=['id'=>7,'role'=>'Medico','permissions'=>['marcar_cita_atendida','consultar_historial_citas']];
$staff=['id'=>2,'role'=>'Recepcionista','permissions'=>['controlar_asistencia','consultar_historial_citas']];
$foreign=['id'=>7,'role'=>'Medico','permissions'=>['marcar_cita_atendida']];
$appointment=['id_cita'=>1,'id_paciente'=>1,'id_medico'=>7,'id_especialidad'=>1,'fecha'=>'2026-11-06','hora_inicio'=>'10:00:00','hora_fin'=>'10:30:00','estado'=>'programada','cantidad_reprogramaciones'=>0,'updated_at'=>'v1'];
$version=AttendancePolicy::version($appointment);
$check(AttendanceAccess::confirm($patient) && AttendanceAccess::attend($doctor) && AttendanceAccess::control($staff));
$check(AttendancePolicy::input($patient,['id'=>'1','version'=>$version,'csrf'=>'x'],'confirmar')['action']==='confirmar');
$check(AttendancePolicy::input($staff,['id'=>'1','version'=>$version,'csrf'=>'x','accion'=>'inasistencia'],'asistencia')['action']==='inasistencia');
$reject(fn()=>AttendancePolicy::input($patient,['id'=>'1','version'=>$version,'csrf'=>'x','accion'=>'atender'],'confirmar'),InvalidArgumentException::class);
$reject(fn()=>AttendancePolicy::authorize($patient,'atender'),DomainException::class);
foreach(['cancelada','atendida','inasistencia'] as $state){$copy=$appointment;$copy['estado']=$state;foreach(['confirmar','atender','inasistencia'] as $action){$reject(fn()=>AttendancePolicy::check($copy,$action,new DateTimeImmutable('2026-11-09 12:00',new DateTimeZone('America/La_Paz'))),DomainException::class);}}
$now=new DateTimeImmutable('2026-11-06 10:05',new DateTimeZone('America/La_Paz'));
$reject(fn()=>AttendancePolicy::check($appointment,'inasistencia',$now),DomainException::class);
AttendancePolicy::check($appointment,'inasistencia',$now->modify('+5 minutes'));$check(true);
$copy=$appointment;$copy['estado']='confirmada';AttendancePolicy::check($copy,'atender',$now);$check(true);
$store=new class implements AttendanceStore{public int $calls=0;public function find(array $actor,int $id,bool $lock=false):?array{return null;}public function history(array $actor,int $id):array{return [];}public function change(array $actor,array $appointment,string $state):void{$this->calls++;}};
$reject(fn()=>(new AttendanceService($store))->change($doctor,'atender',['id'=>1,'version'=>$version]),RuntimeException::class);
$check($store->calls===0);
$routes=require dirname(__DIR__).'/backend/routes/web.php';
$check(isset($routes['/citas/asistencia']['POST'],$routes['/citas/atender']['POST'],$routes['/mis-citas/confirmar']['POST']));
echo "$checks comprobaciones de asistencia pasaron en memoria, sin PostgreSQL.\n";
