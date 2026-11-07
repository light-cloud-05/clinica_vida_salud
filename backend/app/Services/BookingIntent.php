<?php
declare(strict_types=1);
namespace Clinica\Services;
final class BookingIntent
{
    public static function issue(array $context): string
    {
        $entries=$_SESSION['booking_intents'] ?? [];
        $entries=array_filter($entries,static fn(array $entry): bool=>$entry['expires']>=time());
        if(count($entries)>=20){array_shift($entries);}
        $token=bin2hex(random_bytes(32));$entries[$token]=['context'=>BookingInput::context($context),'expires'=>time()+900,'result'=>null,'payload'=>null];$_SESSION['booking_intents']=$entries;return $token;
    }
    public static function check(mixed $token,array $input): ?int
    {
        $entry=is_string($token) ? ($_SESSION['booking_intents'][$token] ?? null) : null;
        if(!$entry || $entry['expires']<time() || !hash_equals($entry['context'],BookingInput::context($input))){throw new BookingConflict('El formulario venció o cambió de franja. Vuelva a consultar disponibilidad.');}
        if($entry['result']!==null && !hash_equals($entry['payload'],BookingInput::payload($input))){throw new BookingConflict('Este formulario ya se utilizó con otros datos.');}
        return $entry['result'];
    }
    public static function complete(string $token,array $input,int $id): void
    {
        $_SESSION['booking_intents'][$token]['result']=$id;$_SESSION['booking_intents'][$token]['payload']=BookingInput::payload($input);
    }
}
