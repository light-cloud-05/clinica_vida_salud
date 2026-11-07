<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Middleware\BookingAccess;
use Clinica\Support\Input;
final class BookingInput
{
    public static function parse(array $actor,array $input): array
    {
        if(($actor['id'] ?? 0)<1 || !BookingAccess::allowed($actor)){throw new \DomainException('No tiene permiso para reservar.');}
        if($actor['role']==='Paciente' && array_key_exists('paciente',$input)){throw new \DomainException('El paciente de la reserva se obtiene exclusivamente de su sesión.');}
        if(array_diff(array_keys($input),['medico','especialidad','fecha','hora','paciente','csrf','booking_token'])){throw new \InvalidArgumentException('El formulario contiene campos no permitidos.');}
        $doctor=Input::positiveId($input['medico'] ?? null);$specialty=Input::positiveId($input['especialidad'] ?? null);$date=$input['fecha'] ?? null;$time=ScheduleValidator::time($input['hora'] ?? null);
        if($doctor===null || $specialty===null || !ScheduleValidator::date($date) || $time===null){throw new \InvalidArgumentException('Seleccione médico, especialidad, fecha y hora válidos.');}
        $patient=$actor['role']==='Paciente' ? null : Input::positiveId($input['paciente'] ?? null);
        if($actor['role']!=='Paciente' && $patient===null){throw new \InvalidArgumentException('Seleccione un paciente existente.');}
        return ['medico'=>$doctor,'especialidad'=>$specialty,'fecha'=>$date,'hora'=>ScheduleValidator::formatTime($time),'paciente'=>$patient];
    }
    public static function context(array $input): string
    {
        return hash('sha256',json_encode(array_intersect_key($input,array_flip(['medico','especialidad','fecha','hora'])),JSON_THROW_ON_ERROR));
    }
    public static function payload(array $input): string{return hash('sha256',json_encode($input,JSON_THROW_ON_ERROR));}
}
