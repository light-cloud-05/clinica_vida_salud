<?php
declare(strict_types=1);
namespace Clinica\Repositories;

use Clinica\Services\PatientValidator;
use Clinica\Services\PatientConflict;

final class PacienteRepository implements PatientStore
{
    public function __construct(private \PDO $pdo) {}

    private function search(string $search): array
    {
        if($search==='') {return ['',[]];}
        $pattern='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
        return [" WHERE (p.nombres ILIKE :nombres ESCAPE '!' OR p.apellidos ILIKE :apellidos ESCAPE '!' OR p.documento_identidad ILIKE :documento ESCAPE '!')", ['nombres'=>$pattern,'apellidos'=>$pattern,'documento'=>$pattern]];
    }

    public function count(string $search): int
    {
        [$where,$parameters]=$this->search($search);
        $stmt=$this->pdo->prepare('SELECT count(*) FROM public.pacientes p'.$where);
        $stmt->execute($parameters);
        return (int)$stmt->fetchColumn();
    }

    public function listing(string $search, int $limit, int $offset): array
    {
        [$where,$parameters]=$this->search($search);
        $stmt=$this->pdo->prepare('SELECT p.id_paciente,p.codigo_paciente,p.documento_identidad,p.nombres,p.apellidos,p.estado FROM public.pacientes p'.$where.' ORDER BY p.apellidos,p.nombres,p.id_paciente LIMIT :limit OFFSET :offset');
        foreach($parameters as $key=>$value) { $stmt->bindValue(':'.$key,$value); }
        $stmt->bindValue(':limit',$limit,\PDO::PARAM_INT);
        $stmt->bindValue(':offset',$offset,\PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $fields=implode(',',array_map(static fn(string $field): string=>'p.'.$field,array_keys(PatientValidator::FIELDS)));
        $stmt=$this->pdo->prepare('SELECT p.id_paciente,p.id_usuario,p.updated_at,'.$fields.',u.nombre_usuario AS cuenta_usuario FROM public.pacientes p LEFT JOIN public.usuarios u ON u.id_usuario=p.id_usuario WHERE p.id_paciente=:id');
        $stmt->execute(['id'=>$id]);
        return $stmt->fetch() ?: null;
    }

    public function duplicates(array $data, ?int $exclude): array
    {
        $stmt=$this->pdo->prepare('SELECT codigo_paciente,documento_identidad FROM public.pacientes WHERE (codigo_paciente=:codigo OR documento_identidad=:documento) AND id_paciente<>:exclude');
        $stmt->execute(['codigo'=>$data['codigo_paciente'],'documento'=>$data['documento_identidad'],'exclude'=>$exclude ?? 0]);
        $errors=[];
        foreach($stmt->fetchAll() as $row) {
            foreach(['codigo_paciente'=>'código','documento_identidad'=>'documento de identidad'] as $field=>$label) {
                if($row[$field]===$data[$field]) { $errors[$field]="Ya existe un paciente con ese $label."; }
            }
        }
        return $errors;
    }

    public function save(array $data, ?int $id, ?string $version, int $actor): int
    {
        // Solo recibe las columnas administrativas de la lista cerrada del validador.
        $data=array_intersect_key($data,PatientValidator::FIELDS);
        $this->pdo->beginTransaction();
        try {
            $audit=$this->pdo->prepare("SELECT set_config('app.usuario_id',:actor,true)");
            $audit->execute(['actor'=>(string)$actor]);
            if($id===null) {
                $fields=array_keys($data);
                $stmt=$this->pdo->prepare('INSERT INTO public.pacientes ('.implode(',',$fields).') VALUES (:'.implode(',:',$fields).') RETURNING id_paciente');
            } else {
                $assignments=implode(',',array_map(static fn(string $field): string=>$field.'=:'.$field,array_keys($data)));
                $stmt=$this->pdo->prepare('UPDATE public.pacientes SET '.$assignments.' WHERE id_paciente=:id AND updated_at::text=:version RETURNING id_paciente');
                $data['id']=$id; $data['version']=$version;
            }
            $stmt->execute($data);
            $saved=$stmt->fetchColumn();
            if($saved===false) {throw new PatientConflict('El registro cambió desde que abrió el formulario. Vuelva a cargarlo antes de guardar.');}
            $this->pdo->commit();
            return (int)$saved;
        } catch(\Throwable $e) {
            if($this->pdo->inTransaction()) {$this->pdo->rollBack();}
            if($e instanceof \PDOException && $e->getCode()==='23505') { throw new PatientConflict('El código o documento de identidad ya está registrado. Revise los datos.'); }
            throw $e;
        }
    }
}
