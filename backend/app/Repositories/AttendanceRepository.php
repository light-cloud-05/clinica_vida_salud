<?php
declare(strict_types=1);
namespace Clinica\Repositories;

use Clinica\Middleware\{AttendanceAccess, CitaAccess};
use Clinica\Services\{AttendancePolicy, BookingConflict};

final class AttendanceRepository implements AttendanceStore
{
    private const COLUMNS = 'c.id_cita,c.codigo_cita,c.id_paciente,c.id_medico,c.id_especialidad,c.fecha,c.hora_inicio,c.hora_fin,c.estado,c.cantidad_reprogramaciones,c.updated_at,p.nombres||\' \'||p.apellidos AS paciente_nombre,m.nombres||\' \'||m.apellidos AS medico_nombre,e.nombre AS especialidad_nombre';
    public function __construct(private \PDO $pdo) {}

    private function scope(array $actor, string $alias = 'c'): array
    {
        if (($actor['id'] ?? 0) < 1 || !AttendanceAccess::view($actor)) {
            throw new \DomainException('Consulta de asistencia no autorizada.');
        }
        $params = [];
        $where = 'TRUE';
        if ($actor['role'] === 'Paciente') {
            $where = "p.id_usuario=:scope_user";
            $params['scope_user'] = $actor['id'];
        } elseif ($actor['role'] === 'Medico') {
            $where = "m.id_usuario=:scope_user";
            $params['scope_user'] = $actor['id'];
        }
        return [$where, $params];
    }

    public function find(array $actor, int $id, bool $lock = false): ?array
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Identificador de cita inválido.');
        }
        [$where, $params] = $this->scope($actor);
        $params['id'] = $id;
        $sql = 'SELECT '.self::COLUMNS.' FROM public.citas c
            JOIN public.pacientes p ON p.id_paciente=c.id_paciente
            JOIN public.medicos m ON m.id_medico=c.id_medico
            JOIN public.especialidades e ON e.id_especialidad=c.id_especialidad
            WHERE '.$where.' AND c.id_cita=:id';
        if ($lock) {
            $sql .= ' FOR UPDATE OF c';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetch() ?: null;
    }

    public function history(array $actor, int $id): array
    {
        [$scope, $params] = $this->scope($actor, 'c');
        $params['id'] = $id;
        $statement = $this->pdo->prepare(
            'SELECT h.id_cambio,h.fecha_hora_cambio,h.tipo_cambio,h.fecha_anterior,
                    h.hora_inicio_anterior,h.hora_fin_anterior,h.fecha_nueva,h.hora_inicio_nueva,
                    h.hora_fin_nueva,h.estado_anterior,h.estado_nuevo,h.observacion,
                    u.nombre_usuario,r.nombre AS rol
             FROM public.historial_cambios_cita h
             JOIN public.citas c ON c.id_cita=h.id_cita
             JOIN public.pacientes p ON p.id_paciente=c.id_paciente
             JOIN public.medicos m ON m.id_medico=c.id_medico
             LEFT JOIN public.usuarios u ON u.id_usuario=h.id_usuario
             LEFT JOIN public.roles r ON r.id_rol=u.id_rol
             WHERE '.$scope.' AND h.id_cita=:id
             ORDER BY h.fecha_hora_cambio DESC,h.id_cambio DESC'
        );
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function change(array $actor, array $appointment, string $state): void
    {
        $action = match ($state) {
            'confirmada' => 'confirmar',
            'atendida' => 'atender',
            'inasistencia' => 'inasistencia',
            default => throw new \InvalidArgumentException('Estado de asistencia inválido.'),
        };
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("SET LOCAL lock_timeout = '3s'");
            $current = $this->find($actor, (int) $appointment['id_cita'], true);
            if (!$current || !hash_equals(AttendancePolicy::version($current), AttendancePolicy::version($appointment))) {
                throw new BookingConflict('La cita cambió. Vuelva a abrir el formulario.');
            }
            AttendancePolicy::check(
                $current,
                $action,
                new \DateTimeImmutable(
                    (string) $this->pdo->query("SELECT (clock_timestamp() AT TIME ZONE 'America/La_Paz')::text")->fetchColumn(),
                    new \DateTimeZone('America/La_Paz')
                )
            );
            $statement = $this->pdo->prepare("SELECT set_config('app.usuario_id',:actor,true)");
            $statement->execute(['actor' => (string) $actor['id']]);
            $statement = $this->pdo->prepare(
                'UPDATE public.citas SET estado=:state WHERE id_cita=:id AND estado=:old_state'
            );
            $statement->execute([
                'state' => $state,
                'id' => $current['id_cita'],
                'old_state' => $current['estado'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new BookingConflict('La cita cambió. Consulte nuevamente su estado.');
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof \PDOException && in_array($exception->getCode(), ['23514', '55P03', '40P01', '40001', '57014'], true)) {
                throw new BookingConflict('La cita cambió o la transición incumple una restricción.');
            }
            throw $exception;
        }
    }
}
