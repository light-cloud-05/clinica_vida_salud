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
        if($r['reserva_estado']==='pendiente' && $r['estado']!=='confirmado' && self::now($r)>=new \DateTimeImmutable($r['fecha_vencimiento'])){$r['reserva_estado']='expirada';$r['cita_estado']='cancelada';}
    }
    public function create(array $actor,array $context,string $amount,string $method,?\DateTimeImmutable $rehearsal,string $intent):string
    {
        if(!PaymentAccess::view($actor) || (!PaymentAccess::pay($actor) && !PaymentAccess::cash($actor))){throw new \DomainException('Inicio de pago no autorizado.');}
        if(!preg_match('/^[a-f0-9]{64}$/D',$intent)){throw new \InvalidArgumentException('Intención inválida.');}
        $now=$rehearsal ?? PaymentPolicy::clock();$appointment=new \DateTimeImmutable($context['fecha'].' '.$context['hora'],new \DateTimeZone('America/La_Paz'));
        $deadline=PaymentPolicy::deadline($method,$now,$appointment,30);$amount=PaymentPolicy::amount($amount);
        return $this->store->transaction(function(array &$rows)use($actor,$context,$amount,$method,$rehearsal,$intent,$now,$deadline):string{
            $digest=hash('sha256',json_encode([$context,$method,$rehearsal?->format(DATE_ATOM)],JSON_THROW_ON_ERROR));
            foreach($rows as &$r){self::expire($r);if($r['intent']===$intent && $r['creator']===$actor['id']){if($r['digest']!==$digest){throw new BookingConflict('El formulario ya se utilizó con otros datos.');}return $r['id'];}}unset($r);
            foreach($rows as $r){if($r['cita_estado']!=='cancelada' && (int)$r['medico']===$context['medico'] && $r['fecha']===$context['fecha'] && DisponibilidadService::overlaps(['hora_inicio'=>$context['hora'],'hora_fin'=>$context['end']],['hora_inicio'=>$r['hora_inicio'],'hora_fin'=>$r['hora_fin']])){throw new BookingConflict('El simulador ya tiene ese turno ocupado. Elija otro.');}}
            if(count($rows)>=500){throw new BookingConflict('El almacén de ensayo alcanzó su límite local.');}
            $id=bin2hex(random_bytes(16));$code='SIM-'.strtoupper(substr($id,0,16));
            $rows[$id]=['id'=>$id,'creator'=>$actor['id'],'owner'=>$context['owner'],'patient'=>$context['paciente'],'medico'=>$context['medico'],'especialidad'=>$context['especialidad'],'fecha'=>$context['fecha'],'hora_inicio'=>$context['hora'],'hora_fin'=>$context['end'],'codigo_cita'=>$code,'importe'=>$amount,'moneda'=>'BOB','metodo'=>$method,'estado'=>'pendiente','reserva_estado'=>'pendiente','cita_estado'=>'programada','fecha_creacion'=>$now->format(DATE_ATOM),'fecha_vencimiento'=>$deadline->format(DATE_ATOM),'fecha_confirmacion'=>null,'referencia'=>$code,'numero_comprobante'=>null,'intent'=>$intent,'digest'=>$digest,'events'=>[]];
            if($rehearsal){$rows[$id]['demo_clock']=$rehearsal->format(DATE_ATOM);}return $id;
        });
    }
    public function listing(array $actor):array
    {
        if(!PaymentAccess::view($actor)){throw new \DomainException('Consulta no autorizada.');}
        return $this->store->transaction(function(array &$rows)use($actor):array{$out=[];foreach($rows as &$r){if($this->scope($actor,$r)){self::expire($r);$out[]=$r;}}unset($r);return array_reverse($out);});
    }
    public function find(array $actor,string $id):?array{foreach($this->listing($actor) as $r){if(hash_equals($r['id'],$id)){return $r;}}return null;}
    public function action(array $actor,string $id,string $action,?\DateTimeImmutable $clock=null):void
    {
        $this->store->transaction(function(array &$rows)use($actor,$id,$action,$clock):void{
            if(!isset($rows[$id]) || !$this->scope($actor,$rows[$id])){throw new \DomainException('Ensayo no disponible dentro de su ámbito.');}$r=&$rows[$id];
            if($action==='reloj'){
                if(!PaymentAccess::cash($actor) || !$clock || $clock<self::now($r)){throw new \DomainException('Solo recepción puede avanzar el reloj de ensayo.');}$r['demo_clock']=$clock->format(DATE_ATOM);self::expire($r);return;
            }
            if(!in_array($action,['aprobar','rechazar'],true)){throw new \InvalidArgumentException('Operación inválida.');}
            if($r['metodo']==='efectivo' ? !PaymentAccess::cash($actor) : !PaymentAccess::pay($actor)){throw new \DomainException('Confirmación no autorizada para este método.');}
            // Se comprueba antes de cualquier transición; un resultado repetido no emite otro comprobante.
            self::expire($r);
            if($r['estado']==='confirmado'){return;}
            if($r['reserva_estado']!=='pendiente' || $r['cita_estado']!=='programada'){throw new BookingConflict('La reserva de ensayo venció o ya no está disponible.');}
            if($r['metodo']==='efectivo' && !PaymentPolicy::cashOpen(self::now($r))){throw new BookingConflict('Caja cerrada: lunes a viernes de 08:00 a 18:00.');}
            $r['events'][]=['proveedor'=>$r['metodo']==='paypal_sandbox' ? 'paypal_simulacion_local' : ($r['metodo']==='efectivo' ? 'efectivo_simulado' : 'qr_simulado'),'estado'=>$action,'referencia_externa'=>'SIM-'.bin2hex(random_bytes(8)),'actor'=>$actor['id']];
            if($action==='rechazar'){$r['estado']='rechazado';return;}
            $r['estado']='confirmado';$r['reserva_estado']='confirmada';$r['cita_estado']='confirmada';$r['fecha_confirmacion']=self::now($r)->format(DATE_ATOM);$r['numero_comprobante']='DEMO-'.strtoupper(substr($id,0,20));
        });
    }
}
