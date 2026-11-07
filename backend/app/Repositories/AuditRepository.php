<?php
declare(strict_types=1);
namespace Clinica\Repositories;

final class AuditRepository implements AuditStore
{
    public function __construct(private \PDO $pdo) {}

    public function choices(): array
    {
        return ['users' => $this->pdo->query(
            "SELECT u.id_usuario,u.nombre_usuario,r.nombre AS rol
             FROM public.usuarios u JOIN public.roles r ON r.id_rol=u.id_rol
             ORDER BY u.nombre_usuario,u.id_usuario"
        )->fetchAll()];
    }

    private function where(array $filters, string $alias = 'a'): array
    {
        $where = ['TRUE'];
        $params = [];
        if ($filters['usuario'] !== null) {
            $where[] = "$alias.id_usuario=:usuario";
            $params['usuario'] = $filters['usuario'];
        }
        if ($filters['fecha_inicio'] !== null) {
            $where[] = "$alias.fecha_hora>=:fecha_inicio";
            $params['fecha_inicio'] = $filters['fecha_inicio'].' 00:00:00';
        }
        if ($filters['fecha_fin'] !== null) {
            $where[] = "$alias.fecha_hora<(CAST(:fecha_fin AS date) + INTERVAL '1 day')";
            $params['fecha_fin'] = $filters['fecha_fin'];
        }
        if ($filters['operacion'] !== null) {
            $where[] = "$alias.operacion=:operacion";
            $params['operacion'] = $filters['operacion'];
        }
        if ($filters['entidad'] !== null) {
            $where[] = "$alias.entidad_afectada ILIKE :entidad ESCAPE '!'";
            $params['entidad'] = '%'.strtr($filters['entidad'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        }
        return [implode(' AND ', $where), $params];
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->where($filters);
        $statement = $this->pdo->prepare("SELECT count(*) FROM public.registros_auditoria a WHERE $where");
        $statement->execute($params);
        return (int)$statement->fetchColumn();
    }

    public function listing(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filters);
        $statement = $this->pdo->prepare(
            "SELECT a.id_registro,a.id_usuario,a.fecha_hora,a.operacion,a.entidad_afectada,a.id_entidad,a.detalles,
                    u.nombre_usuario,r.nombre AS rol
             FROM public.registros_auditoria a
             LEFT JOIN public.usuarios u ON u.id_usuario=a.id_usuario
             LEFT JOIN public.roles r ON r.id_rol=u.id_rol
             WHERE $where ORDER BY a.fecha_hora DESC,a.id_registro DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $statement->bindValue(':'.$key, $value);
        }
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT a.id_registro,a.id_usuario,a.fecha_hora,a.operacion,a.entidad_afectada,a.id_entidad,a.detalles,
                    u.nombre_usuario,r.nombre AS rol
             FROM public.registros_auditoria a
             LEFT JOIN public.usuarios u ON u.id_usuario=a.id_usuario
             LEFT JOIN public.roles r ON r.id_rol=u.id_rol
             WHERE a.id_registro=:id"
        );
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: null;
    }

    public function appointmentHistory(array $filters, int $limit): array
    {
        $where = ['TRUE'];
        $params = [];
        if ($filters['usuario'] !== null) {
            $where[] = 'h.id_usuario=:hist_usuario';
            $params['hist_usuario'] = $filters['usuario'];
        }
        if ($filters['fecha_inicio'] !== null) {
            $where[] = 'h.fecha_hora_cambio>=:hist_inicio';
            $params['hist_inicio'] = $filters['fecha_inicio'].' 00:00:00';
        }
        if ($filters['fecha_fin'] !== null) {
            $where[] = "h.fecha_hora_cambio<(CAST(:hist_fin AS date) + INTERVAL '1 day')";
            $params['hist_fin'] = $filters['fecha_fin'];
        }
        $statement = $this->pdo->prepare(
            "SELECT h.id_cambio,h.id_cita,h.id_usuario,h.fecha_hora_cambio,h.tipo_cambio,
                    h.fecha_anterior,h.hora_inicio_anterior,h.hora_fin_anterior,h.fecha_nueva,h.hora_inicio_nueva,
                    h.hora_fin_nueva,h.estado_anterior,h.estado_nuevo,h.observacion,
                    u.nombre_usuario,r.nombre AS rol
             FROM public.historial_cambios_cita h
             LEFT JOIN public.usuarios u ON u.id_usuario=h.id_usuario
             LEFT JOIN public.roles r ON r.id_rol=u.id_rol
             WHERE ".implode(' AND ', $where)."
             ORDER BY h.fecha_hora_cambio DESC,h.id_cambio DESC LIMIT :limit"
        );
        foreach ($params as $key => $value) {
            $statement->bindValue(':'.$key, $value);
        }
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }
}
