<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
require __DIR__.'/integration-bootstrap.php';require dirname(__DIR__).'/backend/bootstrap.php';
$pdo=Clinica\Repositories\Connection::integration(require dirname(__DIR__).'/backend/config/database.php');
Clinica\Support\IntegrationGuard::check($pdo);
echo "AISLAMIENTO CORRECTO: base test, puerto 55432, clúster y marcador verificados; original ausente.\n";
