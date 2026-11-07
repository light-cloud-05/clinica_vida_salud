<?php
declare(strict_types=1);
namespace Clinica\Repositories;

final class PatientAccountRepository implements PatientAccountStore
{
    public function __construct(private \PDO $pdo) {}

    public function duplicates(string $username, string $email, string $code, string $document): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                EXISTS(SELECT 1 FROM public.usuarios WHERE lower(nombre_usuario)=lower(:username)) AS username,
                EXISTS(SELECT 1 FROM public.usuarios WHERE lower(correo_acceso)=lower(:email)) AS email,
                EXISTS(SELECT 1 FROM public.pacientes WHERE codigo_paciente=:code) AS code,
                EXISTS(SELECT 1 FROM public.pacientes WHERE documento_identidad=:document) AS document"
        );
        $stmt->execute(compact('username', 'email', 'code', 'document'));
        $row = $stmt->fetch() ?: [];
        return [
            'nombre_usuario' => filter_var($row['username'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'Ese nombre de usuario ya está registrado.' : null,
            'correo_acceso' => filter_var($row['email'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'Ese correo ya está registrado.' : null,
            'codigo_paciente' => filter_var($row['code'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'Ese código de paciente ya está registrado.' : null,
            'documento_identidad' => filter_var($row['document'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'Ese documento de identidad ya está registrado.' : null,
        ];
    }

    public function matchingPatient(array $identity): ?array
    {
        $s = $this->pdo->prepare('SELECT id_paciente,id_usuario FROM public.pacientes WHERE codigo_paciente=:code AND documento_identidad=:document AND lower(nombres)=lower(:names) AND lower(apellidos)=lower(:surnames)');
        $s->execute(['code' => $identity['codigo_paciente'], 'document' => $identity['documento_identidad'], 'names' => $identity['nombres'], 'surnames' => $identity['apellidos']]);
        return $s->fetch() ?: null;
    }

    public function register(array $account, array $patient, ?int $existingPatientId = null): int
    {
        $this->pdo->beginTransaction();
        try {
            $role = $this->pdo->prepare("SELECT id_rol FROM public.roles WHERE nombre='Paciente'");
            $role->execute();
            $roleId = $role->fetchColumn();
            if ($roleId === false) {
                throw new \RuntimeException('El rol Paciente no está configurado.');
            }
            $user = $this->pdo->prepare(
                'INSERT INTO public.usuarios (id_rol,nombre_usuario,correo_acceso,contrasena_hash,estado)
                 VALUES (:role,:username,:email,:hash,\'activo\') RETURNING id_usuario'
            );
            $user->execute(['role' => (int)$roleId] + $account);
            $userId = (int)$user->fetchColumn();
            if ($existingPatientId !== null) {
                $link = $this->pdo->prepare('UPDATE public.pacientes SET id_usuario=:user WHERE id_paciente=:patient AND id_usuario IS NULL');
                $link->execute(['user' => $userId, 'patient' => $existingPatientId]);
                if ($link->rowCount() !== 1) { throw new \RuntimeException('El paciente ya tiene una cuenta vinculada.'); }
            } else {
                $patient['id_usuario'] = $userId;
                $fields = array_keys($patient);
                $stmt = $this->pdo->prepare('INSERT INTO public.pacientes (' . implode(',', $fields) . ') VALUES (:' . implode(',:', $fields) . ')');
                $stmt->execute($patient);
            }
            $this->pdo->commit();
            return $userId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($e instanceof \PDOException && $e->getCode() === '23505') {
                throw new \RuntimeException('El nombre de usuario, correo o identificación ya está registrado.', 0, $e);
            }
            throw $e;
        }
    }
}
