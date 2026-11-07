<?php
declare(strict_types=1);
// Solo se usa explícitamente como auto_prepend_file de un proceso de pruebas.
require_once dirname(__DIR__).'/vendor/autoload.php';
$private=dirname(__DIR__).'/var/integration/test.env';
if(!is_file($private)){throw new RuntimeException('Falta el entorno privado de integración.');}
foreach(file($private,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
    if(!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/D',$line,$m)){throw new RuntimeException('Configuración de prueba inválida.');}
    putenv($m[1].'='.$m[2]);
}
if(Clinica\Support\Environment::get('DB_DATABASE')!=='clinica_vida_salud_test' || Clinica\Support\Environment::get('DB_PORT')!=='55432'){throw new RuntimeException('Destino de pruebas incorrecto.');}
