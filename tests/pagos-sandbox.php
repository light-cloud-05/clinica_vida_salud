<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Services\{PayPalSandboxService,PaymentDemoService,PaymentPolicy,BookingConflict};
use Clinica\Repositories\PaymentDemoStore;
$n=0;$check=static function(bool $ok,string $message)use(&$n){if(!$ok){throw new RuntimeException($message);}++$n;};
$reject=static function(callable $call)use($check){try{$call();$check(false,'Debe rechazar');}catch(BookingConflict $e){$check(true,'rechazado');}};
$requests=[];$completed=false;$wrong=false;
$transport=static function($method,$path,$body,$requestId,$oauth)use(&$requests,&$completed,&$wrong){
    $requests[]=[$method,$path,$body,$requestId];
    if($path==='/v2/checkout/orders') {return ['id'=>'ORDER12345','links'=>[['rel'=>'approve','href'=>'https://www.sandbox.paypal.com/checkoutnow?token=ORDER12345']]];}
    if($method==='POST'){$completed=true;return ['status'=>'COMPLETED'];}
    $ref=$wrong ? 'AJENA' : $requests[0][2]['purchase_units'][0]['custom_id'];
    return ['id'=>'ORDER12345','status'=>$completed ? 'COMPLETED' : 'APPROVED','purchase_units'=>[['custom_id'=>$ref,'amount'=>['currency_code'=>'USD','value'=>'10.00'],'payments'=>['captures'=>[['id'=>'CAPTURE123','status'=>'COMPLETED','amount'=>['currency_code'=>'USD','value'=>'10.00']]]]]]];
};
$paypal=new PayPalSandboxService('TEST-CLIENT','TEST-SECRET','7','http://127.0.0.1:8080',$transport);
$check($paypal->quote('70')==='10.00','Conversión decimal');
$old=getenv('PAYMENT_SANDBOX_ENABLED');putenv('PAYMENT_SANDBOX_ENABLED=true');
$path=tempnam(sys_get_temp_dir(),'p9-sandbox-');unlink($path);
try {
    $service=new PaymentDemoService(new PaymentDemoStore($path));$actor=['id'=>22,'role'=>'Paciente','permissions'=>['consultar_pagos_propios','pagar_cita_propia']];
    $date=PaymentPolicy::clock()->modify('+1 day')->format('Y-m-d');
    $c=['owner'=>22,'paciente'=>1,'medico'=>1,'especialidad'=>1,'fecha'=>$date,'hora'=>'16:00:00','end'=>'16:30:00','reserve_deadline'=>PaymentPolicy::clock()->modify('+2 hours')->format(DATE_ATOM)];
    $id=$service->create($actor,$c,'70','paypal_sandbox',null,str_repeat('3',64));
    $url=$service->sandbox($actor,$id,false,$paypal);
    $check(str_starts_with($url,'https://www.sandbox.paypal.com/'),'Aprobación exclusivamente Sandbox');
    $check($service->find($actor,$id)['fecha_vencimiento']===$c['reserve_deadline'],'Sandbox conserva plazo de reserva, no 30 minutos simulados');
    $reception=['id'=>2,'role'=>'Recepcionista','permissions'=>['consultar_pagos_autorizados','registrar_pago_efectivo']];
    $reject(fn()=>$service->action($reception,$id,'reloj',PaymentPolicy::clock()->modify('+1 minute')));
    $check(str_contains($requests[0][2]['application_context']['return_url'],'/mis-pagos/sandbox/retorno?pago='.$id),'Retorno vinculado al ensayo');
    $service->sandbox($actor,$id,false,$paypal);$check(count($requests)===1,'Crear orden idempotente');
    $reject(fn()=>$service->action($actor,$id,'aprobar'));
    $wrong=true;$reject(fn()=>$service->sandbox($actor,$id,true,$paypal));$check(!$completed,'No captura orden ajena');$wrong=false;
    $service->sandbox($actor,$id,true,$paypal);$r=$service->find($actor,$id);
    $check($r['estado']==='confirmado' && $r['events'][0]['proveedor']==='paypal_sandbox' && $r['events'][0]['referencia_externa']==='CAPTURE123','Solo confirma captura verificada (transporte de prueba)');
    $count=count($requests);$service->sandbox($actor,$id,true,$paypal);$check(count($requests)===$count,'Captura repetida idempotente');
    $data=['id'=>'ORDER12345','status'=>'COMPLETED','purchase_units'=>[['custom_id'=>'R','payments'=>['captures'=>[['id'=>'C','status'=>'COMPLETED','amount'=>['currency_code'=>'EUR','value'=>'10.00']]]]]]];
    $reject(fn()=>PayPalSandboxService::verify($data,'ORDER12345','R','10.00'));
    unset($c['reserve_deadline']);$c['hora']='17:00:00';$c['end']='17:30:00';
    $missing=$service->create($actor,$c,'70','paypal_sandbox',null,str_repeat('4',64));
    $reject(fn()=>$service->sandbox($actor,$missing,false,$paypal));
} finally {putenv($old===false ? 'PAYMENT_SANDBOX_ENABLED' : 'PAYMENT_SANDBOX_ENABLED='.$old);@unlink($path);@unlink($path.'.lock');}
echo "$n comprobaciones Sandbox con transporte ficticio de prueba; ninguna solicitud a PayPal.\n";
