<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try {
    require dirname(__DIR__).'/backend/bootstrap.php';
    $pdo=Clinica\Repositories\Connection::readOnly(require dirname(__DIR__).'/backend/config/database.php');
    $pdo->beginTransaction();
    $queries=[
        'columns'=>"SELECT table_name,column_name,data_type,is_nullable,column_default,character_maximum_length,numeric_precision,numeric_scale,is_identity FROM information_schema.columns WHERE table_schema='public' AND table_name IN ('medicos','especialidades','medico_especialidad') ORDER BY table_name,ordinal_position",
        'constraints'=>"SELECT c.relname AS tabla,pg_get_constraintdef(k.oid) AS definicion FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND (c.relname IN ('medicos','especialidades','medico_especialidad') OR k.confrelid IN ('public.medicos'::regclass,'public.especialidades'::regclass,'public.medico_especialidad'::regclass)) ORDER BY c.relname,k.conname",
        'indexes'=>"SELECT tablename,indexdef FROM pg_indexes WHERE schemaname='public' AND tablename IN ('medicos','especialidades','medico_especialidad')",
        'triggers'=>"SELECT c.relname AS tabla,pg_get_triggerdef(t.oid) AS trigger,pg_get_functiondef(t.tgfoid) AS funcion FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname IN ('medicos','especialidades','medico_especialidad') AND NOT t.tgisinternal",
        'permissions'=>"SELECT r.nombre AS rol,p.nombre AS permiso FROM public.roles r JOIN public.rol_permiso rp ON rp.id_rol=r.id_rol JOIN public.permisos p ON p.id_permiso=rp.id_permiso WHERE p.nombre LIKE '%medic%' OR p.nombre LIKE '%especial%' ORDER BY r.nombre,p.nombre",
        'counts'=>"SELECT (SELECT count(*) FROM public.medicos) AS medicos,(SELECT count(*) FROM public.especialidades) AS especialidades,(SELECT count(*) FROM public.medico_especialidad) AS asociaciones",
        'all_permission_names'=>"SELECT nombre FROM public.permisos ORDER BY nombre",
    ];
    foreach($queries as $label=>$sql){echo json_encode([$label=>$pdo->query($sql)->fetchAll()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;}
    $pdo->rollBack();
}catch(Throwable $e){fwrite(STDERR,"No se pudo inspeccionar el esquema médico. Detalles omitidos.\n");exit(1);}
