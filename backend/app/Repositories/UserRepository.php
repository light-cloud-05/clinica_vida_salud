<?php
declare(strict_types=1);
namespace Clinica\Repositories;

final class UserRepository implements AuthRepository
{
    public function __construct(private \PDO $pdo) {}

    private const COLUMNS = 'u.id_usuario,u.id_rol,u.nombre_usuario,u.contrasena_hash,u.estado,r.nombre AS rol';

    public function findByLogin(string $login): ?array
    {
        // Dos parámetros distintos para consultas nativas PDO. No priorizar coincidencias ambiguas.
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM public.usuarios u JOIN public.roles r ON r.id_rol=u.id_rol WHERE lower(u.nombre_usuario)=lower(:username) OR lower(u.correo_acceso)=lower(:email) LIMIT 2');
        $stmt->execute(['username'=>$login, 'email'=>$login]);
        $rows = $stmt->fetchAll();
        return count($rows) === 1 ? $rows[0] : null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM public.usuarios u JOIN public.roles r ON r.id_rol=u.id_rol WHERE u.id_usuario=:id');
        $stmt->execute(['id'=>$id]);
        return $stmt->fetch() ?: null;
    }

    public function permissions(int $roleId): array
    {
        $stmt = $this->pdo->prepare('SELECT p.nombre FROM public.permisos p JOIN public.rol_permiso rp ON rp.id_permiso=p.id_permiso WHERE rp.id_rol=:role ORDER BY p.nombre');
        $stmt->execute(['role'=>$roleId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
