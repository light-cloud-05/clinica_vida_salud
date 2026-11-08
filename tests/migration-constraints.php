<?php
declare(strict_types=1);
require dirname(__DIR__).'/tools/migration-diagnostics.php';
require dirname(__DIR__).'/tools/migration-constraints.php';
$n=0;$check=static function(bool $ok)use(&$n):void{if(!$ok){throw new RuntimeException('Comparacion semantica incorrecta');}++$n;};
$base=['table_name'=>'medicos','conname'=>'medicos_estado_check','contype'=>'c','column_names'=>'["estado"]','convalidated'=>true,'condeferrable'=>false,'definition'=>"CHECK (((estado)::text = ANY (ARRAY[('activo'::character varying)::text, ('inactivo'::character varying)::text])))"];
$a=MigrationConstraintComparison::normalize($base);
foreach(["CHECK (((estado)::text = ANY ((ARRAY['activo'::character varying, 'inactivo'::character varying])::text[])))", "CHECK (estado = ANY (ARRAY['inactivo'::text,'activo'::text,'activo'::text]))", "CHECK ((\"estado\")::text = ANY(ARRAY['activo','inactivo']::text[]))"] as $expression){$check(MigrationConstraintComparison::normalize(array_replace($base,['definition'=>$expression]))===$a);}
foreach(["CHECK (estado = ANY (ARRAY['ACTIVO','inactivo']))","CHECK (estado = ANY (ARRAY['activo']))","CHECK (estado = ANY (ARRAY['activo','inactivo','otro']))","CHECK (estado <> ALL (ARRAY['activo','inactivo']))","CHECK (otro = ANY (ARRAY['activo','inactivo']))","CHECK (estado::varchar(3) = ANY (ARRAY['activo','inactivo']))","CHECK (estado = ANY (ARRAY['activo','inactivo']) OR true)","CHECK (lower(estado) = ANY (ARRAY['activo','inactivo']))","CHECK (estado = ANY (ARRAY['activo'::other_type,'inactivo']))"] as $expression){try{MigrationConstraintComparison::normalize(array_replace($base,['definition'=>$expression]));$check(false);}catch(MigrationDiagnosticFailure){$check(true);}}
foreach([['column_names'=>'["otro"]'],['contype'=>'u']] as $change){try{MigrationConstraintComparison::normalize(array_replace($base,$change));$check(false);}catch(MigrationDiagnosticFailure){$check(true);}}
$other=array_replace($base,['conname'=>'otro_check','definition'=>'CHECK(hora_fin > hora_inicio)']);$check(MigrationConstraintComparison::normalize($other)===$other);
$check(MigrationConstraintComparison::normalize(array_replace($base,['convalidated'=>false]))!==$a);
$check(MigrationConstraintComparison::normalize(array_replace($base,['condeferrable'=>true]))!==$a);
echo "$n pruebas de equivalencia de CHECK pasaron; sin conexiones.\n";
