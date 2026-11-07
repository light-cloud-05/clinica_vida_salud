<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    require dirname(__DIR__).'/backend/bootstrap.php';
    $pdo=Clinica\Repositories\Connection::readOnly(require dirname(__DIR__).'/backend/config/database.php');
    $pdo->beginTransaction();
    $queries=[
        'columns'=>"SELECT table_name,column_name,data_type,is_nullable,column_default,character_maximum_length FROM information_schema.columns WHERE table_schema='public' AND table_name IN ('pacientes','usuarios') ORDER BY table_name,ordinal_position",
        'constraints'=>"SELECT c.relname AS tabla,pg_get_constraintdef(k.oid) AS definicion FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND (c.relname IN ('pacientes','usuarios') OR k.confrelid='public.pacientes'::regclass) ORDER BY c.relname,k.conname",
        'indexes'=>"SELECT indexdef FROM pg_indexes WHERE schemaname='public' AND tablename='pacientes'",
        'triggers'=>"SELECT pg_get_triggerdef(t.oid) AS trigger,pg_get_functiondef(t.tgfoid) AS funcion FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname='pacientes' AND NOT t.tgisinternal",
        'permissions'=>"SELECT r.nombre AS rol,p.nombre AS permiso FROM public.roles r JOIN public.rol_permiso rp ON rp.id_rol=r.id_rol JOIN public.permisos p ON p.id_permiso=rp.id_permiso WHERE p.nombre LIKE '%pacient%' ORDER BY r.nombre,p.nombre",
    ];
    foreach($queries as $label=>$sql) { echo json_encode([$label=>$pdo->query($sql)->fetchAll()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL; }
    $pdo->rollBack();
} catch(Throwable $e) { fwrite(STDERR,"No se pudo inspeccionar el esquema. Detalles omitidos.\n"); exit(1); }
