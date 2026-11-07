<?php
declare(strict_types=1);
namespace Clinica\Services;
final class PaymentPolicy
{
    public const METHODS=['paypal_sandbox'=>'PayPal Sandbox / simulación local','qr_simulado'=>'QR de demostración','efectivo'=>'Efectivo ficticio en recepción'];
    public static function clock():\DateTimeImmutable{return new \DateTimeImmutable('now',new \DateTimeZone('America/La_Paz'));}
    public static function rehearsal(mixed $value):?\DateTimeImmutable
    {
        if($value===null || $value===''){return null;}
        if(!is_string($value)){throw new \InvalidArgumentException('Reloj de ensayo inválido.');}
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone('America/La_Paz'));
        if(!$d || $d->format('Y-m-d\TH:i')!==$value){throw new \InvalidArgumentException('Reloj de ensayo inválido.');}return $d;
    }
    public static function amount(string $value):string
    {
        if(!preg_match('/^\d{1,10}(\.\d{1,2})?$/D',$value) || bccomp($value,'0',2)<=0){throw new \InvalidArgumentException('Importe inválido.');}return bcadd($value,'0',2);
    }
    public static function cashOpen(\DateTimeImmutable $now):bool
    {
        $d=$now->setTimezone(new \DateTimeZone('America/La_Paz'));return (int)$d->format('N')<=5 && $d->format('H:i:s')>='08:00:00' && $d->format('H:i:s')<'18:00:00';
    }
    /** Equivalente a fn_vencimiento_pago_efectivo, utilizada para simulaciones y contraste SELECT. */
    public static function cashDeadline(\DateTimeImmutable $created,\DateTimeImmutable $appointment):?\DateTimeImmutable
    {
        $tz=new \DateTimeZone('America/La_Paz');$created=$created->setTimezone($tz);$limit=min($created->modify('+48 hours'),$appointment->modify('-3 hours'));$result=null;
        for($d=$created->setTime(0,0);$d<=$limit;$d=$d->modify('+1 day')){
            if((int)$d->format('N')>5){continue;}$end=min($limit,$d->setTime(18,0));$start=max($created,$d->setTime(8,0));if($end>$start){$result=$end;}
        }return $result;
    }
    public static function deadline(string $method,\DateTimeImmutable $created,\DateTimeImmutable $appointment,?int $onlineMinutes):\DateTimeImmutable
    {
        if(!isset(self::METHODS[$method])){throw new \InvalidArgumentException('Método no permitido.');}
        $deadline=$method==='efectivo' ? self::cashDeadline($created,$appointment) : ($onlineMinutes!==null && $onlineMinutes>0 && $onlineMinutes<=1440 ? min($created->modify('+'.$onlineMinutes.' minutes'),$appointment) : null);
        if(!$deadline || $deadline<=$created){throw new BookingConflict($method==='efectivo' ? 'No existe oportunidad de caja antes del límite. Elija QR o PayPal simulado.' : 'Falta autorizar un plazo online válido.');}return $deadline;
    }
}
