<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0'); ini_set('log_errors', '0');
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, "Diagnóstico local detenido: " . get_class($e) . "; código " . $e->getCode() . "; detalles privados omitidos.\n"); exit(1); });
require dirname(__DIR__) . '/backend/bootstrap.php';
$config = require dirname(__DIR__) . '/backend/config/database.php';
if (!in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true) || $config['database'] !== 'clinica_vida_salud') { throw new RuntimeException('Origen no autorizado.'); }
$pdo = Clinica\Repositories\Connection::readOnly($config);
$pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'clinica_vida_salud' || $pdo->query('SHOW transaction_read_only')->fetchColumn() !== 'on') { throw new RuntimeException('Origen no autorizado.'); }
$tables = ['especialidades','medicos','pacientes','medico_especialidad','horarios_atencion','citas','bloqueos_horario'];
$report = ['origen'=>'clinica_vida_salud', 'solo_lectura'=>true];
foreach ($tables as $table) { $report['conteos'][$table] = (int)$pdo->query('SELECT count(*) FROM public.' . $table)->fetchColumn(); }
$report['especialidades'] = $pdo->query('SELECT nombre, precio_consulta, estado FROM public.especialidades ORDER BY nombre')->fetchAll();
$report['citas'] = $pdo->query('SELECT estado, count(*) AS total, min(fecha) AS desde, max(fecha) AS hasta FROM public.citas GROUP BY estado ORDER BY estado')->fetchAll();
$report['fechas'] = $pdo->query("SELECT current_date AS hoy, count(*) FILTER (WHERE fecha + hora_inicio <= now() AT TIME ZONE 'America/La_Paz') AS pasadas, count(*) FILTER (WHERE fecha + hora_inicio > now() AT TIME ZONE 'America/La_Paz') AS futuras FROM public.citas")->fetch();
$report['cuentas_vinculadas'] = $pdo->query('SELECT (SELECT count(*) FROM public.medicos WHERE id_usuario IS NOT NULL) AS medicos, (SELECT count(*) FROM public.pacientes WHERE id_usuario IS NOT NULL) AS pacientes')->fetch();
$report['integridad'] = $pdo->query('SELECT (SELECT count(*) FROM public.citas c LEFT JOIN public.pacientes p USING(id_paciente) WHERE p.id_paciente IS NULL) AS pacientes_huerfanos, (SELECT count(*) FROM public.citas c LEFT JOIN public.medico_especialidad m USING(id_medico,id_especialidad) WHERE m.id_medico IS NULL) AS asociaciones_huerfanas')->fetch();
$report['columnas'] = $pdo->query("SELECT table_name,column_name,data_type,is_nullable,is_identity FROM information_schema.columns WHERE table_schema='public' AND table_name IN ('especialidades','medicos','pacientes','medico_especialidad','horarios_atencion','citas','bloqueos_horario') ORDER BY table_name,ordinal_position")->fetchAll();
$report['restricciones'] = $pdo->query("SELECT c.relname AS tabla,k.conname,pg_get_constraintdef(k.oid) AS definicion FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname IN ('especialidades','medicos','pacientes','medico_especialidad','horarios_atencion','citas') ORDER BY c.relname,k.conname")->fetchAll();
$report['triggers'] = $pdo->query("SELECT c.relname AS tabla,pg_get_triggerdef(t.oid) AS trigger,pg_get_functiondef(t.tgfoid) AS funcion FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname IN ('especialidades','medicos','pacientes','horarios_atencion','citas') AND NOT t.tgisinternal ORDER BY c.relname,t.tgname")->fetchAll();
$pdo->rollBack();
fwrite(STDOUT,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
