<?php
declare(strict_types=1);
namespace Clinica\Repositories;

use Clinica\Middleware\AuthorizationMiddleware;

final class NotificationRepository implements NotificationStore
{
    private const APPOINTMENT = "SELECT n.id_notificacion,n.id_cita,n.tipo,n.destinatario,n.asunto,n.fecha_programada,n.fecha_envio,n.estado_envio,
        c.codigo_cita,c.fecha,c.hora_inicio,c.hora_fin,c.estado,
        m.nombres||' '||m.apellidos AS medico_nombre,e.nombre AS especialidad_nombre,
        p.id_paciente,p.id_usuario,p.nombres||' '||p.apellidos AS paciente_nombre
        FROM public.notificaciones n
        JOIN public.citas c ON c.id_cita=n.id_cita
        JOIN public.pacientes p ON p.id_paciente=c.id_paciente
        JOIN public.medicos m ON m.id_medico=c.id_medico
        JOIN public.especialidades e ON e.id_especialidad=c.id_especialidad";

    public function __construct(private \PDO $pdo) {}

    public function appointment(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id_cita,c.codigo_cita,c.fecha,c.hora_inicio,c.hora_fin,c.estado,
                    p.id_paciente,p.id_usuario,p.correo_contacto,
                    m.nombres||' '||m.apellidos AS medico_nombre,e.nombre AS especialidad_nombre
             FROM public.citas c
             JOIN public.pacientes p ON p.id_paciente=c.id_paciente
             JOIN public.medicos m ON m.id_medico=c.id_medico
             JOIN public.especialidades e ON e.id_especialidad=c.id_especialidad
             WHERE c.id_cita=:id"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function enqueue(int $appointmentId, string $type, string $recipient, string $subject, ?string $scheduledAt = null): ?int
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:key))');
            $lock->execute(['key' => $appointmentId.':'.$type]);
            $stmt = $this->pdo->prepare(
                "INSERT INTO public.notificaciones
                    (id_notificacion,id_cita,tipo,destinatario,asunto,fecha_programada,estado_envio)
                 SELECT (SELECT COALESCE(MAX(id_notificacion),0)+1 FROM public.notificaciones),
                    :appointment,:type,:recipient,:subject,:scheduled,'pendiente'
                 WHERE NOT EXISTS (
                    SELECT 1 FROM public.notificaciones
                    WHERE id_cita=:same_appointment AND tipo=:same_type
                 )
                 RETURNING id_notificacion"
            );
            $stmt->execute([
                'appointment' => $appointmentId,
                'type' => $type,
                'recipient' => $recipient,
                'subject' => $subject,
                'scheduled' => $scheduledAt,
                'same_appointment' => $appointmentId,
                'same_type' => $type,
            ]);
            $id = $stmt->fetchColumn();
            $this->pdo->commit();
            return $id === false ? null : (int)$id;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function pending(int $limit): array
    {
        $stmt = $this->pdo->prepare(self::APPOINTMENT . " WHERE n.estado_envio='pendiente' AND (n.fecha_programada IS NULL OR n.fecha_programada<=CURRENT_TIMESTAMP)
            ORDER BY n.id_notificacion LIMIT :limit");
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function upcoming(\DateTimeImmutable $now, int $leadMinutes, int $limit): array
    {
        $until = $now->modify("+{$leadMinutes} minutes");
        $stmt = $this->pdo->prepare(
            "SELECT c.id_cita,c.codigo_cita,c.fecha,c.hora_inicio,c.hora_fin,c.estado,
                    p.id_paciente,p.id_usuario,p.correo_contacto,
                    m.nombres||' '||m.apellidos AS medico_nombre,e.nombre AS especialidad_nombre
             FROM public.citas c
             JOIN public.pacientes p ON p.id_paciente=c.id_paciente
             JOIN public.medicos m ON m.id_medico=c.id_medico
             JOIN public.especialidades e ON e.id_especialidad=c.id_especialidad
             WHERE c.estado IN ('programada','confirmada')
               AND (c.fecha+c.hora_inicio) >= (CAST(:now AS timestamptz) AT TIME ZONE 'America/La_Paz')
               AND (c.fecha+c.hora_inicio) <= (CAST(:until AS timestamptz) AT TIME ZONE 'America/La_Paz')
               AND p.correo_contacto IS NOT NULL AND p.correo_contacto<>''
               AND NOT EXISTS (
                   SELECT 1 FROM public.notificaciones n
                   WHERE n.id_cita=c.id_cita AND n.tipo='recordatorio_cita'
               )
             ORDER BY c.fecha,c.hora_inicio,c.id_cita
             LIMIT :limit"
        );
        $stmt->bindValue(':now', $now->format('c'));
        $stmt->bindValue(':until', $until->format('c'));
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function markSent(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE public.notificaciones SET estado_envio='enviado',fecha_envio=CURRENT_TIMESTAMP WHERE id_notificacion=:id AND estado_envio='pendiente'");
        $stmt->execute(['id' => $id]);
    }

    public function markError(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE public.notificaciones SET estado_envio='error' WHERE id_notificacion=:id AND estado_envio='pendiente'");
        $stmt->execute(['id' => $id]);
    }

    public function listing(array $actor, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if (($actor['role'] ?? '') === 'Paciente') {
            $where[] = 'p.id_usuario=:user';
            $params['user'] = $actor['id'];
        } elseif (!AuthorizationMiddleware::allows($actor, 'Administrador', 'consultar_auditoria')) {
            throw new \DomainException('Consulta de envíos no autorizada.');
        }
        $sql = self::APPOINTMENT.' WHERE '.($where ? implode(' AND ', $where) : 'TRUE').' ORDER BY n.id_notificacion DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':'.$key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
