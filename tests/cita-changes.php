<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Services\{CitaChangePolicy as P,CitaChangeService,BookingConflict};
use Clinica\Repositories\CitaChangeStore;
$n=0;$check=static function(bool $ok)use(&$n):void{if(!$ok){throw new RuntimeException('Fallo P5-C.');}++$n;};
$reject=static function(callable $f,string $type)use($check):void{try{$f();$check(false);}catch(Throwable $e){$check($e instanceof $type);}};
$patient=['id'=>22,'role'=>'Paciente','permissions'=>['gestionar_citas_propias']];$staff=['id'=>2,'role'=>'Recepcionista','permissions'=>['consultar_agenda','gestionar_citas']];
$c=['id_cita'=>1,'id_paciente'=>1,'id_medico'=>1,'id_especialidad'=>1,'fecha'=>'2026-11-09','hora_inicio'=>'10:00:00','hora_fin'=>'10:30:00','estado'=>'programada','cantidad_reprogramaciones'=>0,'updated_at'=>'v1'];
$now=new DateTimeImmutable('2026-11-09 08:00',new DateTimeZone('America/La_Paz'));$version=P::version($c);
$input=['id'=>'1','version'=>$version,'fecha'=>'2026-11-10','hora'=>'10:00'];
$check(P::input($patient,$input,'reprogramar')['hora']==='10:00:00');$check(P::input($staff,['id'=>'1','version'=>$version,'motivo'=>'  '],'cancelar')['motivo']===null);
foreach(['atendida','cancelada','inasistencia'] as $state){$bad=$c;$bad['estado']=$state;foreach(['cancelar','reprogramar'] as $action){$reject(fn()=>P::check($bad,$action,$now),BookingConflict::class);}}
foreach(['programada','confirmada'] as $state){$copy=$c;$copy['estado']=$state;P::check($copy,'reprogramar',$now);P::check($copy,'cancelar',$now);$check(true);}
$copy=$c;$copy['cantidad_reprogramaciones']=1;$reject(fn()=>P::check($copy,'reprogramar',$now),BookingConflict::class);P::check($copy,'cancelar',$now);$check(true);
$reject(fn()=>P::check($c,'cancelar',$now->modify('+2 hours')),BookingConflict::class);
foreach(['paciente'=>'2','medico'=>'2','estado'=>'cancelada','cantidad_reprogramaciones'=>'0','fecha'=>'2026-02-30','hora'=>['10:00'],'id'=>'-1','version'=>'x'] as $key=>$v){$bad=$input;$bad[$key]=$v;$reject(fn()=>P::input($patient,$bad,'reprogramar'),InvalidArgumentException::class);}
$reject(fn()=>P::input(['id'=>3,'role'=>'Medico','permissions'=>['consultar_agenda']],$input,'reprogramar'),DomainException::class);
$reject(fn()=>P::input($patient,['id'=>'1','version'=>$version,'motivo'=>str_repeat('a',2001)],'cancelar'),InvalidArgumentException::class);
$copy=$c;$copy['updated_at']='v2';$check(P::version($copy)!==$version);
$store=new class implements CitaChangeStore{public int $calls=0;public function change(array $actor,string $action,array $input):int{++$this->calls;return $input['id'];}};
$reject(fn()=>(new CitaChangeService($store))->change($patient,'reprogramar',$input),RuntimeException::class);$check($store->calls===0);
$check((new CitaChangeService($store,true))->change($patient,'reprogramar',$input)===1 && $store->calls===1);
$routes=require dirname(__DIR__).'/backend/routes/web.php';foreach(['/citas/reprogramar','/citas/cancelar','/mis-citas/reprogramar','/mis-citas/cancelar'] as $path){$check(isset($routes[$path]['GET'],$routes[$path]['POST']));}
echo "$n comprobaciones P5-C pasaron en memoria, sin PostgreSQL.\n";
