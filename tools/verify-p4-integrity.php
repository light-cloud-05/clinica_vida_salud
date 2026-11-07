<?php
declare(strict_types=1);
// Verificación CLI de solo lectura. Conserva huellas privadas en var/, nunca valores de filas.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Repositories\Connection;
use Clinica\Support\Environment;
try{
    foreach(['PATIENT_WRITES_ENABLED','MEDICAL_WRITES_ENABLED','SCHEDULE_WRITES_ENABLED','APPOINTMENT_WRITES_ENABLED','APPOINTMENT_CHANGES_ENABLED','PAYMENT_WRITES_ENABLED'] as $flag){
        if(Environment::get($flag)==='true'){throw new RuntimeException('Hay un indicador de escritura habilitado. No se ejecutará la auditoría de cierre.');}
    }
    $mode=$argv[1] ?? '';
    $label=$argv[2] ?? 'p4';
    if(!in_array($argc,[2,3],true) || !in_array($mode,['baseline','verify'],true) || !preg_match('/^[a-z0-9-]{1,30}$/D',$label)){throw new RuntimeException('Uso: php tools/verify-p4-integrity.php baseline|verify [etapa]');}
    $path=dirname(__DIR__).'/var/'.$label.'-integrity.json';
    if($mode==='baseline' && is_file($path)){throw new RuntimeException('Ya existe una línea base privada. No se sobrescribirá.');}
    $pdo=Connection::readOnly(require dirname(__DIR__).'/backend/config/database.php');$pdo->beginTransaction();
    if($pdo->query('SELECT current_database()')->fetchColumn()!=='clinica_vida_salud'){throw new RuntimeException('Base no autorizada.');}
    $quote=static fn(string $name): string=>'public."'.str_replace('"','""',$name).'"';
    $tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    $snapshot=['tables'=>[],'sequences'=>[],'env_fingerprint'=>is_file(dirname(__DIR__).'/.env') ? hash_file('sha256',dirname(__DIR__).'/.env') : null];
    foreach($tables as $table){$snapshot['tables'][$table]=hash('sha256',(string)$pdo->query("SELECT COALESCE(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text)::text,'[]') FROM ".$quote($table).' t')->fetchColumn());}
    $sequences=$pdo->query("SELECT sequencename FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename")->fetchAll(PDO::FETCH_COLUMN);
    foreach($sequences as $sequence){$snapshot['sequences'][$sequence]=hash('sha256',json_encode($pdo->query('SELECT last_value,is_called FROM '.$quote($sequence))->fetch(),JSON_THROW_ON_ERROR));}
    $counts=$pdo->query('SELECT (SELECT count(*) FROM public.horarios_atencion) AS horarios,(SELECT count(*) FROM public.bloqueos_horario) AS bloqueos,(SELECT count(*) FROM public.citas) AS citas,(SELECT count(*) FROM public.pacientes) AS pacientes')->fetch();
    $pdo->rollBack();
    if($mode==='baseline'){
        if(!is_dir(dirname($path))){mkdir(dirname($path),0700,true);}
        $file=fopen($path,'x');if(!$file){throw new RuntimeException('No se pudo guardar la línea base.');}
        try{$json=json_encode($snapshot,JSON_THROW_ON_ERROR);if(fwrite($file,$json)!==strlen($json)){throw new RuntimeException('Línea base incompleta.');}}finally{fclose($file);}
        echo 'Línea base privada creada: '.count($tables).' tablas y '.count($sequences).' secuencias. Los indicadores de escritura están deshabilitados.'.PHP_EOL;
    }else{
        if(!is_file($path)){throw new RuntimeException('Falta la línea base privada.');}
        $before=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if($before!==$snapshot){throw new RuntimeException('Se detectaron diferencias respecto de la línea base. No se restauraron ni modificaron datos.');}
        echo 'INTEGRIDAD CORRECTA: '.count($tables).' tablas (incluida auditoría), '.count($sequences).' secuencias (last_value e is_called) y .env sin cambios durante esta revisión.'.PHP_EOL;
        echo json_encode($counts,JSON_UNESCAPED_UNICODE),PHP_EOL;
    }
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,$e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage().PHP_EOL : "No se pudo verificar integridad. Detalles omitidos.\n");exit(1);}
