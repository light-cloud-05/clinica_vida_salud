<?php
declare(strict_types=1);
// Integración optativa CLI. Solo SELECT; no genera contraseñas persistidas ni activa cuentas.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/backend/bootstrap.php';

use Clinica\Repositories\Connection;
use Clinica\Repositories\UserRepository;
use Clinica\Services\AuthService;

try {
    $pdo=Connection::readOnly(require dirname(__DIR__).'/backend/config/database.php');
    $pdo->beginTransaction();
    if($pdo->query('SELECT current_database()')->fetchColumn()!=='clinica_vida_salud' || $pdo->query('SHOW transaction_read_only')->fetchColumn()!=='on') {throw new RuntimeException();}
    $snapshot=static fn(): string => hash('sha256',(string)$pdo->query("SELECT json_agg(u ORDER BY id_usuario)::text FROM public.usuarios u")->fetchColumn());
    $before=$snapshot();
    $repository=new UserRepository($pdo);
    $auth=new AuthService($repository);
    $probe=bin2hex(random_bytes(18));
    $demos=['usuario1'=>'Administrador','usuario2'=>'Recepcionista','profesional01'=>'Medico','paciente01'=>'Paciente'];
    foreach($demos as $username=>$role) {
        $row=$repository->findByLogin($username);
        if(!$row || $row['rol']!==$role || $auth->authenticate($username,$probe)!==null) {throw new RuntimeException();}
        echo "$username: cuenta existente, credencial de prueba rechazada; rol correcto.\n";
    }
    $stmt=$pdo->prepare('SELECT correo_acceso FROM public.usuarios WHERE nombre_usuario=:username');
    $stmt->execute(['username'=>'usuario1']);
    $email=$stmt->fetchColumn();
    if($repository->findByLogin($email)['nombre_usuario']!=='usuario1' || $auth->authenticate($email,$probe)!==null) {throw new RuntimeException();}
    echo "Búsqueda real por correo: correcta; credencial de prueba rechazada.\n";
    $inactive=$pdo->query("SELECT nombre_usuario FROM public.usuarios WHERE estado='inactivo' ORDER BY id_usuario LIMIT 1")->fetchColumn();
    if($inactive===false || $auth->authenticate($inactive,$probe)!==null) {throw new RuntimeException();}
    echo "Una cuenta real inactiva sigue sin poder autenticarse.\n";
    if($repository->findByLogin("' OR 1=1 --")!==null) {throw new RuntimeException();}
    foreach($demos as $username=>$role) {
        $row=$repository->findByLogin($username);
        if(count($repository->permissions((int)$row['id_rol']))===0) {throw new RuntimeException();}
    }
    $summary=$pdo->query("SELECT count(*) AS usuarios, count(*) FILTER (WHERE estado='activo') AS activos, count(contrasena_hash) AS con_hash FROM public.usuarios")->fetch();
    $counts=$pdo->query('SELECT (SELECT count(*) FROM public.pacientes) AS pacientes,(SELECT count(*) FROM public.medicos) AS medicos,(SELECT count(*) FROM public.citas) AS citas')->fetch();
    if($before!==$snapshot()) {throw new RuntimeException();}
    $pdo->rollBack();
    echo json_encode(['usuarios'=>$summary,'totales'=>$counts,'snapshot_usuarios_sin_cambios'=>true],JSON_PRETTY_PRINT),PHP_EOL;
    echo "Integración de autenticación: correcta, transacción de solo lectura revertida.\n";
} catch(Throwable $error) {
    if(isset($pdo)&&$pdo->inTransaction()) {$pdo->rollBack();}
    fwrite(STDERR,"Falló la integración de autenticación. Detalles omitidos para proteger datos.\n");
    exit(1);
}
