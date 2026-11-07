<?php
declare(strict_types=1);
// Prueba manual de entrada oculta, sin conexión a PostgreSQL ni escritura de contraseñas.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/backend/bootstrap.php';
try {
    $first=Clinica\Support\ConsoleSecret::read('Texto temporal de prueba (oculto): ');
    $second=Clinica\Support\ConsoleSecret::read('Repita el texto temporal (oculto): ');
    $same=$first!=='' && hash_equals($first,$second);
    unset($first,$second);
    echo $same ? "Entrada oculta y confirmación: correctas. No se guardó ningún valor.\n" : "Los textos no coinciden. No se guardó ningún valor.\n";
    exit($same ? 0 : 1);
} catch(Throwable $e) {
    fwrite(STDERR,"Entrada oculta no disponible: use una terminal interactiva Windows. No se guardó ningún valor.\n");
    exit(1);
}
