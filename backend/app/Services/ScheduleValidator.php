<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Support\Input;
final class ScheduleValidator
{
    public const DAYS=[1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes'];
    public static function fields(string $kind): array
    {
        return match($kind){
            'horario'=>['id_medico','dia_semana','hora_inicio','hora_fin','duracion_cita_minutos','estado'],
            'bloqueo'=>['id_medico','fecha','hora_inicio','hora_fin','motivo'],
            default=>throw new \InvalidArgumentException('Tipo de horario inválido.')
        };
    }
    public static function date(mixed $value): ?\DateTimeImmutable
    {
        if(!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value) || substr($value,0,4)==='0000'){return null;}
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('America/La_Paz'));
        return $date && $date->format('Y-m-d')===$value ? $date : null;
    }
    public static function time(mixed $value): ?int
    {
        if(!is_string($value) || !preg_match('/^([0-9]{2}):([0-9]{2})(?::([0-9]{2})(?:\.([0-9]{1,6}))?)?$/D',$value,$p)){return null;}
        $h=(int)$p[1];$m=(int)$p[2];$s=(int)($p[3] ?? 0);$u=(int)str_pad($p[4] ?? '',6,'0');
        if($h>24 || $m>59 || $s>59 || ($h===24 && ($m || $s || $u))){return null;}
        return ($h*3600+$m*60+$s)*1000000+$u;
    }
    public static function formatTime(int $time): string
    {
        $seconds=intdiv($time,1000000);$value=sprintf('%02d:%02d:%02d',intdiv($seconds,3600),intdiv($seconds%3600,60),$seconds%60);
        return $time%1000000 ? $value.'.'.rtrim(sprintf('%06d',$time%1000000),'0') : $value;
    }
    public static function version(string $kind,array $row): string
    {
        $values=[];foreach(self::fields($kind) as $key){$values[$key]=isset($row[$key]) ? (string)$row[$key] : null;}
        if($kind==='horario'){$values['updated_at']=$row['updated_at'] ?? null;}
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }
    public static function displayTime(string $time): string
    {
        return preg_match('/^[0-9]{2}:[0-9]{2}:00$/D',$time) ? substr($time,0,5) : $time;
    }
    public static function validate(string $kind,array $input): array
    {
        $fields=self::fields($kind);$errors=[];$data=[];
        if(array_diff(array_keys($input),array_merge($fields,['csrf','version']))){$errors['_form']='El formulario contiene campos que no se pueden editar.';}
        foreach($fields as $field){$value=$input[$field] ?? ($field==='duracion_cita_minutos' ? '30' : '');
            if(!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0")){$errors[$field]='Valor inválido.';$value='';}
            $data[$field]=trim($value);
        }
        $doctor=Input::positiveId($data['id_medico']);if($doctor===null){$errors['id_medico']='Seleccione un médico válido.';}else{$data['id_medico']=$doctor;}
        $start=self::time($data['hora_inicio']);$end=self::time($data['hora_fin']);
        if($start===null){$errors['hora_inicio']='Ingrese una hora inicial válida.';}
        if($end===null || ($start!==null && $end<=$start)){$errors['hora_fin']='La hora final debe ser válida y posterior a la inicial.';}
        if($start!==null){$data['hora_inicio']=self::formatTime($start);}if($end!==null){$data['hora_fin']=self::formatTime($end);}
        if($kind==='horario'){
            if(!in_array($data['dia_semana'],['1','2','3','4','5'],true)){$errors['dia_semana']='Seleccione lunes a viernes.';}else{$data['dia_semana']=(int)$data['dia_semana'];}
            $duration=Input::positiveId($data['duracion_cita_minutos']);if($duration===null || $duration>2147483647){$errors['duracion_cita_minutos']='Ingrese una duración entera positiva válida.';}else{$data['duracion_cita_minutos']=$duration;}
            if(!in_array($data['estado'],['activo','inactivo'],true)){$errors['estado']='Seleccione activo o inactivo.';}
        }else{
            if(!self::date($data['fecha'])){$errors['fecha']='Ingrese una fecha válida (AAAA-MM-DD).';}
            if($data['motivo']===''){$data['motivo']=null;}
        }
        return ['data'=>$data,'errors'=>$errors];
    }
}
