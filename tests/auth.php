<?php
declare(strict_types=1);
require dirname(__DIR__) . '/backend/bootstrap.php';

use Clinica\Repositories\AuthRepository;
use Clinica\Services\AuthService;
use Clinica\Services\PasswordService;
use Clinica\Services\LoginThrottle;
use Clinica\Services\SessionService;
use Clinica\Middleware\AuthorizationMiddleware;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FALLO: ' . $label); }
    ++$checks;
};
$password = bin2hex(random_bytes(18));
$hash = PasswordService::hash($password);
$repository = new class($hash) implements AuthRepository {
    public ?array $row;
    public function __construct(string $hash) { $this->row=['id_usuario'=>1,'id_rol'=>1,'nombre_usuario'=>'prueba_memoria','estado'=>'activo','rol'=>'Administrador','contrasena_hash'=>$hash]; }
    public function findByLogin(string $login): ?array { return in_array($login,['prueba_memoria','prueba@example.invalid'],true) ? $this->row : null; }
    public function findById(int $id): ?array { return $id === 1 ? $this->row : null; }
    public function permissions(int $roleId): array { return ['gestionar_usuarios']; }
};
$auth = new AuthService($repository);
$user = $auth->authenticate('prueba_memoria', $password);
$check($user !== null && !isset($user['contrasena_hash']), 'Identidad sin hash');
$check($auth->authenticate('prueba@example.invalid', $password) !== null, 'Acceso por correo');
$check($auth->authenticate('prueba_memoria', 'incorrecta') === null, 'Contraseña incorrecta');
$check($auth->authenticate('no_existe', $password) === null, 'Cuenta inexistente');
$check($auth->authenticate('prueba_memoria', str_repeat('x',73)) === null, 'Sin truncamiento bcrypt');
$check($auth->authenticate('prueba_memoria', "abc\0def") === null, 'Sin bytes nulos');
$repository->row['estado']='inactivo';
$check($auth->authenticate('prueba_memoria', $password) === null, 'Inactivo rechazado incluso con contraseña correcta');
$check($auth->current(1, $user['credential_version']) === null, 'Revocación por desactivación');
$repository->row['estado']='activo';
$repository->row['contrasena_hash']=null;
$check($auth->authenticate('prueba_memoria', $password) === null, 'Cuenta sin hash rechazada');
$repository->row['contrasena_hash']=PasswordService::hash(bin2hex(random_bytes(18)));
$check($auth->current(1, $user['credential_version']) === null, 'Revocación al cambiar contraseña');
$routes = require dirname(__DIR__) . '/backend/routes/web.php';
foreach (AuthService::roles() as $role=>$path) {
    foreach (AuthService::roles() as $other=>$otherPath) {
        $identity=['role'=>$role, 'permissions'=>[$routes[$otherPath]['permission']]];
        $check(AuthorizationMiddleware::allows($identity,$routes[$otherPath]['role'],$routes[$otherPath]['permission']) === ($role === $other), 'Matriz de acceso por roles');
    }
    $check(!AuthorizationMiddleware::allows(['role'=>$role,'permissions'=>[]],$role,$routes[$path]['permission']), 'Sin permiso no accede al panel');
}
$_SESSION=[];
$csrf=SessionService::csrf();
$check(SessionService::validCsrf($csrf), 'CSRF válido');
$check(!SessionService::validCsrf('otro') && !SessionService::validCsrf(null) && !SessionService::validCsrf([]), 'CSRF inválido rechazado');
$check(SessionService::fresh(['created'=>1000,'last'=>1000],1001), 'Sesión vigente');
$check(!SessionService::fresh(['created'=>1000,'last'=>1000],2800), 'Expiración inactividad');
$check(!SessionService::fresh(['created'=>1000,'last'=>29799],29800), 'Expiración absoluta');
$directory=sys_get_temp_dir().'/cvs-throttle-'.bin2hex(random_bytes(8));
try {
    $limiter=new LoginThrottle($directory);
    for($i=0;$i<5;$i++) { $check(!$limiter->attempt('127.0.0.1','Prueba',static fn()=>null,1000)['limited'],'Intentos iniciales'); }
    $called=false;
    $blocked=$limiter->attempt('otra-ip','prueba',static function() use (&$called) { $called=true; return null; },1001);
    $check($blocked['limited'] && !$called && $blocked['retry']===899,'Límite por cuenta independiente de IP y sesión');
    $check(!$limiter->attempt('otra-ip','prueba',static fn()=>null,1900)['limited'],'Vencimiento de bloqueo');
    for($i=0;$i<20;$i++) { $limiter->attempt('ip-comun','cuenta-'.$i,static fn()=>null,2000); }
    $check($limiter->attempt('ip-comun','otra-cuenta',static fn()=>null,2001)['limited'],'Límite por IP con cuentas diferentes');
} finally {
    if(is_file($directory.'/attempts.json')) { unlink($directory.'/attempts.json'); }
    if(is_dir($directory)) { rmdir($directory); }
}
foreach(AuthService::roles() as $role=>$path) {
    $user=['role'=>$role,'username'=>'<script>','permissions'=>['consultar_agenda']];
    ob_start(); (new Clinica\Controllers\DashboardController())->index($user); $html=ob_get_clean();
    $check(str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>'),'Panel por rol y salida escapada');
}
unset($password,$hash);
echo "$checks comprobaciones de autenticación pasaron (solo memoria y archivos temporales; sin BD).\n";
