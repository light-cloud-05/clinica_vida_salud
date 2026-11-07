<?php
declare(strict_types=1);
require dirname(__DIR__).'/tools/integration-bootstrap.php';require dirname(__DIR__).'/backend/bootstrap.php';
$dir=dirname(__DIR__).'/var/integration';$job=json_decode(file_get_contents($dir.'/race.json'),true);
$pdo=Clinica\Repositories\Connection::appointmentCreation(require dirname(__DIR__).'/backend/config/database.php');
$actor=json_decode(file_get_contents($dir.'/actors.json'),true)['Paciente'];
$deadline=microtime(true)+10;while(!is_file($dir.'/race.go')){if(microtime(true)>$deadline){exit(2);}usleep(10000);}
try{$r=(new Clinica\Services\ReservaService(new Clinica\Repositories\ReservaRepository($pdo),true))->reserve($actor,$job);echo json_encode(['ok'=>true,'id'=>$r['id']]);}
catch(Clinica\Services\BookingConflict $e){echo json_encode(['ok'=>false,'conflict'=>true]);}
catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>get_class($e)]);exit(1);}
