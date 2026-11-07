<?php
declare(strict_types=1);
require dirname(__DIR__) . '/backend/bootstrap.php';

use Clinica\Repositories\UserAdminStore;
use Clinica\Middleware\AuthorizationMiddleware;
use Clinica\Services\SessionService;
use Clinica\Services\UserAdminService;
use Clinica\Services\UserAdminValidation;
use Clinica\Services\UserAdminValidator;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FALLO: ' . $label); }
    ++$checks;
};
$roles = [
    ['id_rol'=>1,'nombre'=>'Administrador','descripcion'=>'Administración'],
    ['id_rol'=>2,'nombre'=>'Recepcionista','descripcion'=>'Recepción'],
    ['id_rol'=>3,'nombre'=>'Medico','descripcion'=>'Atención médica'],
    ['id_rol'=>4,'nombre'=>'Paciente','descripcion'=>'Paciente'],
];
$valid = UserAdminValidator::validate([
    'nombre_usuario'=>'nuevo','correo_acceso'=>'nuevo@example.test','id_rol'=>'2','estado'=>'inactivo',
    'contrasena'=>'','contrasena_confirmacion'=>'','csrf'=>'token'
], $roles, false);
$check(!$valid['errors'], 'Formulario válido de cuenta inactiva');
$check(isset($valid['data']['hash']) === false, 'Cuenta inactiva sin contraseña fija');
foreach (['nombre_usuario'=>'','correo_acceso'=>'no-es-correo','id_rol'=>'99','estado'=>'otro'] as $field => $value) {
    $input = ['nombre_usuario'=>'nuevo','correo_acceso'=>'nuevo@example.test','id_rol'=>'2','estado'=>'inactivo','contrasena'=>'','contrasena_confirmacion'=>'','csrf'=>'token'];
    $input[$field] = $value;
    $check(UserAdminValidator::validate($input, $roles, false)['errors'] !== [], 'Validación de '.$field);
}
$password = UserAdminValidator::validate([
    'nombre_usuario'=>'nuevo','correo_acceso'=>'nuevo@example.test','id_rol'=>'2','estado'=>'activo',
    'contrasena'=>'secreto-seguro','contrasena_confirmacion'=>'secreto-seguro','csrf'=>'token'
], $roles, false);
$check(!$password['errors'] && password_verify('secreto-seguro', $password['data']['hash']), 'Hash de contraseña verificable');

$store = new class($roles) implements UserAdminStore {
    public array $rows = [];
    public int $writes = 0;
    public function __construct(public array $roles) {}
    public function count(string $search, ?int $roleId, ?string $status): int { return count($this->rows); }
    public function listing(string $search, ?int $roleId, ?string $status, int $limit, int $offset): array { return array_slice(array_values($this->rows), $offset, $limit); }
    public function find(int $id): ?array { return $this->rows[$id] ?? null; }
    public function roles(): array { return $this->roles; }
    public function rolePermissions(): array { return [['id_rol'=>1,'rol'=>'Administrador','rol_descripcion'=>'Administración','permiso'=>'gestionar_usuarios','descripcion'=>'Usuarios']]; }
    public function duplicates(string $username, string $email, ?int $exclude): array {
        foreach ($this->rows as $id => $row) {
            if ($id !== $exclude && $row['nombre_usuario'] === $username) { return ['nombre_usuario'=>'Duplicado']; }
            if ($id !== $exclude && $row['correo_acceso'] === $email) { return ['correo_acceso'=>'Duplicado']; }
        }
        return [];
    }
    public function save(array $data, ?int $id, int $actor): int {
        ++$this->writes; $id ??= 2;
        $role = array_values(array_filter($this->roles, static fn(array $role): bool => (int)$role['id_rol'] === (int)$data['role']))[0]['nombre'];
        $this->rows[$id] = ['id_usuario'=>$id,'id_rol'=>$data['role'],'rol'=>$role,'nombre_usuario'=>$data['username'],'correo_acceso'=>$data['email'],'estado'=>$data['status'],'id_paciente'=>null,'id_medico'=>null];
        return $id;
    }
    public function activeAdministrators(): int { return 1; }
};
$form = [
    'nombre_usuario'=>'nuevo','correo_acceso'=>'nuevo@example.test','id_rol'=>'2','estado'=>'inactivo',
    'contrasena'=>'','contrasena_confirmacion'=>'','csrf'=>'token'
];
$service = new UserAdminService($store, false);
$actor = ['id'=>1,'role'=>'Administrador','permissions'=>['gestionar_usuarios']];
try { $service->save($form ?? [], null, $actor); $check(false, 'Escritura bloqueada'); } catch (RuntimeException $e) { $check($store->writes === 0, 'Bloqueo sin persistencia'); }
$service = new UserAdminService($store, true);
$service->save($form, null, $actor);
$check($store->writes === 1, 'Alta simulada');
try { $service->save($form, null, $actor); $check(false, 'Duplicado rechazado'); } catch (UserAdminValidation $e) { $check(isset($e->errors['nombre_usuario']), 'Duplicado validado'); }
$store->rows[3] = ['id_usuario'=>3,'id_rol'=>3,'rol'=>'Medico','nombre_usuario'=>'doctor','correo_acceso'=>'doctor@example.test','estado'=>'activo','id_paciente'=>null,'id_medico'=>8];
try { $service->save(['nombre_usuario'=>'doctor','correo_acceso'=>'doctor@example.test','id_rol'=>'2','estado'=>'activo','contrasena'=>'','contrasena_confirmacion'=>''], 3, $actor); $check(false, 'Relación protegida'); } catch (UserAdminValidation $e) { $check(isset($e->errors['id_rol']), 'Relación médico protegida'); }
try { $service->save($form, 1, $actor); $check(false, 'Auto modificación rechazada'); } catch (UserAdminValidation $e) { $check(true, 'Auto modificación rechazada'); }
$routes = require dirname(__DIR__) . '/backend/routes/web.php';
foreach (['/usuarios','/usuarios/detalle','/usuarios/nuevo','/usuarios/editar','/usuarios/roles'] as $path) {
    $check(($routes[$path]['role'] ?? '') === 'Administrador' && ($routes[$path]['permission'] ?? '') === 'gestionar_usuarios', 'Ruta protegida '.$path);
}
foreach (['Recepcionista','Medico','Paciente'] as $role) {
    $check(!AuthorizationMiddleware::allows(['role'=>$role,'permissions'=>['gestionar_usuarios']], 'Administrador', 'gestionar_usuarios'), 'Acceso denegado para '.$role);
}
$_SESSION = ['csrf'=>'csrf-test'];
$check(SessionService::validCsrf('csrf-test') && !SessionService::validCsrf('incorrecto'), 'CSRF válido y token incorrecto rechazado');
echo "$checks comprobaciones de usuarios pasaron: escrituras solo simuladas, sin BD.\n";
