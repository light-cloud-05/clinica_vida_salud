<?php
declare(strict_types=1);

$root = dirname(__DIR__);
foreach (['css/app.css', 'css/home.css', 'js/app.js', 'js/reportes.js', 'js/consultas-voz.js'] as $file) {
    $destination = $root . '/public/assets/' . $file;
    if (!is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0775, true);
    }
    if (!copy($root . '/frontend/assets/' . $file, $destination)) {
        throw new RuntimeException('No se pudo publicar un recurso.');
    }
}
echo "Recursos publicados.\n";
