<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Repositories\MedicalStore;
use Clinica\Middleware\MedicalAccess;

final class MedicoService
{
    public function __construct(private MedicalStore $store,private bool $writesEnabled=false){}
    public function specialties(): array{return $this->store->specialties();}
    public function page(string $search,int $page,?int $specialty): array
    {
        $total=$this->store->countDoctors($search,$specialty);$pages=max(1,(int)ceil($total/10));$page=max(1,min($page,$pages));
        return ['items'=>$this->store->doctors($search,$specialty,10,($page-1)*10),'total'=>$total,'page'=>$page,'pages'=>$pages];
    }
    public function find(string $kind,int $id): ?array
    {
        MedicalValidator::fields($kind);if($id<1){throw new \InvalidArgumentException('Identificador inválido.');}return $this->store->find($kind,$id);
    }
    public function validate(string $kind,array $input,?int $id): array
    {
        $result=MedicalValidator::validate($kind,$input);$errors=$result['errors'];
        if(!$errors){
            $errors=$this->store->duplicates($kind,$result['data'],$id);
            if($kind==='medico'){
                $existing=array_map(static fn(array $e): int=>(int)$e['id_especialidad'],$this->store->specialties());
                if(array_diff($result['ids'],$existing)){$errors['especialidades']='Una especialidad seleccionada no existe.';}
                if($id!==null){$doctor=$this->find('medico',$id);foreach($doctor['especialidades'] ?? [] as $specialty){if((int)$specialty['tiene_citas']===1 && !in_array((int)$specialty['id_especialidad'],$result['ids'],true)){$errors['especialidades']='Conserve las especialidades que están vinculadas a citas.';}}}
            }
        }
        $result['errors']=$errors;return $result;
    }
    public function save(string $kind,array $input,?int $id,array $actor): int
    {
        if(!MedicalAccess::allowed($actor) || ($actor['id'] ?? 0)<1){throw new \RuntimeException('Acceso no autorizado.');}
        if(!$this->writesEnabled){throw new \RuntimeException('El guardado está deshabilitado.');}
        if($id!==null && $id<1){throw new \InvalidArgumentException('Identificador inválido.');}
        $result=$this->validate($kind,$input,$id);if($result['errors']){throw new MedicalValidation($result['errors']);}
        $version=$input['version'] ?? null;$associationVersion=$input['asociaciones_version'] ?? null;
        if($id!==null && (!is_string($version) || $version==='' || strlen($version)>100)){throw new MedicalValidation(['_form'=>'Vuelva a abrir el formulario.']);}
        if($id!==null && $kind==='medico' && (!is_string($associationVersion) || !preg_match('/^[a-f0-9]{64}$/D',$associationVersion))){throw new MedicalValidation(['_form'=>'Vuelva a abrir el formulario de asociaciones.']);}
        return $this->store->save($kind,$result['data'],$result['ids'],$id,$id===null ? null : $version,$id===null || $kind!=='medico' ? null : $associationVersion,(int)$actor['id']);
    }
}
