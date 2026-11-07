<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Repositories\PaymentDemoStore;
use Clinica\Middleware\PaymentAccess;
final class PaymentDemoService
{
    public function __construct(private PaymentDemoStore $store){}
    private function scope(array $actor,array $row):bool{return PaymentAccess::view($actor) && ($actor['role']!=='Paciente' || (int)$row['owner']===(int)$actor['id']);}
    private static function now(array $r):\DateTimeImmutable{return isset($r['demo_clock']) ? new \DateTimeImmutable($r['demo_clock']) : PaymentPolicy::clock();}
    private static function expire(array &$r):void
    {
        if($r['reserva_estado']==='pendiente' && $r['estado']!=='confirmado' && self::now($r)>=new \DateTimeImmutable($r['fecha_vencimiento'])){$r['estado']='vencido';$r['reserva_estado']='expirada';$r['cita_estado']='cancelada';}
    }
    public function create(array $actor,array $context,string $amount,string $method,?\DateTimeImmutable $rehearsal,string $intent):string
    {
        if(!PaymentAccess::view($actor) || ($method==='efectivo' ? !(PaymentAccess::pay($actor) || PaymentAccess::cash($actor)) : !PaymentAccess::pay($actor))){throw new \DomainException('Inicio de pago no autorizado para este método.');}
        if($actor['role']==='Paciente' && (int)$context['owner']!==(int)$actor['id']){throw new \DomainException('Cita ajena.');}
        if(!preg_match('/^[a-f0-9]{64}$/D',$intent)){throw new \InvalidArgumentException('Intención inválida.');}
        $now=$rehearsal ?? PaymentPolicy::clock();$appointment=new \DateTimeImmutable($context['fecha'].' '.$context['hora'],new \DateTimeZone('America/La_Paz'));
        $created=$method==='efectivo' && isset($context['created']) ? new \DateTimeImmutable($context['created']) : $now;
        if($created>$now){throw new BookingConflict('El reloj precede a la reserva.');}
        $deadline=PaymentPolicy::deadline($method,$created,$appointment,30);
        if(!empty($context['reserve_deadline'])){$deadline=min($deadline,new \DateTimeImmutable($context['reserve_deadline']));}
        if($deadline<=$now){throw new BookingConflict('El plazo de pago venció.');}
        $amount=PaymentPolicy::amount($amount);
        return $this->store->transaction(function(array &$rows)use($actor,$context,$amount,$method,$rehearsal,$intent,$now,$deadline):string{
            $digest=hash('sha256',json_encode([$context,$amount,$method,$rehearsal?->format(DATE_ATOM)],JSON_THROW_ON_ERROR));
            foreach($rows as &$r){self::expire($r);if($r['intent']===$intent && $r['creator']===$actor['id']){if($r['digest']!==$digest){throw new BookingConflict('El formulario ya se utilizó con otros datos.');}return $r['id'];}}unset($r);
            foreach($rows as $r){if(isset($context['appointment']) && ($r['appointment'] ?? null)===$context['appointment'] && in_array($r['estado'],['pendiente','confirmado'],true)){throw new BookingConflict('La cita ya tiene un ensayo activo.');}if($r['cita_estado']!=='cancelada' && (int)$r['medico']===$context['medico'] && $r['fecha']===$context['fecha'] && DisponibilidadService::overlaps(['hora_inicio'=>$context['hora'],'hora_fin'=>$context['end']],['hora_inicio'=>$r['hora_inicio'],'hora_fin'=>$r['hora_fin']])){throw new BookingConflict('El simulador ya tiene ese turno ocupado. Elija otro.');}}
            if(count($rows)>=500){throw new BookingConflict('El almacén de ensayo alcanzó su límite local.');}
            $id=bin2hex(random_bytes(16));$code='SIM-'.strtoupper(substr($id,0,16));
            $rows[$id]=['id'=>$id,'creator'=>$actor['id'],'owner'=>$context['owner'],'patient'=>$context['paciente'],'medico'=>$context['medico'],'especialidad'=>$context['especialidad'],'fecha'=>$context['fecha'],'hora_inicio'=>$context['hora'],'hora_fin'=>$context['end'],'codigo_cita'=>$code,'importe'=>$amount,'moneda'=>'BOB','metodo'=>$method,'estado'=>'pendiente','reserva_estado'=>'pendiente','cita_estado'=>'programada','fecha_creacion'=>$now->format(DATE_ATOM),'fecha_vencimiento'=>$deadline->format(DATE_ATOM),'fecha_confirmacion'=>null,'referencia'=>$code,'numero_comprobante'=>null,'intent'=>$intent,'digest'=>$digest,'events'=>[]];
            $rows[$id]['appointment']=$context['appointment'] ?? null;
            $rows[$id]['source_code']=$context['code'] ?? null;
            $rows[$id]['source_reserve_deadline']=$context['reserve_deadline'] ?? null;
            if($rehearsal){$rows[$id]['demo_clock']=$rehearsal->format(DATE_ATOM);}return $id;
        });
    }
    public function listing(array $actor):array
    {
        if(!PaymentAccess::view($actor)){throw new \DomainException('Consulta no autorizada.');}
        return $this->store->transaction(function(array &$rows)use($actor):array{$out=[];foreach($rows as &$r){if($this->scope($actor,$r)){self::expire($r);$out[]=$r;}}unset($r);return array_reverse($out);});
    }
    public function find(array $actor,string $id):?array{foreach($this->listing($actor) as $r){if(hash_equals($r['id'],$id)){return $r;}}return null;}
    /** Orden/captura Sandbox auténtica, resultado solo local. Nunca escribe PostgreSQL. */
    public function sandbox(array $actor,string $id,bool $capture,PayPalSandboxService $paypal):?string
    {
        if(\Clinica\Support\Environment::get('PAYMENT_SANDBOX_ENABLED','false')!=='true'){throw new BookingConflict('Sandbox externo bloqueado; use la simulación local.');}
        return $this->store->transaction(function(array &$rows)use($actor,$id,$capture,$paypal):?string{
            if(!isset($rows[$id]) || !$this->scope($actor,$rows[$id]) || !PaymentAccess::pay($actor)){throw new \DomainException('Orden fuera de su ámbito.');}
            $r=&$rows[$id];self::expire($r);
            if($capture && $r['estado']==='confirmado' && isset($r['sandbox'])){return null;}
            if($r['metodo']!=='paypal_sandbox' || isset($r['demo_clock']) || $r['estado']!=='pendiente' || $r['reserva_estado']!=='pendiente'){throw new BookingConflict('Sandbox exige un ensayo pendiente sin reloj simulado.');}
            if(!isset($r['sandbox'])){
                if(empty($r['source_reserve_deadline'])){throw new BookingConflict('Sandbox externo requiere una reserva provisional vigente de PostgreSQL. No se aplica el plazo local de 30 minutos.');}
                $r['fecha_vencimiento']=$r['source_reserve_deadline'];
            }
            if(new \DateTimeImmutable($r['fecha_vencimiento'])<PaymentPolicy::clock()->modify('+45 seconds')){throw new BookingConflict('No queda margen para verificar Sandbox.');}
            if(!$capture){
                $r['sandbox']=$r['sandbox'] ?? $paypal->create($r['referencia'],$r['importe'],$id);
                return $r['sandbox']['approve'];
            }
            if(empty($r['sandbox'])){throw new BookingConflict('Primero cree y apruebe la orden en Sandbox.');}
            $external=$paypal->capture($r['sandbox']['order'],$r['referencia'],$r['sandbox']['usd']);
            $r['estado']='confirmado';$r['reserva_estado']='confirmada';$r['cita_estado']='confirmada';
            $r['fecha_confirmacion']=PaymentPolicy::clock()->format(DATE_ATOM);$r['numero_comprobante']='SANDBOX-'.strtoupper(substr($id,0,20));
            $r['events'][]=['proveedor'=>'paypal_sandbox','estado'=>'COMPLETED','referencia_externa'=>$external,'actor'=>$actor['id']];
            return null;
        });
    }
    public function action(array $actor,string $id,string $action,?\DateTimeImmutable $clock=null):void
    {
        $this->store->transaction(function(array &$rows)use($actor,$id,$action,$clock):void{
            if(!isset($rows[$id]) || !$this->scope($actor,$rows[$id])){throw new \DomainException('Ensayo no disponible dentro de su ámbito.');}$r=&$rows[$id];
            if($action==='reloj'){
                if(isset($r['sandbox'])){throw new BookingConflict('Una orden Sandbox externa no admite reloj simulado.');}
                if(!PaymentAccess::cash($actor) || !$clock || $clock<self::now($r)){throw new \DomainException('Solo recepción puede avanzar el reloj de ensayo.');}$r['demo_clock']=$clock->format(DATE_ATOM);self::expire($r);return;
            }
            if(!in_array($action,['aprobar','rechazar'],true)){throw new \InvalidArgumentException('Operación inválida.');}
            if(isset($r['sandbox'])){throw new BookingConflict('Una orden Sandbox no admite resultados simulados. Verifique la captura en el proveedor.');}
            if($r['metodo']==='efectivo' ? !PaymentAccess::cash($actor) : !PaymentAccess::pay($actor)){throw new \DomainException('Confirmación no autorizada para este método.');}
            // Se comprueba antes de cualquier transición; un resultado repetido no emite otro comprobante.
            self::expire($r);
            if($r['estado']==='confirmado'){if($action==='aprobar'){return;}throw new BookingConflict('Un pago confirmado no puede rechazarse.');}
            if($r['estado']!=='pendiente'){throw new BookingConflict('El intento ya terminó.');}
            if($r['reserva_estado']!=='pendiente' || $r['cita_estado']!=='programada'){throw new BookingConflict('La reserva de ensayo venció o ya no está disponible.');}
            if($r['metodo']==='efectivo' && !PaymentPolicy::cashOpen(self::now($r))){throw new BookingConflict('Caja cerrada: lunes a viernes de 08:00 a 18:00.');}
            $r['events'][]=['proveedor'=>$r['metodo']==='paypal_sandbox' ? 'paypal_simulacion_local' : ($r['metodo']==='efectivo' ? 'efectivo_simulado' : 'qr_simulado'),'estado'=>$action,'referencia_externa'=>'SIM-'.bin2hex(random_bytes(8)),'actor'=>$actor['id']];
            if($action==='rechazar'){$r['estado']='rechazado';$r['reserva_estado']='cancelada';$r['cita_estado']='cancelada';return;}
            $r['estado']='confirmado';$r['reserva_estado']='confirmada';$r['cita_estado']='confirmada';$r['fecha_confirmacion']=self::now($r)->format(DATE_ATOM);$r['numero_comprobante']='DEMO-'.strtoupper(substr($id,0,20));
        });
    }
}
