<?php
declare(strict_types=1);
require dirname(__DIR__) . '/backend/bootstrap.php';

use Clinica\Services\SessionService;
use Clinica\Services\AuthService;
use Clinica\Middleware\AuthMiddleware;
use Clinica\Repositories\AuthRepository;

$checks=0;
$check=static function(bool $ok) use (&$checks): void { if(!$ok) {throw new RuntimeException('Fallo de seguridad de sesión.');} ++$checks; };
SessionService::start();
$before=session_id();
$oldCsrf=SessionService::csrf();
$hash=password_hash(bin2hex(random_bytes(18)),PASSWORD_DEFAULT);
$repository=new class($hash) implements AuthRepository {
    public string $state='activo';
    public function __construct(private string $hash) {}
    public function findByLogin(string $login): ?array {return null;}
    public function findById(int $id): ?array { return ['id_usuario'=>1,'id_rol'=>1,'nombre_usuario'=>'memoria','estado'=>$this->state,'rol'=>'Administrador','contrasena_hash'=>$this->hash]; }
    public function permissions(int $roleId): array { return ['gestionar_usuarios']; }
};
SessionService::login(['id'=>1,'credential_version'=>hash('sha256',$hash)]);
$check($before!==session_id());
$check(!is_file(session_save_path().'/sess_'.$before));
$check(!SessionService::validCsrf($oldCsrf));
$check(session_get_cookie_params()['httponly'] && session_get_cookie_params()['samesite']==='Lax');
$check(!isset($_SESSION['auth']['contrasena_hash']) && !isset($_SESSION['auth']['role']));
$check(AuthMiddleware::current(new AuthService($repository)) !== null);
$repository->state='inactivo';
$check(AuthMiddleware::current(new AuthService($repository)) === null);
$check(session_status()!==PHP_SESSION_ACTIVE && $_SESSION===[]);
echo "$checks comprobaciones de sesión pasaron, incluida renovación e invalidación (sin BD).\n";
