<?php
declare(strict_types=1);
namespace Clinica\Services;

use Clinica\Repositories\AuthRepository;

final class AuthService
{
    private static ?string $dummyHash = null;

    public function __construct(private AuthRepository $users) {}

    public static function roles(): array
    {
        return ['Administrador'=>'/administrador', 'Recepcionista'=>'/recepcionista', 'Medico'=>'/medico', 'Paciente'=>'/paciente'];
    }

    public function authenticate(string $login, string $password): ?array
    {
        if ($login === '' || strlen($login) > 320 || $password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
            return null;
        }
        self::$dummyHash ??= password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
        $user = $this->users->findByLogin(trim($login));
        $hash = $user['contrasena_hash'] ?? null;
        $verified = password_verify($password, is_string($hash) && $hash !== '' ? $hash : self::$dummyHash);
        if (!$verified || !$user || !is_string($hash) || $hash === '' || $user['estado'] !== 'activo' || !isset(self::roles()[$user['rol']])) {
            return null;
        }
        return $this->identity($user);
    }

    public function current(int $id, string $credentialVersion): ?array
    {
        $user = $this->users->findById($id);
        if (!$user || $user['estado'] !== 'activo' || !is_string($user['contrasena_hash']) || !isset(self::roles()[$user['rol']]) || !hash_equals(hash('sha256', $user['contrasena_hash']), $credentialVersion)) {
            return null;
        }
        return $this->identity($user);
    }

    private function identity(array $user): array
    {
        return ['id'=>(int)$user['id_usuario'], 'username'=>$user['nombre_usuario'], 'role'=>$user['rol'], 'permissions'=>$this->users->permissions((int)$user['id_rol']), 'credential_version'=>hash('sha256', $user['contrasena_hash'])];
    }
}
