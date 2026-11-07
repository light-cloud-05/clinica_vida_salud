<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/backend/bootstrap.php';
try{
 $pdo=Clinica\Repositories\Connection::readOnly(require dirname(__DIR__).'/backend/config/database.php');
 $queries=[
 'tables'=>"SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name",
 'columns'=>"SELECT table_name,column_name,data_type,is_nullable,column_default,character_maximum_length FROM information_schema.columns WHERE table_schema='public' AND table_name IN ('pagos','reservas_provisionales','transacciones_pago','comprobantes_pago') ORDER BY table_name,ordinal_position",
 'constraints'=>"SELECT c.relname AS tabla,pg_get_constraintdef(k.oid) AS definicion FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname IN ('pagos','reservas_provisionales','transacciones_pago','comprobantes_pago') ORDER BY c.relname,k.conname",
 'functions'=>"SELECT pg_get_functiondef(p.oid) AS definicion FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND (p.proname='fn_vencimiento_pago_efectivo' OR p.proname ILIKE '%expir%')",
 'triggers'=>"SELECT c.relname AS tabla,pg_get_triggerdef(t.oid) AS trigger,pg_get_functiondef(t.tgfoid) AS funcion FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname IN ('pagos','reservas_provisionales','transacciones_pago','comprobantes_pago') AND NOT t.tgisinternal",
 'prices'=>"SELECT id_especialidad,nombre,precio_consulta FROM public.especialidades ORDER BY id_especialidad",
 ];
 foreach($queries as $name=>$sql){if(!isset($argv[1]) || $argv[1]===$name){echo json_encode([$name=>$pdo->query($sql)->fetchAll()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;}}
}catch(Throwable $e){fwrite(STDERR,"No se pudo inspeccionar pagos. Detalles omitidos.\n");exit(1);}
