<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Repositories\CitaStore;
use Clinica\Middleware\CitaAccess;
final class CitaService
{
    public function __construct(private CitaStore $store){}
    private function guard(array $actor,bool $agenda=false): void
    {
        if(($actor['id'] ?? 0)<1 || ($agenda ? !CitaAccess::agenda($actor) : !CitaAccess::listing($actor))){throw new \DomainException('Consulta no autorizada.');}
    }
    public function choices(array $actor,bool $agenda=false): array{$this->guard($actor,$agenda);return $this->store->choices($actor);}
    public function page(array $actor,array $query,bool $agenda=false): array
    {
        $this->guard($actor,$agenda);$filters=CitaFilters::parse($query);$choices=$this->store->choices($actor);
        foreach(['medico'=>['doctors','id_medico'],'paciente'=>['patients','id_paciente'],'especialidad'=>['specialties','id_especialidad']] as $field=>[$collection,$key]){
            if($filters[$field]!==null && !in_array($filters[$field],array_map('intval',array_column($choices[$collection],$key)),true)){throw new \DomainException('El filtro solicitado está fuera de su ámbito autorizado o no existe.');}
        }
        $total=$this->store->count($actor,$filters);$pages=max(1,(int)ceil($total/10));$page=min($filters['pagina'],$pages);
        return ['items'=>$this->store->listing($actor,$filters,10,($page-1)*10),'total'=>$total,'pages'=>$pages,'page'=>$page,'filters'=>$filters,'choices'=>$choices];
    }
    public function find(array $actor,int $id): ?array{$this->guard($actor);if($id<1){throw new \InvalidArgumentException('Identificador de cita inválido.');}return $this->store->find($actor,$id);}
    public function availabilityChoices(array $actor): array
    {
        if(($actor['id'] ?? 0)<1 || !CitaAccess::availability($actor)){throw new \DomainException('Disponibilidad no autorizada.');}return $this->store->availabilityChoices($actor);
    }
    public function availability(array $actor,int $doctor,int $specialty,string $date,?string $time=null,?\DateTimeImmutable $now=null): array
    {
        $choices=$this->availabilityChoices($actor);$match=null;
        foreach($choices['doctors'] as $row){if((int)$row['id_medico']===$doctor){$match=$row;break;}}
        if(!$match){throw new \DomainException('Médico fuera del ámbito autorizado o inexistente.');}
        if(!in_array($specialty,$match['especialidades'],true)){throw new \InvalidArgumentException('El médico no está asociado con esa especialidad.');}
        $clock=$now ?? new \DateTimeImmutable('now',new \DateTimeZone('America/La_Paz'));
        $window=DisponibilidadService::window($clock);
        $data=in_array($date,$window,true) ? $this->store->availabilityData($actor,$doctor,$date) : ['hours'=>[],'blocks'=>[],'appointments'=>[]];
        return (new CitaAvailability())->evaluate($match,$date,$data['hours'],$data['blocks'],$data['appointments'],$time,$clock);
    }
}
