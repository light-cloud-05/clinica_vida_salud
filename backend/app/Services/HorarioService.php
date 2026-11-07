<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Repositories\ScheduleStore;
use Clinica\Middleware\ScheduleAccess;
final class HorarioService
{
    public function __construct(private ScheduleStore $store,private bool $writesEnabled=false){}
    public function doctors(array $user): array
    {
        if(!ScheduleAccess::view($user) && !ScheduleAccess::schedules($user) && !ScheduleAccess::blocks($user)){throw new \RuntimeException('Acceso no autorizado.');}
        return $this->store->doctors(ScheduleAccess::ownOnly($user) ? (int)$user['id'] : null);
    }
    public function specialties(): array{return $this->store->specialties();}
    public function ids(array $user): array{return array_map(static fn(array $m): int=>(int)$m['id_medico'],$this->doctors($user));}
    public function find(string $kind,int $id,array $user): ?array
    {
        if($id<1){throw new \InvalidArgumentException('Identificador inválido.');}return $this->store->find($kind,$id,$this->ids($user));
    }
    public function catalog(array $user,?int $doctor=null,?int $specialty=null,?int $day=null): array
    {
        $all=$this->doctors($user);$ids=array_map(static fn(array $m): int=>(int)$m['id_medico'],$all);
        if($doctor!==null && !in_array($doctor,$ids,true)){throw new \DomainException('Médico fuera del ámbito autorizado.');}
        $doctors=array_values(array_filter($all,static fn(array $m): bool=>($doctor===null || (int)$m['id_medico']===$doctor) && ($specialty===null || in_array($specialty,$m['especialidades'],true))));
        $ids=array_map(static fn(array $m): int=>(int)$m['id_medico'],$doctors);$hours=$this->store->hours($ids);if($day!==null){$hours=array_values(array_filter($hours,static fn(array $h): bool=>(int)$h['dia_semana']===$day));}
        $blocks=$this->store->blocks($ids);if($day!==null){$blocks=array_values(array_filter($blocks,static fn(array $b): bool=>(int)ScheduleValidator::date($b['fecha'])->format('N')===$day));}
        return ['doctors'=>$doctors,'hours'=>$hours,'blocks'=>$blocks];
    }
    public function week(array $user,?int $doctor,?int $specialty,?int $day,string $date): array
    {
        $anchor=ScheduleValidator::date($date);if(!$anchor){throw new \InvalidArgumentException('Fecha inválida.');}
        $monday=$anchor->modify('-'.((int)$anchor->format('N')-1).' days');$end=$monday->modify('+6 days');
        $catalog=$this->catalog($user,$doctor,$specialty,$day);$ids=array_map(static fn(array $m): int=>(int)$m['id_medico'],$catalog['doctors']);
        $blocks=$this->store->blocks($ids,$monday->format('Y-m-d'),$end->format('Y-m-d'));$appointments=$this->store->appointments($ids,$monday->format('Y-m-d'),$end->format('Y-m-d'));$days=[];$availability=new DisponibilidadService();
        foreach($catalog['doctors'] as $m){for($i=0;$i<7;$i++){$date=$monday->modify('+'.$i.' days');if($day!==null && (int)$date->format('N')!==$day){continue;}$days[(int)$m['id_medico']][]=$availability->day($m,$date->format('Y-m-d'),$catalog['hours'],$blocks,$appointments);}}
        return $catalog+['monday'=>$monday,'end'=>$end,'days'=>$days,'window'=>DisponibilidadService::window(new \DateTimeImmutable('now',new \DateTimeZone('America/La_Paz')))];
    }
    public function validate(string $kind,array $input,?int $id,array $user): array
    {
        $result=ScheduleValidator::validate($kind,$input);if($result['errors']){return $result;}
        $ids=$this->ids($user);if(!in_array((int)$result['data']['id_medico'],$ids,true)){$result['errors']['id_medico']='Seleccione un médico de su ámbito autorizado.';return $result;}
        $result['errors']=ScheduleRules::conflicts($kind,$result['data'],$id,$this->store->hours($ids),$this->store->blocks($ids),$this->store->appointments($ids));return $result;
    }
    public function save(string $kind,array $input,?int $id,array $user): int
    {
        $allowed=$kind==='horario' ? ScheduleAccess::schedules($user) : ScheduleAccess::blocks($user);
        if(!$allowed || ($user['id'] ?? 0)<1){throw new \RuntimeException('Acceso no autorizado.');}if(!$this->writesEnabled){throw new \RuntimeException('Escrituras deshabilitadas.');}
        if($id!==null && $this->find($kind,$id,$user)===null){throw new ScheduleConflict('Registro fuera de su ámbito o inexistente.');}
        $result=$this->validate($kind,$input,$id,$user);if($result['errors']){throw new ScheduleValidation($result['errors']);}
        $version=$input['version'] ?? null;if($id!==null && (!is_string($version) || !preg_match('/^[a-f0-9]{64}$/D',$version))){throw new ScheduleValidation(['_form'=>'Vuelva a abrir el formulario.']);}
        return $this->store->save($kind,$result['data'],$id,$id===null ? null : $version,$user);
    }
}
