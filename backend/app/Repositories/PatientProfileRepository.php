<?php
declare(strict_types=1);
namespace Clinica\Repositories;
final class PatientProfileRepository implements PatientProfileStore
{
    public function __construct(private \PDO $pdo) {}
    public function findByUser(int $userId): ?array
    {
        $s = $this->pdo->prepare('SELECT p.id_paciente,p.codigo_paciente,p.documento_identidad,p.nombres,p.apellidos,p.fecha_nacimiento,p.sexo,p.telefono,p.correo_contacto,p.direccion,p.contacto_emergencia_nombre,p.contacto_emergencia_parentesco,p.contacto_emergencia_telefono,p.tipo_sangre,p.alergias,p.antecedentes_relevantes,u.correo_acceso,p.updated_at FROM public.pacientes p JOIN public.usuarios u ON u.id_usuario=p.id_usuario WHERE p.id_usuario=:id');
        $s->execute(['id' => $userId]);
        return $s->fetch() ?: null;
    }
    public function update(int $userId, array $data): void
    {
        $this->pdo->beginTransaction();
        try {
            $user = $this->pdo->prepare('UPDATE public.usuarios SET correo_acceso=:email WHERE id_usuario=:id');
            $user->execute(['email' => $data['correo_acceso'], 'id' => $userId]);
            unset($data['correo_acceso']);
            $set = implode(',', array_map(static fn(string $field): string => "$field=:$field", array_keys($data)));
            $data['id'] = $userId;
            $s = $this->pdo->prepare("UPDATE public.pacientes SET $set WHERE id_usuario=:id");
            $s->execute($data);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($e instanceof \PDOException && $e->getCode() === '23505') { throw new \RuntimeException('Ese correo ya está registrado.', 0, $e); }
            throw $e;
        }
    }
}
