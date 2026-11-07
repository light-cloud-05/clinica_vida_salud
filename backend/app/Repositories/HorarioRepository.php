<?php
declare(strict_types=1);
namespace Clinica\Repositories;
use Clinica\Services\ScheduleValidator as V;
use Clinica\Services\ScheduleRules;
use Clinica\Services\ScheduleConflict;
use Clinica\Services\ScheduleValidation;
use Clinica\Middleware\ScheduleAccess;
final class HorarioRepository implements ScheduleStore
{
    public function __construct(private \PDO $pdo){}
    private function scope(array $ids): array
    {
        $params=[];$keys=[];foreach(array_values($ids) as $i=>$id){$keys[]=':d'.$i;$params['d'.$i]=(int)$id;}
        return [$keys ? implode(',',$keys) : 'NULL',$params];
    }
    public function doctors(?int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT m.id_medico,m.codigo_medico,m.nombres,m.apellidos,m.estado,COALESCE((SELECT json_agg(me.id_especialidad ORDER BY me.id_especialidad)::text FROM public.medico_especialidad me WHERE me.id_medico=m.id_medico),\'[]\') AS especialidades FROM public.medicos m'.($userId===null ? '' : ' WHERE m.id_usuario=:user').' ORDER BY m.apellidos,m.nombres,m.id_medico');$stmt->execute($userId===null ? [] : ['user'=>$userId]);$rows=$stmt->fetchAll();foreach($rows as &$row){$row['especialidades']=array_map('intval',json_decode($row['especialidades'],true,512,JSON_THROW_ON_ERROR));}unset($row);return $rows;
    }
    public function specialties(): array{return $this->pdo->query('SELECT id_especialidad,nombre FROM public.especialidades ORDER BY nombre,id_especialidad')->fetchAll();}
    public function hours(array $doctors): array
    {
        [$in,$params]=$this->scope($doctors);$stmt=$this->pdo->prepare('SELECT id_horario,id_medico,dia_semana,hora_inicio,hora_fin,duracion_cita_minutos,estado,updated_at FROM public.horarios_atencion WHERE id_medico IN ('.$in.') ORDER BY id_medico,dia_semana,hora_inicio,id_horario');$stmt->execute($params);return $stmt->fetchAll();
    }
    private function dated(string $kind,array $doctors,?string $from,?string $to): array
    {
        [$in,$params]=$this->scope($doctors);
        $sql=$kind==='bloqueo' ? 'SELECT id_bloqueo,id_medico,fecha,hora_inicio,hora_fin,motivo FROM public.bloqueos_horario' : "SELECT id_medico,fecha,hora_inicio,hora_fin,estado FROM public.citas";
        $sql.=' WHERE id_medico IN ('.$in.')';
        if($from!==null){$sql.=' AND fecha>=:from';$params['from']=$from;}if($to!==null){$sql.=' AND fecha<=:to';$params['to']=$to;}
        $stmt=$this->pdo->prepare($sql.' ORDER BY fecha,hora_inicio,id_medico');$stmt->execute($params);return $stmt->fetchAll();
    }
    public function blocks(array $doctors,?string $from=null,?string $to=null): array{return $this->dated('bloqueo',$doctors,$from,$to);}
    public function appointments(array $doctors,?string $from=null,?string $to=null): array{return $this->dated('cita',$doctors,$from,$to);}
    public function find(string $kind,int $id,array $doctors): ?array
    {
        V::fields($kind);[$in,$params]=$this->scope($doctors);$params['id']=$id;$table=$kind==='horario' ? 'horarios_atencion' : 'bloqueos_horario';$pk=$kind==='horario' ? 'id_horario' : 'id_bloqueo';
        $fields=implode(',',V::fields($kind));if($kind==='horario'){$fields.=',updated_at';}
        $stmt=$this->pdo->prepare("SELECT $pk,$fields FROM public.$table WHERE $pk=:id AND id_medico IN ($in)");$stmt->execute($params);return $stmt->fetch() ?: null;
    }
    public function save(string $kind,array $data,?int $id,?string $version,array $actor): int
    {
        $data=array_intersect_key($data,array_flip(V::fields($kind)));$table=$kind==='horario' ? 'horarios_atencion' : 'bloqueos_horario';$pk=$kind==='horario' ? 'id_horario' : 'id_bloqueo';
        $this->pdo->beginTransaction();
        try{
            // Sin restricciones de solapamiento en BD: serializar P4 y bloquear DML concurrente de citas durante la validación.
            $this->pdo->exec('LOCK TABLE public.citas IN SHARE MODE');
            $this->pdo->exec('LOCK TABLE public.horarios_atencion, public.bloqueos_horario IN SHARE ROW EXCLUSIVE MODE');
            $doctors=$this->doctors(ScheduleAccess::ownOnly($actor) ? (int)$actor['id'] : null);$ids=array_map(static fn(array $m): int=>(int)$m['id_medico'],$doctors);
            if(!in_array((int)$data['id_medico'],$ids,true)){throw new ScheduleConflict('El médico no está dentro de su ámbito autorizado.');}
            if($id!==null){$old=$this->find($kind,$id,$ids);if(!$old || !is_string($version) || !hash_equals(V::version($kind,$old),$version)){throw new ScheduleConflict('El registro cambió. Vuelva a abrir el formulario.');}}
            $errors=ScheduleRules::conflicts($kind,$data,$id,$this->hours($ids),$this->blocks($ids),$this->appointments($ids));if($errors){throw new ScheduleValidation($errors);}
            $stmt=$this->pdo->prepare("SELECT set_config('app.usuario_id',:actor,true)");$stmt->execute(['actor'=>(string)$actor['id']]);
            $fields=array_keys($data);
            if($id===null){$stmt=$this->pdo->prepare("INSERT INTO public.$table (".implode(',',$fields).') VALUES (:'.implode(',:',$fields).") RETURNING $pk");}
            else{$assign=implode(',',array_map(static fn(string $f): string=>$f.'=:'.$f,$fields));$stmt=$this->pdo->prepare("UPDATE public.$table SET $assign WHERE $pk=:id RETURNING $pk");$data['id']=$id;}
            $stmt->execute($data);$saved=$stmt->fetchColumn();if($saved===false){throw new ScheduleConflict('Registro no disponible.');}$this->pdo->commit();return (int)$saved;
        }catch(\Throwable $e){if($this->pdo->inTransaction()){$this->pdo->rollBack();}if($e instanceof \PDOException && in_array($e->getCode(),['23503','23514','23P01','40001','40P01'],true)){throw new ScheduleConflict('Los datos cambiaron o incumplen una restricción. Recargue y vuelva a revisar.');}throw $e;}
    }
}
