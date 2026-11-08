<?php
declare(strict_types=1);
require dirname(__DIR__).'/tools/migration-diagnostics.php';
$n=0;
$check=static function(bool $ok,string $label)use(&$n): void {if(!$ok){throw new RuntimeException('Fallo de diagnostico: '.$label);}++$n;};
$secret='DO_NOT_PRINT_THIS synthetic native error private_user private.invalid';
foreach(['08006'=>'DB_CONNECTION_FAILED','28P01'=>'DB_AUTH_FAILED','42501'=>'DB_PERMISSION_DENIED','42703'=>'DB_COLUMN_MISSING','42P01'=>'DB_TABLE_MISSING','57014'=>'DB_TIMEOUT'] as $state=>$expected){
    $state=(string)$state;
    $error=new PDOException('Native connection error '.$secret);
    $error->errorInfo=[$state,7,$secret];
    $event=MigrationDiagnostics::event($error,'TARGET_CONNECT');
    $check($event['reason']===$expected && $event['sqlstate']===$state,'SQLSTATE clasificado '.$state);
    $json=json_encode($event,JSON_THROW_ON_ERROR);
    $check(!str_contains($json,'private_user') && !str_contains($json,'DO_NOT_PRINT_THIS') && !str_contains($json,'private.invalid'),'No fuga nativa '.$state);
}
foreach([new RuntimeException($secret),new Error('Undefined function '.$secret),new MigrationDiagnosticFailure($secret)] as $error){
    $event=MigrationDiagnostics::event($error,'PHP_RUNTIME');
    $check($event['reason']==='UNEXPECTED_ERROR' && !str_contains(json_encode($event),$secret),'Error desconocido no refleja texto');
}
$check(MigrationDiagnostics::event(new RuntimeException('Cita fuera de ventana futura; no se cambiarán fechas automáticamente.'),'PLAN_BUILD')['reason']==='APPOINTMENT_DATE_INVALID','Error controlado UTF8 pasa a codigo ASCII');
$check(MigrationDiagnostics::event(new RuntimeException('Referencia de origen sin padre: citas'),'PLAN_BUILD')['reason']==='SOURCE_REFERENCE_MISSING','Referencia controlada');
$check(MigrationDiagnostics::event(new RuntimeException('Registro UNI26 existente divergente: medicos'),'PLAN_BUILD')['reason']==='EXISTING_IMPORT_DIVERGED','Divergencia controlada');
$check(MigrationDiagnostics::event(new MigrationDiagnosticFailure('MBSTRING_REQUIRED'),'PHP_RUNTIME')['reason']==='MBSTRING_REQUIRED','Extension ausente identificada');
$check(MigrationDiagnostics::event(new MigrationDiagnosticFailure('SCHEMA_TRIGGERS_DIFFER'),'TARGET_SCHEMA')['reason']==='SCHEMA_TRIGGERS_DIFFER','Componente de esquema identificado');
$check(MigrationDiagnostics::event(new Error($secret),'simulación en memoria')['stage']==='BOOTSTRAP','Etapa no ASCII nunca se refleja');
$runtime=MigrationDiagnostics::runtime();
$check(isset($runtime['php'],$runtime['binary'],$runtime['libpq']) && !isset($runtime['password']),'Runtime no lee variables privadas');
echo "$n comprobaciones de diagnostico seguro pasaron; sin conexiones ni escrituras.\n";
