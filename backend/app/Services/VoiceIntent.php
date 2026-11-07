<?php
declare(strict_types=1);
namespace Clinica\Services;

/** Lista cerrada de consultas; el texto nunca se convierte en SQL. */
final class VoiceIntent
{
    public static function normalize(string $text):string
    {
        $text=strtr(mb_strtolower($text,'UTF-8'),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        return trim((string)preg_replace('/\s+/u',' ',str_replace(['¿','?','¡','!','.',','],' ',$text)));
    }
    public static function parse(mixed $text):array
    {
        if(!is_string($text) || !mb_check_encoding($text,'UTF-8') || mb_strlen($text,'UTF-8')>240 || preg_match('/[\x00-\x1f\x7f]/',$text)){
            throw new \InvalidArgumentException('Escriba una consulta de hasta 240 caracteres, sin caracteres de control.');
        }
        $q=self::normalize($text);
        if(preg_match('/^(?:(?:cuales son|muestrame|mostrar|consultar) )?(?:mis |las )?(?:proximas citas|citas proximas)$/D',$q)){
            return ['intent'=>'proximas','specialty'=>null];
        }
        if(preg_match('/^(?:(?:muestrame|mostrar|consultar) )?(?:(?:mi |la )?agenda(?: de)? hoy|(?:mis |las )?citas de hoy)$/D',$q)){
            return ['intent'=>'agenda','specialty'=>null];
        }
        if(preg_match('/^(?:(?:cual es )?(?:el )?estado de (?:mi pago|mis pagos|los pagos|pagos)|mis pagos)$/D',$q)){
            return ['intent'=>'pagos','specialty'=>null];
        }
        if(preg_match('/^(?:que |cuales )?medicos (?:atienden|hay en|de) (?:la especialidad de )?([a-z ]{2,80})$/D',$q,$m)){
            return ['intent'=>'medicos','specialty'=>$m[1]];
        }
        if(preg_match('/^(?:que )?horarios (?:hay )?disponibles(?: (?:de|para|en) ([a-z ]{2,80}))?$/D',$q,$m)){
            return ['intent'=>'horarios','specialty'=>$m[1] ?? null];
        }
        throw new \InvalidArgumentException('Consulta no reconocida. Use los ejemplos de citas, médicos, horarios, agenda o pagos. Solo se permiten consultas; no reservas, cambios, cobros ni SQL.');
    }
}
