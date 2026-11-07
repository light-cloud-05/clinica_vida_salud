<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// Lista cerrada: el servidor temporal solo entrega los recursos públicos previstos.
if (in_array($path, ['/assets/css/app.css', '/assets/js/app.js'], true)) {
    return false;
}
require dirname(__DIR__) . '/public/index.php';
