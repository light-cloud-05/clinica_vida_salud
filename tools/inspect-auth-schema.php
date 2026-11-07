<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    require dirname(__DIR__) . '/backend/bootstrap.php';
    $pdo = \Clinica\Repositories\Connection::readOnly(require dirname(__DIR__) . '/backend/config/database.php');
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT table_name, column_name, data_type, is_nullable, column_default, character_maximum_length FROM information_schema.columns WHERE table_schema = :schema AND table_name IN ('usuarios','roles','permisos','rol_permiso') ORDER BY table_name, ordinal_position");
    $stmt->execute(['schema' => 'public']);
    echo json_encode(['columns' => $stmt->fetchAll()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    $stmt = $pdo->prepare("SELECT c.relname AS table_name, pg_get_constraintdef(k.oid) AS definition FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=:schema AND c.relname IN ('usuarios','roles','permisos','rol_permiso') ORDER BY c.relname,k.conname");
    $stmt->execute(['schema' => 'public']);
    echo json_encode(['constraints' => $stmt->fetchAll()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    echo json_encode(['roles' => $pdo->query('SELECT id_rol,nombre FROM public.roles ORDER BY id_rol')->fetchAll(), 'permissions' => $pdo->query('SELECT r.nombre AS rol,p.nombre AS permiso FROM public.rol_permiso rp JOIN public.roles r ON r.id_rol=rp.id_rol JOIN public.permisos p ON p.id_permiso=rp.id_permiso ORDER BY r.id_rol,p.nombre')->fetchAll()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    $stmt = $pdo->prepare("SELECT u.nombre_usuario,u.estado,(u.contrasena_hash IS NOT NULL) AS has_password,r.nombre AS rol FROM public.usuarios u JOIN public.roles r ON r.id_rol=u.id_rol WHERE u.nombre_usuario IN (:a,:b,:c,:d) ORDER BY u.id_usuario");
    $stmt->execute(['a'=>'usuario1','b'=>'usuario2','c'=>'profesional01','d'=>'paciente01']);
    echo json_encode(['demo_accounts' => $stmt->fetchAll()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    $stmt = $pdo->prepare("SELECT pg_get_triggerdef(t.oid) AS definition,pg_get_functiondef(t.tgfoid) AS function_definition FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=:schema AND c.relname='usuarios' AND NOT t.tgisinternal");
    $stmt->execute(['schema'=>'public']);
    echo json_encode(['user_triggers' => $stmt->fetchAll()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    $pdo->rollBack();
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo inspeccionar el esquema. Detalles omitidos por seguridad.\n");
    exit(1);
}
