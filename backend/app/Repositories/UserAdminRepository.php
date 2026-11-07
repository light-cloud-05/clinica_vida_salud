<?php
declare(strict_types=1);
namespace Clinica\Repositories;

final class UserAdminRepository implements UserAdminStore
{
    public function __construct(private \PDO $pdo) {}

    private function filters(string $search, ?int $roleId, ?string $status): array
    {
        $where = [];
        $params = [];
        if ($search !== '') {
            $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where[] = "(u.nombre_usuario ILIKE :search ESCAPE '!' OR u.correo_acceso ILIKE :search ESCAPE '!')";
            $params['search'] = $pattern;
        }
        if ($roleId !== null) { $where[] = 'u.id_rol=:role'; $params['role'] = $roleId; }
        if ($status !== null) { $where[] = 'u.estado=:status'; $params['status'] = $status; }
        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
    }

    public function count(string $search, ?int $roleId, ?string $status): int
    {
        [$where, $params] = $this->filters($search, $roleId, $status);
        $stmt = $this->pdo->prepare('SELECT count(*) FROM public.usuarios u' . $where);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function listing(string $search, ?int $roleId, ?string $status, int $limit, int $offset): array
    {
        [$where, $params] = $this->filters($search, $roleId, $status);
        $stmt = $this->pdo->prepare('SELECT u.id_usuario,u.id_rol,u.nombre_usuario,u.correo_acceso,u.estado,u.created_at,u.updated_at,r.nombre AS rol
            FROM public.usuarios u JOIN public.roles r ON r.id_rol=u.id_rol' . $where .
            ' ORDER BY u.nombre_usuario,u.id_usuario LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) { $stmt->bindValue(':' . $key, $value); }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT u.id_usuario,u.id_rol,u.nombre_usuario,u.correo_acceso,u.estado,u.created_at,u.updated_at,
            r.nombre AS rol,r.descripcion AS rol_descripcion,
            p.id_paciente,p.nombres AS paciente_nombres,p.apellidos AS paciente_apellidos,
            m.id_medico,m.nombres AS medico_nombres,m.apellidos AS medico_apellidos
            FROM public.usuarios u JOIN public.roles r ON r.id_rol=u.id_rol
            LEFT JOIN public.pacientes p ON p.id_usuario=u.id_usuario
            LEFT JOIN public.medicos m ON m.id_usuario=u.id_usuario
            WHERE u.id_usuario=:id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function roles(): array
    {
        return $this->pdo->query('SELECT id_rol,nombre,descripcion FROM public.roles ORDER BY id_rol')->fetchAll();
    }

    public function rolePermissions(): array
    {
        return $this->pdo->query('SELECT r.id_rol,r.nombre AS rol,r.descripcion AS rol_descripcion,p.nombre AS permiso,p.descripcion
            FROM public.roles r LEFT JOIN public.rol_permiso rp ON rp.id_rol=r.id_rol
            LEFT JOIN public.permisos p ON p.id_permiso=rp.id_permiso ORDER BY r.id_rol,p.nombre')->fetchAll();
    }

    public function duplicates(string $username, string $email, ?int $exclude): array
    {
        $stmt = $this->pdo->prepare('SELECT nombre_usuario,correo_acceso FROM public.usuarios
            WHERE (nombre_usuario=:username OR correo_acceso=:email) AND id_usuario<>:exclude');
        $stmt->execute(['username' => $username, 'email' => $email, 'exclude' => $exclude ?? 0]);
        $errors = [];
        foreach ($stmt->fetchAll() as $row) {
            if (strcasecmp($row['nombre_usuario'], $username) === 0) { $errors['nombre_usuario'] = 'Ese nombre de usuario ya está registrado.'; }
            if (strcasecmp($row['correo_acceso'], $email) === 0) { $errors['correo_acceso'] = 'Ese correo ya está registrado.'; }
        }
        return $errors;
    }

    public function activeAdministrators(): int
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM public.usuarios u JOIN public.roles r ON r.id_rol=u.id_rol WHERE r.nombre='Administrador' AND u.estado='activo'");
        return (int)$stmt->fetchColumn();
    }

    public function save(array $data, ?int $id, int $actor): int
    {
        $this->pdo->beginTransaction();
        try {
            $audit = $this->pdo->prepare("SELECT set_config('app.usuario_id',:actor,true)");
            $audit->execute(['actor' => (string)$actor]);
            if ($id !== null) {
                $lock = $this->pdo->query("SELECT u.id_usuario FROM public.usuarios u JOIN public.roles r ON r.id_rol=u.id_rol WHERE r.nombre='Administrador' AND u.estado='activo' FOR UPDATE");
                $activeAdministrators = array_map(static fn(array $row): int => (int)$row['id_usuario'], $lock->fetchAll());
                if (count($activeAdministrators) === 1 && in_array($id, $activeAdministrators, true)
                    && ((string)$data['status'] === 'inactivo' || (int)$data['role'] !== (int)$this->pdo->query("SELECT id_rol FROM public.roles WHERE nombre='Administrador'")->fetchColumn())) {
                    throw new \RuntimeException('No se puede desactivar la última cuenta administrativa activa.');
                }
            }
            if ($id === null) {
                $stmt = $this->pdo->prepare('INSERT INTO public.usuarios (id_rol,nombre_usuario,correo_acceso,contrasena_hash,estado)
                    VALUES (:role,:username,:email,:hash,:status) RETURNING id_usuario');
                $stmt->execute($data + ['hash' => null]);
            } else {
                $hashPart = array_key_exists('hash', $data) ? ',contrasena_hash=:hash' : '';
                $stmt = $this->pdo->prepare('UPDATE public.usuarios SET id_rol=:role,nombre_usuario=:username,correo_acceso=:email,estado=:status' . $hashPart . '
                    WHERE id_usuario=:id RETURNING id_usuario');
                $data['id'] = $id;
                $stmt->execute($data);
            }
            $saved = $stmt->fetchColumn();
            if ($saved === false) { throw new \RuntimeException('La cuenta no existe o cambió durante la operación.'); }
            $this->pdo->commit();
            return (int)$saved;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($e instanceof \PDOException && $e->getCode() === '23505') {
                throw new \RuntimeException('El nombre de usuario o correo ya está registrado.', 0, $e);
            }
            throw $e;
        }
    }
}
