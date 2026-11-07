<?php
declare(strict_types=1);
require dirname(__DIR__).'/tools/integration-bootstrap.php';require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\{Connection,CitaRepository,CitaChangeRepository,ReservaRepository,PaymentRepository,PaymentWriteRepository};
use Clinica\Services\{CitaService,CitaChangeService,CitaChangePolicy,ReservaService,DisponibilidadService,PayPalSandboxService,PaymentPolicy,PaymentReceipt};
use Clinica\Support\IntegrationGuard;
$dir=dirname(__DIR__).'/var/integration';$checks=[];
function assertIntegration(bool $ok,string $label):void{global $checks,$dir;if(!$ok){throw new RuntimeException('FALLO: '.$label);}$checks[]=$label;file_put_contents($dir.'/extended-results.json',json_encode($checks,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo 'OK: '.$label.PHP_EOL;}
function isolatedWrite(PDO $pdo,string $sql,array $params,?int $id=null):PDOStatement{IntegrationGuard::check($pdo,$id);$s=$pdo->prepare($sql);$s->execute($params);return $s;}
try{
    $pdo=Connection::integration(require dirname(__DIR__).'/backend/config/database.php');$actors=json_decode(file_get_contents($dir.'/actors.json'),true);$patient=$actors['Paciente'];$fixture=json_decode(file_get_contents($dir.'/patient.json'),true);
    $read=new CitaService(new CitaRepository($pdo));$booking=new ReservaService(new ReservaRepository($pdo),true);
    $slot=function()use($read,$patient):array{foreach(DisponibilidadService::window(new DateTimeImmutable('now',new DateTimeZone('America/La_Paz'))) as $day){foreach($read->availabilityChoices($patient)['doctors'] as $d){if($d['estado']!=='activo' || !$d['especialidades']){continue;}$s=$d['especialidades'][0];foreach($read->availability($patient,$d['id_medico'],$s,$day)['slots'] as $r){if($r['available']){return ['medico'=>(string)$d['id_medico'],'especialidad'=>(string)$s,'fecha'=>$day,'hora'=>$r['hora_inicio']];}}}}throw new RuntimeException('Sin franjas.');};
    $input=$slot();file_put_contents($dir.'/race.json',json_encode($input));if(is_file($dir.'/race.go')){unlink($dir.'/race.go');}
    $workers=[];for($i=0;$i<2;$i++){$workers[]=proc_open([PHP_BINARY,__DIR__.'/integration-worker.php'],[0=>['pipe','r'],1=>['file',$dir.'/race-'.$i.'.json','w'],2=>['file',$dir.'/race-error.log','a']],$pipes,null,null,['bypass_shell'=>true]);fclose($pipes[0]);}
    file_put_contents($dir.'/race.go','start');foreach($workers as $w){proc_close($w);}
    $a=json_decode(file_get_contents($dir.'/race-0.json'),true);$b=json_decode(file_get_contents($dir.'/race-1.json'),true);
    assertIntegration(($a['ok'] ?? false)!==($b['ok'] ?? false) && (($a['conflict'] ?? false) || ($b['conflict'] ?? false)),'Dos procesos concurrentes: un COMMIT y un conflicto');
    $id=(int)(($a['ok'] ?? false) ? $a['id'] : $b['id']);
    $s=$pdo->prepare("SELECT count(*) FROM public.citas WHERE id_medico=:doctor AND fecha=:day AND hora_inicio=:time AND estado<>'cancelada'");$s->execute(['doctor'=>$input['medico'],'day'=>$input['fecha'],'time'=>$input['hora']]);assertIntegration((int)$s->fetchColumn()===1,'Concurrencia deja exactamente una cita ocupando la franja');
    $c=(new CitaChangeRepository($pdo))->find($patient,$id);
    try{isolatedWrite($pdo,"INSERT INTO public.citas(id_paciente,id_medico,id_especialidad,codigo_cita,fecha,hora_inicio,hora_fin,estado) VALUES(:p,:m,:e,:code,:d,:a,:b,'programada')",['p'=>$fixture['patient'],'m'=>$c['id_medico'],'e'=>$c['id_especialidad'],'code'=>'TEST-'.bin2hex(random_bytes(6)),'d'=>$c['fecha'],'a'=>$c['hora_inicio'],'b'=>$c['hora_fin']]);throw new RuntimeException('La exclusión no actuó.');}catch(PDOException $e){assertIntegration($e->getCode()==='23P01','Exclusión PostgreSQL rechaza solapamiento incluso con INSERT directo');}
    $next=$slot();$changes=new CitaChangeService(new CitaChangeRepository($pdo),true);
    $changes->change($patient,'reprogramar',['id'=>(string)$id,'version'=>CitaChangePolicy::version($c),'fecha'=>$next['fecha'],'hora'=>$next['hora']]);
    try{isolatedWrite($pdo,'UPDATE public.citas SET fecha=:d,hora_inicio=:a,hora_fin=:b WHERE id_cita=:id',['d'=>$c['fecha'],'a'=>$c['hora_inicio'],'b'=>$c['hora_fin'],'id'=>$id],$id);throw new RuntimeException('Falta trigger de reprogramación.');}catch(PDOException $e){assertIntegration($e->getCode()==='P0001' && str_contains($e->getMessage(),'Solo se permite'),'Trigger rechaza segunda reprogramación aun fuera del servicio');}
    $payments=json_decode(file_get_contents($dir.'/payments.json'),true);$cash=$payments['efectivo'];putenv('PAYMENT_ALLOWED_APPOINTMENTS='.$cash['appointment']);
    $s=$pdo->prepare('SELECT fecha_vencimiento FROM public.reservas_provisionales WHERE id_cita=:id');$s->execute(['id'=>$cash['appointment']]);$clock=(new DateTimeImmutable($s->fetchColumn()))->modify('-1 minute');
    assertIntegration(PaymentPolicy::cashOpen($clock),'Existe oportunidad de caja antes del plazo máximo de 48 horas');
    $cashRepo=new PaymentWriteRepository($pdo,new PayPalSandboxService('','','',''),static fn()=>$clock);
    try{$cashRepo->confirm($actors['Administrador'],$cash['appointment'],$cash['payment']);throw new RuntimeException('Administrador cobró efectivo.');}catch(DomainException $e){assertIntegration(true,'Administrador no confirma efectivo');}
    assertIntegration($cashRepo->confirm($actors['Recepcionista'],$cash['appointment'],$cash['payment']),'Efectivo COMMIT real con recepción y reloj de negocio controlado dentro de caja');
    assertIntegration($cashRepo->confirm($actors['Recepcionista'],$cash['appointment'],$cash['payment']),'Efectivo repetido no duplica cobro ni comprobante');
    $r=$booking->reserve($patient,$slot());putenv('PAYMENT_ALLOWED_APPOINTMENTS='.$r['id']);$payRepo=new PaymentWriteRepository($pdo,new PayPalSandboxService('','','',''));$pid=$payRepo->start($patient,$r['id'],'qr_simulado','demo');
    assertIntegration($payRepo->start($patient,$r['id'],'qr_simulado','demo')===$pid,'Inicio de pago repetido devuelve el mismo registro');
    isolatedWrite($pdo,"UPDATE public.reservas_provisionales SET fecha_creacion=clock_timestamp()-interval '31 minutes',fecha_vencimiento=clock_timestamp()-interval '1 minute' WHERE id_cita=:id",['id'=>$r['id']],$r['id']);
    assertIntegration(!$payRepo->confirm($patient,$r['id'],$pid),'Reserva con fixture temporal vencida no permite confirmar');
    $s=$pdo->prepare('SELECT p.estado AS pago,r.estado AS reserva,c.estado AS cita FROM public.pagos p JOIN public.citas c ON c.id_cita=p.id_cita JOIN public.reservas_provisionales r ON r.id_cita=c.id_cita WHERE p.id_pago=:id');$s->execute(['id'=>$pid]);$states=$s->fetch();assertIntegration($states===['pago'=>'cancelado','reserva'=>'expirada','cita'=>'cancelada'],'Vencimiento persiste estados compatibles con las restricciones reales');
    $reads=new PaymentRepository($pdo);assertIntegration($reads->receipt($patient,$pid)===null,'Vencido no emite comprobante');
    foreach($payments as $method=>$p){$row=$reads->receipt($patient,$p['payment']);assertIntegration($row!==null,'Comprobante persistente consultable '.$method);$row['source_code']=$row['codigo_cita'];$pdf=PaymentReceipt::pdf($row);assertIntegration(str_starts_with($pdf,'%PDF-'),'PDF académico generado desde pago PostgreSQL '.$method);file_put_contents($dir.'/receipt-'.$method.'.pdf',$pdf);}
    try{IntegrationGuard::check($pdo,1);throw new RuntimeException('Se autorizó cita heredada.');}catch(DomainException $e){assertIntegration(true,'Citas originales protegidas también en clúster test');}
    try{$reads->listing($actors['Medico']);throw new RuntimeException('Médico consultó pagos.');}catch(DomainException $e){assertIntegration(true,'Médico sin acceso a pagos persistentes');}
    $baseline=json_decode(file_get_contents($dir.'/original-snapshot.json'),true);
    foreach($baseline['tables'] as $table=>$hash){
        $s=$pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=:table AND is_identity='YES'");$s->execute(['table'=>$table]);$col=$s->fetchColumn();$where='';
        if($col){$seq=$pdo->query("SELECT pg_get_serial_sequence('public.$table','$col')")->fetchColumn();$key=substr($seq,strrpos($seq,'.')+1);$max=$baseline['sequences'][$key]['last_value'];$where=' WHERE "'.$col.'"<='.(int)$max;}
        $hashNow=hash('sha256',(string)$pdo->query('SELECT COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text COLLATE "C")::text,\'[]\') FROM (SELECT * FROM public."'.$table.'"'.$where.') t')->fetchColumn());
        assertIntegration($hashNow===$hash,'Filas heredadas intactas en copia: '.$table);
    }
    echo count($checks).' comprobaciones ampliadas con SQL real SOLO en test. Caja: reloj de negocio de ensayo '.$clock->format(DATE_ATOM).'; reloj PostgreSQL sin modificar.'.PHP_EOL;
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction()){$pdo->rollBack();}file_put_contents($dir.'/extended-failure.log',(string)$e);fwrite(STDERR,($e instanceof PDOException ? 'Error PostgreSQL; diagnóstico privado en extended-failure.log.' : $e->getMessage()).PHP_EOL);exit(1);}
