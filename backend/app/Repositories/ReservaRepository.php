<?php
declare(strict_types=1);
namespace Clinica\Repositories;
use Clinica\Services\BookingConflict;
use Clinica\Services\CitaService;
use Clinica\Middleware\BookingAccess;
final class ReservaRepository implements BookingStore
{
    public function __construct(private \PDO $pdo){}
    public function create(array $actor,array $input): array
    {
        if(($actor['id'] ?? 0)<1 || !BookingAccess::allowed($actor)){throw new \DomainException('Reserva no autorizada.');}
        $this->pdo->beginTransaction();
        try{
            $this->pdo->exec("SET LOCAL lock_timeout = '3s'");
            // Orden compatible con P4: impedir cambios de calendario mientras se revalida e inserta.
            $this->pdo->exec('LOCK TABLE public.citas IN ROW EXCLUSIVE MODE');
            $this->pdo->exec('LOCK TABLE public.horarios_atencion, public.bloqueos_horario IN SHARE MODE');
            $stmt=$this->pdo->prepare('SELECT id_medico FROM public.medicos WHERE id_medico=:id FOR UPDATE');$stmt->execute(['id'=>$input['medico']]);
            if(!$stmt->fetchColumn()){throw new BookingConflict('El médico ya no está disponible.');}
            if($actor['role']==='Paciente'){
                // Nunca se usa un id_paciente aportado por el navegador para el titular.
                $stmt=$this->pdo->prepare('SELECT id_paciente FROM public.pacientes WHERE id_usuario=:user FOR KEY SHARE');$stmt->execute(['user'=>$actor['id']]);
            }else{
                $stmt=$this->pdo->prepare('SELECT id_paciente FROM public.pacientes WHERE id_paciente=:patient FOR KEY SHARE');$stmt->execute(['patient'=>$input['paciente']]);
            }
            $patient=$stmt->fetchColumn();if($patient===false){throw new BookingConflict('No se encontró el paciente vinculado o seleccionado.');}
            $clock=new \DateTimeImmutable($this->pdo->query("SELECT (clock_timestamp() AT TIME ZONE 'America/La_Paz')::text")->fetchColumn(),new \DateTimeZone('America/La_Paz'));
            $availability=(new CitaService(new CitaRepository($this->pdo)))->availability($actor,$input['medico'],$input['especialidad'],$input['fecha'],$input['hora'],$clock);
            $slot=$availability['selected'];if($slot===null){throw new BookingConflict('La franja ya no está disponible o está fuera de la ventana permitida. Consulte nuevamente.');}
            $audit=$this->pdo->prepare("SELECT set_config('app.usuario_id',:actor,true)");$audit->execute(['actor'=>(string)$actor['id']]);
            $code='CVS-'.strtoupper(bin2hex(random_bytes(12)));
            $stmt=$this->pdo->prepare("INSERT INTO public.citas (id_paciente,id_medico,id_especialidad,codigo_cita,fecha,hora_inicio,hora_fin,estado) VALUES (:patient,:doctor,:specialty,:code,:date,:start,:end,'programada') RETURNING id_cita,codigo_cita");
            $stmt->execute(['patient'=>(int)$patient,'doctor'=>$input['medico'],'specialty'=>$input['especialidad'],'code'=>$code,'date'=>$input['fecha'],'start'=>$slot['hora_inicio'],'end'=>$slot['hora_fin']]);
            $saved=$stmt->fetch();if(!$saved){throw new BookingConflict('No se pudo completar la reserva.');}
            $this->pdo->commit();return ['id'=>(int)$saved['id_cita'],'code'=>$saved['codigo_cita']];
        }catch(\Throwable $e){
            if($this->pdo->inTransaction()){$this->pdo->rollBack();}
            if($e instanceof \PDOException && in_array($e->getCode(),['23P01','23505','23503','23514','P0001','55P03','40P01','40001','57014'],true)){throw new BookingConflict('No se pudo reservar: el turno cambió, está ocupado o incumple una restricción. Vuelva a consultar disponibilidad.');}
            throw $e;
        }
    }
}
