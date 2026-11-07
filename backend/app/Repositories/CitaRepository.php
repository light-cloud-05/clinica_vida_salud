<?php
declare(strict_types=1);
namespace Clinica\Repositories;
use Clinica\Middleware\CitaAccess;
final class CitaRepository implements CitaStore
{
    private const JOINS=' FROM public.citas c JOIN public.pacientes p ON p.id_paciente=c.id_paciente JOIN public.medicos m ON m.id_medico=c.id_medico JOIN public.especialidades e ON e.id_especialidad=c.id_especialidad';
    private const COLUMNS="c.id_cita,c.codigo_cita,c.id_paciente,c.id_medico,c.id_especialidad,c.fecha,c.hora_inicio,c.hora_fin,c.estado,p.nombres||' '||p.apellidos AS paciente_nombre,m.nombres||' '||m.apellidos AS medico_nombre,e.nombre AS especialidad_nombre";
    public function __construct(private \PDO $pdo){}
    private function guard(array $actor,bool $availability=false): void
    {
        if(($actor['id'] ?? 0)<1 || ($availability ? !CitaAccess::availability($actor) : !(CitaAccess::listing($actor) || CitaAccess::agenda($actor)))){throw new \DomainException('Consulta no autorizada.');}
    }
    private function where(array $actor,array $filters): array
    {
        $this->guard($actor);$clauses=['TRUE'];$params=[];
        if($actor['role']==='Medico'){$clauses[]='m.id_usuario=:scope_user';$params['scope_user']=$actor['id'];}
        elseif($actor['role']==='Paciente'){$clauses[]='p.id_usuario=:scope_user';$params['scope_user']=$actor['id'];}
        foreach(['fecha'=>'c.fecha','medico'=>'c.id_medico','especialidad'=>'c.id_especialidad','paciente'=>'c.id_paciente','estado'=>'c.estado'] as $key=>$column){if(($filters[$key] ?? null)!==null){$clauses[]=$column.'=:'.$key;$params[$key]=$filters[$key];}}
        if(($filters['q'] ?? '')!==''){
            $like='%'.strtr($filters['q'],['!'=>'!!','%'=>'!%','_'=>'!_']).'%';$search=[];
            foreach(['c.codigo_cita','p.nombres','p.apellidos','m.nombres','m.apellidos','e.nombre'] as $i=>$column){$key='q'.$i;$search[]=$column." ILIKE :$key ESCAPE '!'";$params[$key]=$like;}
            $clauses[]='('.implode(' OR ',$search).')';
        }
        return [' WHERE '.implode(' AND ',$clauses),$params];
    }
    public function count(array $actor,array $filters): int
    {
        [$where,$params]=$this->where($actor,$filters);$stmt=$this->pdo->prepare('SELECT count(*)'.self::JOINS.$where);$stmt->execute($params);return (int)$stmt->fetchColumn();
    }
    public function listing(array $actor,array $filters,int $limit,int $offset): array
    {
        [$where,$params]=$this->where($actor,$filters);$stmt=$this->pdo->prepare('SELECT '.self::COLUMNS.self::JOINS.$where.' ORDER BY c.fecha,c.hora_inicio,c.id_cita LIMIT :limit OFFSET :offset');
        foreach($params as $key=>$value){$stmt->bindValue(':'.$key,$value);}$stmt->bindValue(':limit',$limit,\PDO::PARAM_INT);$stmt->bindValue(':offset',$offset,\PDO::PARAM_INT);$stmt->execute();return $stmt->fetchAll();
    }
    public function find(array $actor,int $id): ?array
    {
        if($id<1){throw new \InvalidArgumentException('Identificador inválido.');}[$where,$params]=$this->where($actor,[]);$params['id']=$id;
        $stmt=$this->pdo->prepare('SELECT '.self::COLUMNS.self::JOINS.$where.' AND c.id_cita=:id');$stmt->execute($params);return $stmt->fetch() ?: null;
    }
    public function choices(array $actor): array
    {
        $this->guard($actor);$params=[];$doctorWhere='';$patientWhere='';
        if($actor['role']==='Medico'){$doctorWhere=' WHERE m.id_usuario=:user';$patientWhere=' WHERE EXISTS (SELECT 1 FROM public.citas c JOIN public.medicos m ON m.id_medico=c.id_medico WHERE c.id_paciente=p.id_paciente AND m.id_usuario=:user)';$params=['user'=>$actor['id']];}
        elseif($actor['role']==='Paciente'){$doctorWhere=' WHERE EXISTS (SELECT 1 FROM public.citas c JOIN public.pacientes p ON p.id_paciente=c.id_paciente WHERE c.id_medico=m.id_medico AND p.id_usuario=:user)';$patientWhere=' WHERE p.id_usuario=:user';$params=['user'=>$actor['id']];}
        $stmt=$this->pdo->prepare("SELECT m.id_medico,m.nombres||' '||m.apellidos AS nombre FROM public.medicos m".$doctorWhere.' ORDER BY nombre,m.id_medico');$stmt->execute($params);$doctors=$stmt->fetchAll();
        $stmt=$this->pdo->prepare("SELECT p.id_paciente,p.nombres||' '||p.apellidos AS nombre FROM public.pacientes p".$patientWhere.' ORDER BY nombre,p.id_paciente');$stmt->execute($params);$patients=$stmt->fetchAll();
        return ['doctors'=>$doctors,'patients'=>$patients,'specialties'=>$this->pdo->query('SELECT id_especialidad,nombre FROM public.especialidades ORDER BY nombre,id_especialidad')->fetchAll()];
    }
    public function availabilityChoices(array $actor): array
    {
        $this->guard($actor,true);$repository=new HorarioRepository($this->pdo);$doctors=[];
        foreach($repository->doctors($actor['role']==='Medico' ? (int)$actor['id'] : null) as $row){$doctors[]=['id_medico'=>(int)$row['id_medico'],'nombre'=>$row['nombres'].' '.$row['apellidos'],'estado'=>$row['estado'],'especialidades'=>$row['especialidades']];}
        return ['doctors'=>$doctors,'specialties'=>$repository->specialties()];
    }
    public function availabilityData(array $actor,int $doctor,string $date): array
    {
        $this->guard($actor,true);$allowed=$this->availabilityChoices($actor);
        if(!in_array($doctor,array_column($allowed['doctors'],'id_medico'),true)){throw new \DomainException('Médico fuera del ámbito autorizado.');}
        $repo=new HorarioRepository($this->pdo);
        return ['hours'=>$repo->hours([$doctor]),'blocks'=>array_map(static fn(array $b): array=>array_intersect_key($b,array_flip(['id_medico','fecha','hora_inicio','hora_fin'])),$repo->blocks([$doctor],$date,$date)),'appointments'=>$repo->appointments([$doctor],$date,$date)];
    }
}
