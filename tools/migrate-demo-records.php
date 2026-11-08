<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0');
require __DIR__.'/migration-diagnostics.php';
require __DIR__.'/migration-constraints.php';
$sourcePdo=null; $targetPdo=null; $phase='BOOTSTRAP';
set_exception_handler(static function (Throwable $e) use (&$sourcePdo,&$targetPdo,&$phase): void {
    foreach ([$targetPdo,$sourcePdo] as $pdo) {
        try { if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable) { /* Disconnect also rolls back. */ }
    }
    $event=MigrationDiagnostics::event($e,$phase);
    fwrite(STDERR,'DETENIDO ['.$event['stage'].']: '.$event['reason'].PHP_EOL);
    fwrite(STDERR,'MIGRATION_ERROR '.json_encode($event,JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
});
$phase='PHP_RUNTIME';
if ($argc===2 && $argv[1]==='--check-runtime') {
    echo json_encode(MigrationDiagnostics::runtime(),JSON_THROW_ON_ERROR).PHP_EOL; exit;
}
if (!extension_loaded('mbstring')) { MigrationDiagnostics::fail('MBSTRING_REQUIRED'); }
if (!extension_loaded('pdo_pgsql')) { MigrationDiagnostics::fail('PDO_PGSQL_REQUIRED'); }
require dirname(__DIR__).'/backend/bootstrap.php';
require __DIR__.'/demo-migration-plan.php';

function migrationRead(PDO $pdo): array
{
    $data=[];
    foreach (DemoMigrationPlan::KEYS as $table=>$key) {
        $columns=DemoMigrationPlan::FIELDS[$table];
        if ($key!==null) { array_unshift($columns,$key); }
        if (in_array($table,['medicos','pacientes'],true)) { $columns[]='id_usuario'; }
        $data[$table]=$pdo->query('SELECT '.implode(',',$columns).' FROM public.'.$table.' ORDER BY '.($key ?? 'id_medico,id_especialidad'))->fetchAll();
    }
    $data['bloqueos_horario']=$pdo->query('SELECT id_medico,fecha,hora_inicio,hora_fin FROM public.bloqueos_horario ORDER BY id_bloqueo')->fetchAll();
    $data['reservas_activas']=$pdo->query("SELECT c.id_medico,c.id_paciente,c.fecha,c.hora_inicio,c.hora_fin FROM public.reservas_provisionales r JOIN public.citas c ON c.id_cita=r.id_cita WHERE r.estado='pendiente' AND r.fecha_vencimiento>now() ORDER BY r.id_reserva")->fetchAll();
    return $data;
}

function migrationSchema(PDO $pdo): array
{
    $result = [];

    $result[] = $pdo->query("
        SELECT table_name,column_name,data_type,is_nullable,
               column_default,is_identity,character_maximum_length
        FROM information_schema.columns
        WHERE table_schema='public'
        AND table_name IN (
            'especialidades','medicos','pacientes',
            'medico_especialidad','horarios_atencion',
            'citas','bloqueos_horario','reservas_provisionales'
        )
        ORDER BY table_name,ordinal_position
    ")->fetchAll(PDO::FETCH_ASSOC);

    $constraints = $pdo->query("
        SELECT
            c.relname AS table_name,
            k.conname,
            k.contype,
            k.convalidated,
            k.condeferrable,
            k.condeferred,
            k.connoinherit,
            COALESCE((SELECT json_agg(a.attname ORDER BY u.ord)::text FROM unnest(k.conkey) WITH ORDINALITY u(num,ord) JOIN pg_attribute a ON a.attrelid=k.conrelid AND a.attnum=u.num),'[]') AS column_names,
            pg_get_constraintdef(k.oid) AS definition
        FROM pg_constraint k
        JOIN pg_class c ON c.oid=k.conrelid
        JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public'
        AND c.relname IN (
            'especialidades','medicos','pacientes',
            'medico_especialidad','horarios_atencion',
            'citas','bloqueos_horario','reservas_provisionales'
        )
        AND k.contype <> 'n'
        ORDER BY c.relname,k.conname
    ")->fetchAll(PDO::FETCH_ASSOC);

    $constraints = array_map([MigrationConstraintComparison::class,'normalize'], $constraints);

    $result[] = $constraints;

    $result[] = $pdo->query("
        SELECT
            c.relname,
            t.tgname,
            t.tgenabled,
            pg_get_triggerdef(t.oid) AS definition,
            pg_get_functiondef(t.tgfoid) AS body
        FROM pg_trigger t
        JOIN pg_class c ON c.oid=t.tgrelid
        JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public'
        AND c.relname IN (
            'especialidades','medicos','pacientes',
            'horarios_atencion','citas'
        )
        AND NOT t.tgisinternal
        ORDER BY c.relname,t.tgname
    ")->fetchAll(PDO::FETCH_ASSOC);

    return array_map(
        static fn(array $rows): string =>
            hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
        array_combine(
            ['columns','constraints','triggers'],
            $result
        )
    );
}

function migrationDigests(PDO $pdo): array
{
    $digests=[];
    foreach (DemoMigrationPlan::KEYS+['usuarios'=>'id_usuario'] as $table=>$key) {
        // Hash computed by PostgreSQL. No password/hash/credential column leaves the server.
        $order=$key===null ? 'id_medico,id_especialidad' : $key;
        $digests[$table]=$pdo->query('SELECT '.($key===null ? "id_medico::text || ':' || id_especialidad::text" : $key.'::text')." AS key,md5(to_jsonb(t)::text) AS digest FROM public.".$table.' t ORDER BY '.$order)->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $digests;
}
function migrationSeedBaseline(): array
{
    $data=array_fill_keys(array_keys(DemoMigrationPlan::KEYS),[]);
    $data['especialidades']=[['id_especialidad'=>1,'nombre'=>'Medicina familiar','descripcion'=>'Atención médica general de demostración.','precio_consulta'=>'120.00','estado'=>'activo'],['id_especialidad'=>2,'nombre'=>'Pediatría','descripcion'=>'Atención pediátrica ficticia de demostración.','precio_consulta'=>'150.00','estado'=>'activo']];
    foreach ([1,2] as $id) {
        $data['medicos'][]=['id_medico'=>$id,'codigo_medico'=>'DEMO-MED-00'.$id,'registro_profesional'=>'DEMO-REG-00'.$id,'nombres'=>$id===1?'Alex':'Sam','apellidos'=>$id===1?'Montes':'Rojas','estado'=>'activo','id_usuario'=>$id===1?3:null];
        $data['pacientes'][]=['id_paciente'=>$id,'codigo_paciente'=>'DEMO-PAC-00'.$id,'documento_identidad'=>'DEMO-DOC-00'.$id,'nombres'=>$id===1?'Taylor':'Jordan','apellidos'=>$id===1?'Vega':'Paz','fecha_nacimiento'=>$id===1?'1990-04-12':'1988-09-21','sexo'=>'no especificado','estado'=>'activo','id_usuario'=>$id===1?4:null];
        $data['medico_especialidad'][]=['id_medico'=>$id,'id_especialidad'=>$id];
        foreach ([1=>'08:00:00',3=>'14:00:00'] as $day=>$start) { $data['horarios_atencion'][]=['id_horario'=>count($data['horarios_atencion'])+1,'id_medico'=>$id,'dia_semana'=>$day,'hora_inicio'=>$start,'hora_fin'=>$day===1?'12:00:00':'18:00:00','duracion_cita_minutos'=>30,'estado'=>'activo']; }
    }
    $data['bloqueos_horario']=[]; $data['reservas_activas']=[]; return $data;
}

$options=getopt('', ['simulate-seed-baseline','approve-plan:']);
foreach (array_slice($argv,1) as $argument) { if (!preg_match('/^--(?:simulate-seed-baseline|approve-plan=[a-f0-9]{64})$/D',$argument)) { throw new MigrationDiagnosticFailure('ARGUMENT_INVALID'); } }
$apply=isset($options['approve-plan']); $baseline=isset($options['simulate-seed-baseline']);
if ($baseline && $apply) { throw new MigrationDiagnosticFailure('BASELINE_APPLY_FORBIDDEN'); }
$phase='SOURCE_CONFIG';
// DATABASE_URL is never a source setting. Read local DB_* without changing .env.
$config=[
    'host'=>Clinica\Support\Environment::get('DB_HOST','127.0.0.1'),
    'port'=>Clinica\Support\Environment::get('DB_PORT','5432'),
    'database'=>Clinica\Support\Environment::get('DB_DATABASE','clinica_vida_salud'),
    'username'=>Clinica\Support\Environment::get('DB_USERNAME'),
    'password'=>Clinica\Support\Environment::get('DB_PASSWORD'),
    'sslmode'=>Clinica\Support\Environment::get('DB_SSLMODE','prefer'),
];
if (!in_array($config['host'],['127.0.0.1','localhost','::1'],true) || $config['database']!=='clinica_vida_salud') { throw new MigrationDiagnosticFailure('SOURCE_CONFIG_INVALID'); }
$phase='SOURCE_CONNECT';
$sourcePdo=Clinica\Repositories\Connection::readOnly($config);
$sourcePdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
$phase='SOURCE_IDENTITY';
if ($sourcePdo->query('SELECT current_database()')->fetchColumn()!=='clinica_vida_salud' || $sourcePdo->query('SHOW transaction_read_only')->fetchColumn()!=='on') { MigrationDiagnostics::fail('SOURCE_IDENTITY_INVALID'); }
$phase='SOURCE_READ';
$source=migrationRead($sourcePdo);
$phase='SOURCE_SCHEMA';
$schema=migrationSchema($sourcePdo);
$phase='SOURCE_COUNTS';
foreach (['especialidades'=>9,'medicos'=>18,'pacientes'=>30,'medico_especialidad'=>18,'horarios_atencion'=>90,'citas'=>60] as $table=>$expected) {
    if (count($source[$table])!==$expected) { throw new MigrationDiagnosticFailure('SOURCE_COUNTS_CHANGED'); }
}
// Only operational fields. Never read local users, accounts, password hashes, mail or clinical notes.
foreach (['medicos','pacientes'] as $table) { foreach ($source[$table] as &$row) { unset($row['id_usuario']); } unset($row); }
if ($source['bloqueos_horario']!==[] || $source['reservas_activas']!==[]) { throw new MigrationDiagnosticFailure('SOURCE_BLOCKS_OR_RESERVATIONS'); }
$sourcePdo->rollBack();
$now=new DateTimeImmutable('now',new DateTimeZone('America/La_Paz')); $digests=[];
if ($baseline) {
    $target=migrationSeedBaseline();
} else {
$phase='TARGET_URL';
    $url=getenv('MIGRATION_RENDER_URL'); $host=getenv('MIGRATION_EXPECTED_HOST');
    $u=is_string($url)?parse_url($url):false;
    if (!$u || !in_array($u['scheme']??'',['postgres','postgresql'],true) || !is_string($host) || !preg_match('/^dpg-[a-z0-9-]+\.[a-z0-9-]+-postgres\.render\.com$/D',$host)
        || ($u['host']??'')!==$host || rawurldecode(ltrim($u['path']??'','/'))!=='clinica_vida_salud_db' || ($u['port']??5432)!==5432 || empty($u['user']) || empty($u['pass']) || isset($u['fragment']) || !in_array($u['query']??'',['','sslmode=require','sslmode=verify-full','sslmode=verify-ca'],true)) { throw new MigrationDiagnosticFailure('TARGET_URL_INVALID'); }
    if ($apply && (getenv('MIGRATION_CONFIRM')!=='IMPORTAR_FICTICIOS_EN_clinica-vida-salud-db' || !preg_match('/^[a-f0-9]{64}$/D',(string)$options['approve-plan']))) { throw new MigrationDiagnosticFailure('TARGET_CONFIRMATION_REQUIRED'); }
    $ssl='require'; if (isset($u['query'])) { parse_str($u['query'],$q); $ssl=$q['sslmode']; }
    $targetConfig=['host'=>$host,'port'=>'5432','database'=>'clinica_vida_salud_db','username'=>rawurldecode($u['user']),'password'=>rawurldecode($u['pass']),'sslmode'=>$ssl];
    if (!extension_loaded('pgsql') || !defined('PGSQL_LIBPQ_VERSION') || (int)PGSQL_LIBPQ_VERSION<14) { throw new MigrationDiagnosticFailure('PGSQL_SNI_REQUIRED'); }
    $phase='TARGET_CONNECT';
    $targetPdo=$apply?Clinica\Repositories\Connection::accountMaintenance($targetConfig):Clinica\Repositories\Connection::readOnly($targetConfig);
    $targetPdo->exec($apply?'BEGIN ISOLATION LEVEL SERIALIZABLE':'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
    $targetPdo->exec("SET LOCAL lock_timeout='5s'");
    if ($apply) { $targetPdo->exec('LOCK TABLE public.usuarios,public.especialidades,public.medicos,public.pacientes,public.medico_especialidad,public.horarios_atencion,public.citas,public.bloqueos_horario,public.reservas_provisionales IN SHARE ROW EXCLUSIVE MODE'); }
    $phase='TARGET_IDENTITY';
    $identity=$targetPdo->query("SELECT current_database() AS db,current_setting('server_version_num')::int AS version,(SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls,now() AS clock")->fetch();
    if (!$identity || $identity['db']!=='clinica_vida_salud_db' || (int)$identity['version']<180000 || (int)$identity['version']>=190000 || !in_array($identity['tls'],[true,'t',1,'1'],true)) { throw new MigrationDiagnosticFailure('TARGET_IDENTITY_INVALID'); }
    $now=new DateTimeImmutable($identity['clock']);
    $phase='TARGET_SCHEMA';
    $tables=(int)$targetPdo->query("SELECT count(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn();
    if ($tables!==21) { MigrationDiagnostics::fail('TARGET_TABLE_COUNT_INVALID'); }
    $targetSchema=migrationSchema($targetPdo);
    foreach (['columns'=>'SCHEMA_COLUMNS_DIFFER','constraints'=>'SCHEMA_CONSTRAINTS_DIFFER','triggers'=>'SCHEMA_TRIGGERS_DIFFER'] as $component=>$reason) {
        if ($targetSchema[$component]!==$schema[$component]) { MigrationDiagnostics::fail($reason); }
    }
    $phase='TARGET_ACCOUNTS';
    $users=$targetPdo->query('SELECT nombre_usuario FROM public.usuarios ORDER BY nombre_usuario')->fetchAll(PDO::FETCH_COLUMN);
    if ($users!==['demo_admin','demo_medico','demo_paciente','demo_recepcion']) { throw new MigrationDiagnosticFailure('TARGET_ACCOUNTS_INVALID'); }
    $phase='TARGET_READ';
    $target=migrationRead($targetPdo); $digests=migrationDigests($targetPdo);
    $phase='TARGET_SEQUENCES';
    foreach (DemoMigrationPlan::KEYS+['registros_auditoria'=>'id_registro'] as $table=>$key) {
        if ($key===null) { continue; }
        $stmt=$targetPdo->prepare("SELECT pg_get_serial_sequence(:table,:column)"); $stmt->execute(['table'=>'public.'.$table,'column'=>$key]); $seq=$stmt->fetchColumn();
        if (!is_string($seq) || !preg_match('/^public\.[a-z_]+$/D',$seq)) { throw new MigrationDiagnosticFailure('SEQUENCE_UNKNOWN'); }
        $state=$targetPdo->query('SELECT last_value,is_called FROM '.$seq)->fetch();
        $max=(int)$targetPdo->query('SELECT COALESCE(max('.$key.'),0) FROM public.'.$table)->fetchColumn();
        $next=(int)$state['last_value']+(in_array($state['is_called'],[true,'t',1,'1'],true)?1:0);
        if ($next<=$max) { throw new MigrationDiagnosticFailure('SEQUENCE_STALE'); }
    }
}
$phase='PLAN_BUILD';
$plan=DemoMigrationPlan::build($source,$target,$now);
$fingerprint=hash('sha256',json_encode([$schema,$source,$target,$digests,$plan],JSON_THROW_ON_ERROR));
echo json_encode(['modo'=>$baseline?'SIMULACION_CON_BASELINE_DEL_SEED_NO_RENDER_REAL':($apply?'APLICACION_APROBADA':'SIMULACION_RENDER_SOLO_LECTURA'),'plan_sha256'=>$fingerprint,'resumen'=>$plan['report']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
if (!$apply) { if ($targetPdo instanceof PDO) { $targetPdo->rollBack(); } exit; }
$phase='PLAN_APPROVAL';
if (!hash_equals($fingerprint,(string)$options['approve-plan'])) { throw new MigrationDiagnosticFailure('PLAN_CHANGED'); }
$phase='WRITE'; $real=[];
foreach ($plan['operations'] as $operation) {
    $row=$operation['row']; $table=$operation['table']; $key=DemoMigrationPlan::KEYS[$table];
    foreach (['id_medico','id_paciente','id_especialidad'] as $fk) { if (isset($row[$fk]) && (int)$row[$fk]<0) { $row[$fk]=$real[(int)$row[$fk]] ?? throw new MigrationDiagnosticFailure('MAPPING_INCOMPLETE'); } }
    $columns=array_keys($row);
    $sql='INSERT INTO public.'.$table.' ('.implode(',',$columns).') VALUES (:'.implode(',:',$columns).')'.($key===null?'':' RETURNING '.$key);
    $stmt=$targetPdo->prepare($sql); $stmt->execute($row);
    if ($key!==null) { $real[$operation['virtual']]=(int)$stmt->fetchColumn(); }
}
$phase='VERIFY';
$after=migrationRead($targetPdo); $afterDigests=migrationDigests($targetPdo);
foreach ($digests as $table=>$rows) {
    foreach ($rows as $id=>$digest) { if (($afterDigests[$table][$id]??null)!==$digest) { throw new MigrationDiagnosticFailure('EXISTING_ROW_CHANGED'); } }
    if ($table==='usuarios' && $afterDigests[$table]!==$rows) { throw new MigrationDiagnosticFailure('EXISTING_ROW_CHANGED'); }
}
foreach ($plan['report']['despues'] as $table=>$expected) { if (count($after[$table])!==$expected) { throw new MigrationDiagnosticFailure('COUNTS_INVALID'); } }
if (DemoMigrationPlan::build($source,$after,$now)['operations']!==[]) { throw new MigrationDiagnosticFailure('IDEMPOTENCE_FAILED'); }
$orphans=(int)$targetPdo->query('SELECT count(*) FROM public.citas c LEFT JOIN public.pacientes p USING(id_paciente) LEFT JOIN public.medico_especialidad m USING(id_medico,id_especialidad) WHERE p.id_paciente IS NULL OR m.id_medico IS NULL')->fetchColumn();
if ($orphans!==0) { throw new MigrationDiagnosticFailure('ORPHANS_FOUND'); }
$targetPdo->commit();
fwrite(STDOUT,"COMMIT completado: conteos, relaciones, idempotencia y conservación de cuentas/registros previos verificados.\n");
