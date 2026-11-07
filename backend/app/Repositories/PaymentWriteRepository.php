<?php
declare(strict_types=1);
namespace Clinica\Repositories;
use Clinica\Services\{PaymentPolicy,BookingConflict,PayPalSandboxService};
use Clinica\Middleware\PaymentAccess;
use Clinica\Support\Environment;
final class PaymentWriteRepository
{
    public function __construct(private \PDO $pdo,private PayPalSandboxService $paypal){}
    private function run(array $actor,int $appointment,callable $work):mixed
    {
        if(!in_array((string)$appointment,explode(',',Environment::get('PAYMENT_ALLOWED_APPOINTMENTS','')),true)){throw new \DomainException('Esta cita no está autorizada para la prueba persistente.');}
        $this->pdo->beginTransaction();
        try{
            $this->pdo->exec("SET LOCAL lock_timeout='3s'");$c=(new PaymentRepository($this->pdo))->appointment($actor,$appointment,true);if(!$c){throw new \DomainException('Cita fuera de su ámbito.');}
            $this->sql("SELECT set_config('app.usuario_id',:actor,true)",['actor'=>(string)$actor['id']]);
            $now=new \DateTimeImmutable($this->pdo->query('SELECT clock_timestamp()::text')->fetchColumn());
            $result=$work($c,$now);$this->pdo->commit();return $result;
        }catch(\Throwable $e){if($this->pdo->inTransaction()){$this->pdo->rollBack();}if($e instanceof \PDOException){throw new BookingConflict('La operación cambió o no pudo guardarse. Revise el pago antes de reintentar.');}throw $e;}
    }
    private function sql(string $sql,array $params=[]):\PDOStatement{$s=$this->pdo->prepare($sql);$s->execute($params);return $s;}
    public function start(array $actor,int $appointment,string $method,string $mode):int
    {
        if(!isset(PaymentPolicy::METHODS[$method]) || !in_array($mode,['demo','sandbox'],true)){throw new \InvalidArgumentException('Método o modo inválido.');}
        if($method==='efectivo' ? !(PaymentAccess::pay($actor) || PaymentAccess::cash($actor)) : !PaymentAccess::pay($actor)){throw new \DomainException('Inicio de pago no autorizado.');}
        if(($mode==='sandbox')!==($method==='paypal_sandbox')){throw new BookingConflict('El PayPal persistente exige Sandbox auténtico; use el simulador para ensayo local.');}
        return $this->run($actor,$appointment,function(array $c,\DateTimeImmutable $now)use($actor,$method,$mode):int{
            if($c['estado']!=='programada'){throw new BookingConflict('La cita no admite iniciar un pago.');}
            $existing=$this->sql('SELECT id_pago,metodo FROM public.pagos WHERE id_cita=:id FOR UPDATE',['id'=>$c['id_cita']])->fetchAll();
            if($existing){if(count($existing)===1 && $existing[0]['metodo']===$method){return (int)$existing[0]['id_pago'];}throw new BookingConflict('La cita ya tiene un pago; no se duplicará.');}
            if($this->sql('SELECT id_reserva FROM public.reservas_provisionales WHERE id_cita=:id FOR UPDATE',['id'=>$c['id_cita']])->fetchColumn()!==false){throw new BookingConflict('La cita ya tiene una reserva provisional.');}
            $created=new \DateTimeImmutable($c['fecha_registro']);$date=new \DateTimeImmutable($c['fecha'].' '.$c['hora_inicio'],new \DateTimeZone('America/La_Paz'));
            if($method==='efectivo'){
                $value=$this->sql('SELECT public.fn_vencimiento_pago_efectivo(CAST(:created AS timestamptz),CAST(:date AS date),CAST(:time AS time))',['created'=>$created->format(DATE_ATOM),'date'=>$c['fecha'],'time'=>$c['hora_inicio']])->fetchColumn();$expiry=$value ? new \DateTimeImmutable($value) : null;
            }else{$minutes=Environment::get('PAYMENT_ONLINE_HOLD_MINUTES','');$expiry=PaymentPolicy::deadline($method,$created,$date,ctype_digit($minutes) ? (int)$minutes : null);}
            if(!$expiry || $expiry<=$now){throw new BookingConflict('La reserva no tiene un plazo vigente. Elija otro turno o método.');}
            $amount=PaymentPolicy::amount($c['precio_consulta']);$reference='P9-'.strtoupper(bin2hex(random_bytes(16)));
            $this->sql("INSERT INTO public.reservas_provisionales(id_cita,fecha_creacion,fecha_vencimiento,estado) VALUES(:id,:created,:expiry,'pendiente')",['id'=>$c['id_cita'],'created'=>$created->format(DATE_ATOM),'expiry'=>$expiry->format(DATE_ATOM)]);
            $id=(int)$this->sql("INSERT INTO public.pagos(id_cita,id_usuario_registro,importe,moneda,metodo,estado,referencia) VALUES(:id,:actor,:amount,'BOB',:method,'pendiente',:ref) RETURNING id_pago",['id'=>$c['id_cita'],'actor'=>$actor['id'],'amount'=>$amount,'method'=>$method,'ref'=>$reference])->fetchColumn();
            if($mode==='sandbox'){$order=$this->paypal->create($reference,$amount,$id);$this->sql("INSERT INTO public.transacciones_pago(id_pago,proveedor,referencia_externa,estado,detalles) VALUES(:id,'paypal_sandbox',:ref,'CREATED',CAST(:details AS jsonb))",['id'=>$id,'ref'=>$order['order'],'details'=>json_encode($order,JSON_THROW_ON_ERROR)]);}
            return $id;
        });
    }
    public function confirm(array $actor,int $appointment,int $payment):bool
    {
        return $this->run($actor,$appointment,function(array $c,\DateTimeImmutable $now)use($actor,$payment):bool{
            $p=$this->sql('SELECT * FROM public.pagos WHERE id_pago=:id AND id_cita=:cita FOR UPDATE',['id'=>$payment,'cita'=>$c['id_cita']])->fetch();if(!$p){throw new \DomainException('Pago fuera de su ámbito.');}
            if($p['metodo']==='efectivo' ? !PaymentAccess::cash($actor) : !PaymentAccess::pay($actor)){throw new \DomainException('Confirmación no autorizada.');}
            if($p['estado']==='confirmado'){return true;}
            $r=$this->sql('SELECT * FROM public.reservas_provisionales WHERE id_cita=:id FOR UPDATE',['id'=>$c['id_cita']])->fetch();
            if(!$r || $r['estado']!=='pendiente' || $c['estado']!=='programada' || !in_array($p['estado'],['pendiente','rechazado'],true)){throw new BookingConflict('Reserva o pago incompatibles.');}
            if(new \DateTimeImmutable($r['fecha_vencimiento'])<=$now){
                $this->sql('CALL public.cancelar_cita(CAST(:id AS bigint),:reason)',['id'=>$c['id_cita'],'reason'=>'Reserva vencida por falta de pago']);$this->sql("UPDATE public.reservas_provisionales SET estado='expirada' WHERE id_reserva=:id",['id'=>$r['id_reserva']]);return false;
            }
            if(new \DateTimeImmutable($c['fecha'].' '.$c['hora_inicio'],new \DateTimeZone('America/La_Paz'))<=$now){throw new BookingConflict('La cita ya no es futura.');}
            if($p['metodo']==='efectivo' && !PaymentPolicy::cashOpen($now)){throw new BookingConflict('Caja cerrada.');}
            $external='SIM-'.bin2hex(random_bytes(12));$provider=$p['metodo']==='efectivo' ? 'efectivo_simulado' : 'qr_simulado';
            if($p['metodo']==='paypal_sandbox'){
                if(new \DateTimeImmutable($r['fecha_vencimiento'])<$now->modify('+45 seconds')){throw new BookingConflict('No queda margen para verificar Sandbox antes del vencimiento.');}
                $t=$this->sql("SELECT referencia_externa,detalles FROM public.transacciones_pago WHERE id_pago=:id AND proveedor='paypal_sandbox' AND estado='CREATED' ORDER BY id_transaccion LIMIT 1",['id'=>$payment])->fetch();if(!$t){throw new BookingConflict('Falta la orden Sandbox registrada.');}$details=json_decode($t['detalles'],true,512,JSON_THROW_ON_ERROR);
                $external=$this->paypal->capture($t['referencia_externa'],$p['referencia'],$details['usd']);$provider='paypal_sandbox';
            }
            $this->sql("UPDATE public.pagos SET estado='confirmado',fecha_confirmacion=clock_timestamp(),id_usuario_registro=:actor WHERE id_pago=:id",['actor'=>$actor['id'],'id'=>$payment]);
            $this->sql("UPDATE public.reservas_provisionales SET estado='confirmada' WHERE id_reserva=:id",['id'=>$r['id_reserva']]);$this->sql("UPDATE public.citas SET estado='confirmada' WHERE id_cita=:id",['id'=>$c['id_cita']]);
            $this->sql("INSERT INTO public.transacciones_pago(id_pago,proveedor,referencia_externa,estado,detalles) VALUES(:id,:provider,:external,'COMPLETED',CAST(:details AS jsonb))",['id'=>$payment,'provider'=>$provider,'external'=>$external,'details'=>json_encode(['simulacion'=>$provider!=='paypal_sandbox'],JSON_THROW_ON_ERROR)]);
            $this->sql('INSERT INTO public.comprobantes_pago(id_pago,numero_comprobante) VALUES(:id,:number) ON CONFLICT(id_pago) DO NOTHING',['id'=>$payment,'number'=>'ACA-'.strtoupper(bin2hex(random_bytes(16)))]);return true;
        });
    }
}
