<?php
declare(strict_types=1);
namespace Clinica\Repositories;
use Clinica\Middleware\BookingAccess;
use Clinica\Services\{BookingConflict,CitaChangePolicy,CitaAvailability};
final class CitaChangeRepository implements CitaChangeStore
{
    public function __construct(private \PDO $pdo){}
    public function find(array $actor,int $id,bool $lock=false):?array
    {
        if($id<1 || ($actor['id'] ?? 0)<1 || !BookingAccess::allowed($actor)){throw new \DomainException('Consulta no autorizada.');}
        $sql='SELECT c.id_cita,c.codigo_cita,c.id_paciente,c.id_medico,c.id_especialidad,c.fecha,c.hora_inicio,c.hora_fin,c.estado,c.cantidad_reprogramaciones,c.updated_at FROM public.citas c JOIN public.pacientes p ON p.id_paciente=c.id_paciente WHERE c.id_cita=:id';$params=['id'=>$id];
        if($actor['role']==='Paciente'){$sql.=' AND p.id_usuario=:user';$params['user']=$actor['id'];}
        if($lock){$sql.=' FOR UPDATE OF c';}
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt->fetch() ?: null;
    }
    public function availability(array $actor,array $c,string $date,?string $time=null,?\DateTimeImmutable $now=null):array
    {
        $choices=(new CitaRepository($this->pdo))->availabilityChoices($actor);$doctor=null;
        foreach($choices['doctors'] as $m){if((int)$m['id_medico']===(int)$c['id_medico']){$doctor=$m;break;}}
        if(!$doctor || !in_array((int)$c['id_especialidad'],$doctor['especialidades'],true)){throw new BookingConflict('El médico o su especialidad ya no están disponibles.');}
        $repo=new HorarioRepository($this->pdo);
        // Excluir únicamente la cita editada: permite moverla sin considerarla un conflicto consigo misma.
        $stmt=$this->pdo->prepare('SELECT id_medico,fecha,hora_inicio,hora_fin,estado FROM public.citas WHERE id_medico=:doctor AND fecha=:date AND id_cita<>:id');
        $stmt->execute(['doctor'=>$c['id_medico'],'date'=>$date,'id'=>$c['id_cita']]);
        return (new CitaAvailability())->evaluate($doctor,$date,$repo->hours([(int)$c['id_medico']]),$repo->blocks([(int)$c['id_medico']],$date,$date),$stmt->fetchAll(),$time,$now);
    }
    public function change(array $actor,string $action,array $input):int
    {
        if(!in_array($action,['reprogramar','cancelar'],true)){throw new \InvalidArgumentException('Operación inválida.');}
        $this->pdo->beginTransaction();
        try{
            $this->pdo->exec("SET LOCAL lock_timeout = '3s'");
            $this->pdo->exec('LOCK TABLE public.citas IN ROW EXCLUSIVE MODE');
            $this->pdo->exec('LOCK TABLE public.horarios_atencion, public.bloqueos_horario IN SHARE MODE');
            $before=$this->find($actor,$input['id']);if(!$before){throw new BookingConflict('La cita no está disponible dentro de su ámbito.');}
            $stmt=$this->pdo->prepare('SELECT id_medico FROM public.medicos WHERE id_medico=:id FOR UPDATE');$stmt->execute(['id'=>$before['id_medico']]);
            if(!$stmt->fetchColumn()){throw new BookingConflict('Médico no disponible.');}
            $c=$this->find($actor,$input['id'],true);
            if(!$c || !hash_equals(CitaChangePolicy::version($c),$input['version'])){throw new BookingConflict('La cita cambió. Abra nuevamente el formulario.');}
            $now=new \DateTimeImmutable($this->pdo->query("SELECT (clock_timestamp() AT TIME ZONE 'America/La_Paz')::text")->fetchColumn(),new \DateTimeZone('America/La_Paz'));
            CitaChangePolicy::check($c,$action,$now);
            if($action==='reprogramar'){
                if($input['fecha']===$c['fecha'] && $input['hora']===$c['hora_inicio']){throw new \InvalidArgumentException('Seleccione una fecha u hora diferente.');}
                $slot=$this->availability($actor,$c,$input['fecha'],$input['hora'],$now)['selected'];
                if(!$slot){throw new BookingConflict('El nuevo turno no está disponible o está fuera de la ventana de reserva.');}
            }
            $stmt=$this->pdo->prepare("SELECT set_config('app.usuario_id',:actor,true)");$stmt->execute(['actor'=>(string)$actor['id']]);
            if($action==='cancelar'){
                // Firma comprobada en PostgreSQL: también cancela la reserva provisional pendiente; no toca pagos.
                $stmt=$this->pdo->prepare('CALL public.cancelar_cita(CAST(:id AS bigint),CAST(:reason AS text))');$stmt->execute(['id'=>$c['id_cita'],'reason'=>$input['motivo']]);
            }else{
                // El trigger controla contador, historial y auditoría: no escribirlos manualmente.
                $stmt=$this->pdo->prepare('UPDATE public.citas SET fecha=:date,hora_inicio=:start,hora_fin=:end WHERE id_cita=:id');
                $stmt->execute(['date'=>$input['fecha'],'start'=>$slot['hora_inicio'],'end'=>$slot['hora_fin'],'id'=>$c['id_cita']]);
            }
            $this->pdo->commit();return (int)$c['id_cita'];
        }catch(\Throwable $e){
            if($this->pdo->inTransaction()){$this->pdo->rollBack();}
            if($e instanceof \PDOException && in_array($e->getCode(),['23P01','23505','23503','23514','P0001','55P03','40P01','40001','57014'],true)){throw new BookingConflict('El turno cambió o la operación incumple una restricción. Consulte la cita nuevamente.');}
            throw $e;
        }
    }
}
