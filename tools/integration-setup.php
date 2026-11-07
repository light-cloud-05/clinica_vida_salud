<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\Connection;

function integrationSnapshot(PDO $pdo):array {
    $out=['tables'=>[],'sequences'=>[],'schema'=>[]];
    foreach($pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN) as $table){
        $quoted='public."'.str_replace('"','""',$table).'"';
        $out['tables'][$table]=hash('sha256',(string)$pdo->query("SELECT COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text COLLATE \"C\")::text,'[]') FROM $quoted t")->fetchColumn());
    }
    foreach($pdo->query("SELECT sequencename FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename")->fetchAll(PDO::FETCH_COLUMN) as $seq){
        $out['sequences'][$seq]=$pdo->query('SELECT last_value,is_called FROM public."'.str_replace('"','""',$seq).'"')->fetch();
    }
    $queries=[
        'columns'=>"SELECT table_name,column_name,data_type,is_nullable,column_default,is_identity,identity_generation FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position",
        'constraints'=>"SELECT c.relname,k.conname,pg_get_constraintdef(k.oid) AS definition FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' ORDER BY c.relname,k.conname",
        'triggers'=>"SELECT c.relname,t.tgname,t.tgenabled,pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND NOT t.tgisinternal ORDER BY c.relname,t.tgname",
        'functions'=>"SELECT p.proname,pg_get_functiondef(p.oid) AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.prokind IN ('f','p') ORDER BY p.proname,pg_get_function_identity_arguments(p.oid)",
        'indexes'=>"SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='public' ORDER BY tablename,indexname",
    ];
    foreach($queries as $key=>$sql){$out['schema'][$key]=$pdo->query($sql)->fetchAll();}
    return $out;
}
function integrationProcess(array $command,array $env,string $log):void {
    $p=proc_open($command,[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,null,array_merge(getenv(),$env),['bypass_shell'=>true]);
    if(!is_resource($p)){throw new RuntimeException('No se pudo iniciar la herramienta PostgreSQL.');}fclose($pipes[0]);
    if(proc_close($p)!==0){throw new RuntimeException('La herramienta PostgreSQL falló. Revise el registro privado de integración.');}
}
if(realpath($_SERVER['SCRIPT_FILENAME'] ?? '')!==__FILE__){return;}
try{
    $dir=dirname(__DIR__).'/var/integration';$mode=$argv[1] ?? 'inspect';
    $config=require dirname(__DIR__).'/backend/config/database.php';
    if($config['database']!=='clinica_vida_salud'){throw new RuntimeException('La inspección original exige su nombre exacto.');}
    $source=Connection::readOnly($config);
    $identity=$source->query("SELECT current_database() AS database,current_setting('server_version') AS version,inet_server_port() AS port,(SELECT system_identifier::text FROM pg_control_system()) AS system_id")->fetch();
    if($identity['database']!=='clinica_vida_salud'){throw new RuntimeException('Origen inesperado.');}
    if(!is_dir($dir)){mkdir($dir,0700,true);}
    $snapshot=integrationSnapshot($source);
    $counts=$source->query('SELECT (SELECT count(*) FROM public.citas) citas,(SELECT count(*) FROM public.pacientes) pacientes,(SELECT count(*) FROM public.medicos) medicos,(SELECT count(*) FROM public.horarios_atencion) horarios,(SELECT count(*) FROM public.especialidades) especialidades')->fetch();
    if($mode==='verify-original'){
        $before=json_decode(file_get_contents($dir.'/original-snapshot.json'),true,512,JSON_THROW_ON_ERROR);
        if($before!==$snapshot || trim(file_get_contents($dir.'/env.sha256'))!==hash_file('sha256',dirname(__DIR__).'/.env')){throw new RuntimeException('Cambió la huella original; no se restauraron datos.');}
        echo 'ORIGINAL INTACTA: tablas, secuencias, esquema y .env idénticos. '.json_encode($counts).PHP_EOL;exit;
    }
    if($mode!=='create'){echo json_encode(['server'=>$identity,'counts'=>$counts,'tables'=>count($snapshot['tables']),'objects'=>array_map('count',$snapshot['schema'])],JSON_PRETTY_PRINT).PHP_EOL;exit;}
    $cluster=$dir.'/cluster-final';
    if(is_file($dir.'/test.env') || is_dir($cluster)){throw new RuntimeException('Ya existe el entorno aislado; no se sobrescribirá.');}
    file_put_contents($dir.'/original-snapshot.json',json_encode($snapshot,JSON_THROW_ON_ERROR));
    file_put_contents($dir.'/env.sha256',hash_file('sha256',dirname(__DIR__).'/.env'));
    $bin='C:/Program Files/PostgreSQL/18/bin/';$log=$dir.'/setup.log';
    integrationProcess([$bin.'pg_dump.exe','-h',$config['host'],'-p',$config['port'],'-U',$config['username'],'-d','clinica_vida_salud','-Fc','--no-owner','--no-acl','--file='.$dir.'/original.dump'],['PGPASSWORD'=>$config['password'],'PGOPTIONS'=>'-c default_transaction_read_only=on'],$log);
    $password=bin2hex(random_bytes(24));$pw=$dir.'/init-password';file_put_contents($pw,$password);
    file_put_contents($dir.'/test-password',$password);
    integrationProcess([$bin.'initdb.exe','-D',$cluster,'-U','cvs_test_owner','--encoding=UTF8','--locale=C','--auth=scram-sha-256','--pwfile='.$pw],[],$log);unlink($pw);
    integrationProcess([$bin.'pg_ctl.exe','-D',$cluster,'-l',$dir.'/postgres-final.log','-o','-p 55432 -h 127.0.0.1','-w','start'],[],$log);
    $admin=new PDO('pgsql:host=127.0.0.1;port=55432;dbname=postgres','cvs_test_owner',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $testSystem=(string)$admin->query('SELECT system_identifier::text FROM pg_control_system()')->fetchColumn();
    if($testSystem===$identity['system_id'] || (int)$admin->query('SELECT inet_server_port()')->fetchColumn()!==55432){throw new RuntimeException('No se garantiza un clúster separado.');}
    if($admin->query("SELECT count(*) FROM pg_database WHERE datname IN ('clinica_vida_salud','clinica_vida_salud_test')")->fetchColumn()!=0){throw new RuntimeException('Destino no vacío; no se modificará.');}
    $admin->exec("CREATE DATABASE clinica_vida_salud_test TEMPLATE template0 ENCODING 'UTF8'");
    integrationProcess([$bin.'pg_restore.exe','-h','127.0.0.1','-p','55432','-U','cvs_test_owner','-d','clinica_vida_salud_test','--no-owner','--no-acl','--single-transaction','--exit-on-error',$dir.'/original.dump'],['PGPASSWORD'=>$password],$log);
    $testConfig=$config;$testConfig['host']='127.0.0.1';$testConfig['port']='55432';$testConfig['database']='clinica_vida_salud_test';$testConfig['username']='cvs_test_owner';$testConfig['password']=$password;$testConfig['sslmode']='disable';
    $test=Connection::readOnly($testConfig);$restored=integrationSnapshot($test);
    $normalize=static function(array $s):array{foreach($s['schema']['constraints'] as &$c){$c['definition']=preg_replace('/[()\s]+/','',str_replace(['::character varying','::text[]','::text'],'',$c['definition']));}unset($c);return $s;};
    if($normalize($restored)!==$normalize($snapshot)){file_put_contents($dir.'/restored-snapshot.json',json_encode($restored));throw new RuntimeException('La copia difiere del origen. No se habilitarán escrituras.');}
    $marker='CVS_TEST_'.bin2hex(random_bytes(16));
    $admin->exec("COMMENT ON DATABASE clinica_vida_salud_test IS ".$admin->quote($marker));
    $settings=['APP_ENV'=>'integration','DB_HOST'=>'127.0.0.1','DB_PORT'=>'55432','DB_DATABASE'=>'clinica_vida_salud_test','DB_USERNAME'=>'cvs_test_owner','DB_PASSWORD'=>$password,'DB_SSLMODE'=>'disable','INTEGRATION_SYSTEM_ID'=>$testSystem,'INTEGRATION_MARKER'=>$marker,'INTEGRATION_ORIGINAL_MAX_CITA'=>(string)$source->query('SELECT max(id_cita) FROM public.citas')->fetchColumn(),'APPOINTMENT_WRITES_ENABLED'=>'true','APPOINTMENT_CHANGES_ENABLED'=>'true','PAYMENT_WRITES_ENABLED'=>'true','PAYMENT_ONLINE_HOLD_MINUTES'=>'30','PATIENT_WRITES_ENABLED'=>'false','MEDICAL_WRITES_ENABLED'=>'false','SCHEDULE_WRITES_ENABLED'=>'false','PAYMENT_SANDBOX_ENABLED'=>'false'];
    $text='';foreach($settings as $key=>$value){$text.=$key.'='.$value.PHP_EOL;}file_put_contents($dir.'/test.env',$text);
    echo 'COPIA VERIFICADA: clúster separado 127.0.0.1:55432, clinica_vida_salud_test; '.count($snapshot['tables']).' tablas, datos y secuencias idénticos; definiciones estructurales equivalentes tras normalizar casts de CHECK. Configuración privada en var/integration/test.env.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,($e instanceof PDOException ? 'Error PostgreSQL durante preparación; detalles omitidos.' : $e->getMessage()).PHP_EOL);exit(1);}
