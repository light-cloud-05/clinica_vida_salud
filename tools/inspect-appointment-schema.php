<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/backend/bootstrap.php';
try{
    $pdo=Clinica\Repositories\Connection::readOnly(require dirname(__DIR__).'/backend/config/database.php');$pdo->beginTransaction();
    $queries=[
        'procedure'=>"SELECT pg_get_functiondef(p.oid) AS definicion FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.proname='cancelar_cita' AND p.prokind='p'",
        'columns'=>"SELECT table_name,column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' AND table_name IN ('citas','pacientes','medicos','especialidades','medico_especialidad','horarios_atencion','bloqueos_horario','pagos','reservas_provisionales','transacciones_pago') ORDER BY table_name,ordinal_position",
        'constraints'=>"SELECT c.relname AS tabla,pg_get_constraintdef(k.oid) AS definicion FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname IN ('citas','pagos','reservas_provisionales') ORDER BY c.relname,k.conname",
        'indexes'=>"SELECT tablename,indexdef FROM pg_indexes WHERE schemaname='public' AND tablename IN ('citas','pagos','reservas_provisionales')",
        'triggers'=>"SELECT c.relname AS tabla,pg_get_triggerdef(t.oid) AS trigger,pg_get_functiondef(t.tgfoid) AS funcion FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname IN ('citas','pagos','reservas_provisionales') AND NOT t.tgisinternal",
        'permissions'=>"SELECT r.nombre AS rol,p.nombre AS permiso FROM public.roles r JOIN public.rol_permiso rp ON rp.id_rol=r.id_rol JOIN public.permisos p ON p.id_permiso=rp.id_permiso ORDER BY r.nombre,p.nombre",
        'counts'=>"SELECT estado,count(*) AS total FROM public.citas GROUP BY estado ORDER BY estado",
        'future_counts'=>"SELECT count(*) AS futuras FROM public.citas WHERE fecha+hora_inicio>(clock_timestamp() AT TIME ZONE 'America/La_Paz') AND estado IN ('programada','confirmada')",
        'payment_counts'=>"SELECT (SELECT count(*) FROM public.pagos) AS pagos,(SELECT count(*) FROM public.reservas_provisionales) AS reservas",
    ];
    foreach($queries as $label=>$sql){if(isset($argv[1]) && $argv[1]!==$label){continue;}echo json_encode([$label=>$pdo->query($sql)->fetchAll()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;}$pdo->rollBack();
}catch(Throwable $e){fwrite(STDERR,"Inspección de citas no disponible. Detalles omitidos.\n");exit(1);}
