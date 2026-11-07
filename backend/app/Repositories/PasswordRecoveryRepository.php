<?php
declare(strict_types=1);
namespace Clinica\Repositories;

final class PasswordRecoveryRepository implements PasswordRecoveryStore
{
    public function __construct(private \PDO $pdo) {}
    public function userByEmail(string $email): ?array
    {
        $s = $this->pdo->prepare("SELECT u.id_usuario,u.correo_acceso FROM public.usuarios u JOIN public.roles r ON r.id_rol=u.id_rol WHERE lower(u.correo_acceso)=lower(:email) AND u.estado='activo' LIMIT 1");
        $s->execute(['email' => $email]);
        return $s->fetch() ?: null;
    }
    public function activeRequests(int $userId): int
    {
        $s = $this->pdo->prepare('SELECT count(*) FROM public.recuperacion_contrasena WHERE id_usuario=:id AND fecha_uso IS NULL AND fecha_expiracion > CURRENT_TIMESTAMP');
        $s->execute(['id' => $userId]);
        return (int)$s->fetchColumn();
    }
    public function create(int $userId, string $hash, \DateTimeImmutable $expires): void
    {
        $s = $this->pdo->prepare('INSERT INTO public.recuperacion_contrasena (id_usuario,token_hash,fecha_expiracion) VALUES (:id,:hash,:expires)');
        $s->execute(['id' => $userId, 'hash' => $hash, 'expires' => $expires->format('c')]);
    }
    public function reset(string $hash, string $newPassword): bool
    {
        $this->pdo->beginTransaction();
        try {
            $s = $this->pdo->prepare('SELECT id_recuperacion,id_usuario FROM public.recuperacion_contrasena WHERE token_hash=:hash AND fecha_uso IS NULL AND fecha_expiracion>CURRENT_TIMESTAMP FOR UPDATE');
            $s->execute(['hash' => $hash]);
            $row = $s->fetch();
            if (!$row) { $this->pdo->rollBack(); return false; }
            $update = $this->pdo->prepare('UPDATE public.usuarios SET contrasena_hash=:password WHERE id_usuario=:id');
            $update->execute(['password' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => (int)$row['id_usuario']]);
            $used = $this->pdo->prepare('UPDATE public.recuperacion_contrasena SET fecha_uso=CURRENT_TIMESTAMP WHERE id_recuperacion=:id');
            $used->execute(['id' => (int)$row['id_recuperacion']]);
            $this->pdo->commit();
            return $update->rowCount() === 1;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }
}
