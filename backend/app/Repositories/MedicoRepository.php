<?php
declare(strict_types=1);
namespace Clinica\Repositories;
use Clinica\Services\MedicalValidator;
use Clinica\Services\MedicalConflict;

final class MedicoRepository implements MedicalStore
{
    public function __construct(private \PDO $pdo){}
    private function filter(string $search,?int $specialty): array
    {
        $parts=[];$params=[];
        if($search!==''){
            $pattern='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $parts[]="(m.nombres ILIKE :n ESCAPE '!' OR m.apellidos ILIKE :a ESCAPE '!' OR m.codigo_medico ILIKE :c ESCAPE '!' OR m.registro_profesional ILIKE :r ESCAPE '!')";
            $params=['n'=>$pattern,'a'=>$pattern,'c'=>$pattern,'r'=>$pattern];
        }
        if($specialty!==null){$parts[]='EXISTS (SELECT 1 FROM public.medico_especialidad f WHERE f.id_medico=m.id_medico AND f.id_especialidad=:specialty)';$params['specialty']=$specialty;}
        return [$parts ? ' WHERE '.implode(' AND ',$parts) : '',$params];
    }
    public function countDoctors(string $search,?int $specialty): int
    {
        [$where,$params]=$this->filter($search,$specialty);$stmt=$this->pdo->prepare('SELECT count(*) FROM public.medicos m'.$where);$stmt->execute($params);return (int)$stmt->fetchColumn();
    }
    public function doctors(string $search,?int $specialty,int $limit,int $offset): array
    {
        [$where,$params]=$this->filter($search,$specialty);
        $stmt=$this->pdo->prepare("SELECT m.id_medico,m.codigo_medico,m.registro_profesional,m.nombres,m.apellidos,m.estado,COALESCE((SELECT string_agg(e.nombre, ', ' ORDER BY e.nombre) FROM public.medico_especialidad me JOIN public.especialidades e ON e.id_especialidad=me.id_especialidad WHERE me.id_medico=m.id_medico),'') AS especialidades FROM public.medicos m".$where.' ORDER BY m.apellidos,m.nombres,m.id_medico LIMIT :limit OFFSET :offset');
        foreach($params as $key=>$value){$stmt->bindValue(':'.$key,$value);}
        $stmt->bindValue(':limit',$limit,\PDO::PARAM_INT);$stmt->bindValue(':offset',$offset,\PDO::PARAM_INT);$stmt->execute();return $stmt->fetchAll();
    }
    public function specialties(): array
    {
        return $this->pdo->query('SELECT e.id_especialidad,e.nombre,e.descripcion,e.precio_consulta,e.estado,e.updated_at,(SELECT count(*) FROM public.medico_especialidad me WHERE me.id_especialidad=e.id_especialidad) AS medicos FROM public.especialidades e ORDER BY e.nombre,e.id_especialidad')->fetchAll();
    }
    public function find(string $kind,int $id): ?array
    {
        if($kind==='especialidad'){
            $stmt=$this->pdo->prepare('SELECT id_especialidad,nombre,descripcion,precio_consulta,estado,updated_at FROM public.especialidades WHERE id_especialidad=:id');$stmt->execute(['id'=>$id]);return $stmt->fetch() ?: null;
        }
        MedicalValidator::fields($kind);
        $stmt=$this->pdo->prepare('SELECT m.id_medico,m.id_usuario,m.codigo_medico,m.registro_profesional,m.nombres,m.apellidos,m.telefono,m.correo_contacto,m.estado,m.updated_at,u.nombre_usuario AS cuenta_usuario FROM public.medicos m LEFT JOIN public.usuarios u ON u.id_usuario=m.id_usuario WHERE m.id_medico=:id');$stmt->execute(['id'=>$id]);$row=$stmt->fetch();
        if(!$row){return null;}
        $stmt=$this->pdo->prepare('SELECT e.id_especialidad,e.nombre,e.estado,e.precio_consulta,CASE WHEN EXISTS (SELECT 1 FROM public.citas c WHERE c.id_medico=me.id_medico AND c.id_especialidad=me.id_especialidad) THEN 1 ELSE 0 END AS tiene_citas FROM public.medico_especialidad me JOIN public.especialidades e ON e.id_especialidad=me.id_especialidad WHERE me.id_medico=:id ORDER BY e.id_especialidad');$stmt->execute(['id'=>$id]);$row['especialidades']=$stmt->fetchAll();return $row;
    }
    public function duplicates(string $kind,array $data,?int $exclude): array
    {
        if($kind==='especialidad'){
            $stmt=$this->pdo->prepare('SELECT 1 FROM public.especialidades WHERE lower(btrim(nombre))=lower(btrim(:nombre)) AND id_especialidad<>:exclude');$stmt->execute(['nombre'=>$data['nombre'],'exclude'=>$exclude ?? 0]);return $stmt->fetchColumn() ? ['nombre'=>'Ya existe una especialidad con ese nombre normalizado.'] : [];
        }
        MedicalValidator::fields($kind);
        $stmt=$this->pdo->prepare('SELECT codigo_medico,registro_profesional FROM public.medicos WHERE (codigo_medico=:codigo OR registro_profesional=:registro) AND id_medico<>:exclude');$stmt->execute(['codigo'=>$data['codigo_medico'],'registro'=>$data['registro_profesional'],'exclude'=>$exclude ?? 0]);$errors=[];
        foreach($stmt->fetchAll() as $row){foreach(['codigo_medico'=>'código','registro_profesional'=>'registro profesional'] as $field=>$label){if($row[$field]===$data[$field]){$errors[$field]="Ya existe un médico con ese $label.";}}}return $errors;
    }
    public function save(string $kind,array $data,array $specialties,?int $id,?string $version,?string $associationVersion,int $actor): int
    {
        $data=array_intersect_key($data,MedicalValidator::fields($kind));
        $table=$kind==='medico' ? 'medicos' : 'especialidades';$pk=$kind==='medico' ? 'id_medico' : 'id_especialidad';
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare("SELECT set_config('app.usuario_id',:actor,true)");$stmt->execute(['actor'=>(string)$actor]);
            if($id!==null){
                $stmt=$this->pdo->prepare("SELECT updated_at::text FROM public.$table WHERE $pk=:id FOR UPDATE");$stmt->execute(['id'=>$id]);$current=$stmt->fetchColumn();
                if($current===false || $current!==$version){throw new MedicalConflict('El registro cambió. Recargue el formulario antes de guardar.');}
            }
            $old=[];
            if($kind==='medico'){
                if($id!==null){
                    $stmt=$this->pdo->prepare('SELECT id_especialidad FROM public.medico_especialidad WHERE id_medico=:id ORDER BY id_especialidad FOR UPDATE');$stmt->execute(['id'=>$id]);$old=array_map('intval',$stmt->fetchAll(\PDO::FETCH_COLUMN));
                    if(!is_string($associationVersion) || !hash_equals(MedicalValidator::associationVersion($old),$associationVersion)){throw new MedicalConflict('Las asociaciones cambiaron. Recargue el formulario.');}
                }
                $specialties=array_values(array_unique($specialties));sort($specialties,SORT_NUMERIC);
                if($specialties){
                    $params=[];$placeholders=[];foreach($specialties as $i=>$specialty){$placeholders[]=':s'.$i;$params['s'.$i]=$specialty;}
                    $stmt=$this->pdo->prepare('SELECT id_especialidad FROM public.especialidades WHERE id_especialidad IN ('.implode(',',$placeholders).') ORDER BY id_especialidad FOR KEY SHARE');$stmt->execute($params);
                    if(count($stmt->fetchAll(\PDO::FETCH_COLUMN))!==count($specialties)){throw new MedicalConflict('Una especialidad seleccionada ya no existe.');}
                }
                foreach(array_diff($old,$specialties) as $removed){
                    $stmt=$this->pdo->prepare('SELECT 1 FROM public.citas WHERE id_medico=:doctor AND id_especialidad=:specialty LIMIT 1');$stmt->execute(['doctor'=>$id,'specialty'=>$removed]);
                    if($stmt->fetchColumn()){throw new MedicalConflict('No puede retirar una especialidad vinculada a citas. Conserve la asociación.');}
                }
            }
            $fields=array_keys($data);
            if($id===null){$stmt=$this->pdo->prepare("INSERT INTO public.$table (".implode(',',$fields).') VALUES (:'.implode(',:',$fields).") RETURNING $pk");}
            else{$assign=implode(',',array_map(static fn(string $field): string=>$field.'=:'.$field,$fields));$stmt=$this->pdo->prepare("UPDATE public.$table SET $assign WHERE $pk=:id RETURNING $pk");$data['id']=$id;}
            $stmt->execute($data);$saved=$stmt->fetchColumn();if($saved===false){throw new MedicalConflict('El registro ya no está disponible.');}$saved=(int)$saved;
            if($kind==='medico'){
                foreach(array_diff($old,$specialties) as $removed){$stmt=$this->pdo->prepare('DELETE FROM public.medico_especialidad WHERE id_medico=:doctor AND id_especialidad=:specialty');$stmt->execute(['doctor'=>$saved,'specialty'=>$removed]);}
                foreach(array_diff($specialties,$old) as $added){$stmt=$this->pdo->prepare('INSERT INTO public.medico_especialidad (id_medico,id_especialidad) VALUES (:doctor,:specialty)');$stmt->execute(['doctor'=>$saved,'specialty'=>$added]);}
            }
            $this->pdo->commit();return $saved;
        }catch(\Throwable $e){
            if($this->pdo->inTransaction()){$this->pdo->rollBack();}
            if($e instanceof \PDOException && $e->getCode()==='23505'){throw new MedicalConflict('Existe un código, registro profesional o nombre de especialidad duplicado.');}
            if($e instanceof \PDOException && $e->getCode()==='23503'){throw new MedicalConflict('La asociación tiene referencias o uno de sus registros ya no existe. No se guardaron cambios.');}
            throw $e;
        }
    }
}
