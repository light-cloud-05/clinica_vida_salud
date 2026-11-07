<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Support\Input;
final class CitaFilters
{
    public const STATES=['programada','confirmada','atendida','cancelada','inasistencia'];
    public static function parse(array $query): array
    {
        $filters=['q'=>'','fecha'=>null,'medico'=>null,'especialidad'=>null,'paciente'=>null,'estado'=>null,'pagina'=>1];
        foreach(['medico','especialidad','paciente','pagina'] as $key){$raw=$query[$key] ?? '';if($raw===''){continue;}$value=Input::positiveId($raw);if($value===null){throw new \InvalidArgumentException('Identificador o página inválidos.');}$filters[$key]=$value;}
        $q=$query['q'] ?? '';if(!is_string($q) || !mb_check_encoding($q,'UTF-8') || str_contains($q,"\0") || mb_strlen($q,'UTF-8')>200){throw new \InvalidArgumentException('Búsqueda inválida.');}$filters['q']=trim($q);
        $date=$query['fecha'] ?? '';if($date!==''){if(!ScheduleValidator::date($date)){throw new \InvalidArgumentException('Fecha inválida.');}$filters['fecha']=$date;}
        $state=$query['estado'] ?? '';if($state!==''){if(!is_string($state) || !in_array($state,self::STATES,true)){throw new \InvalidArgumentException('Estado de cita inválido.');}$filters['estado']=$state;}
        return $filters;
    }
}
