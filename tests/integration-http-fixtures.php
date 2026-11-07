<?php
declare(strict_types=1);
require dirname(__DIR__).'/tools/integration-bootstrap.php';require dirname(__DIR__).'/backend/bootstrap.php';
use Clinica\Support\IntegrationGuard;
use Clinica\Repositories\{Connection,CitaRepository};
use Clinica\Services\{SessionService,CitaService,DisponibilidadService};
$pdo=Connection::integration(require dirname(__DIR__).'/backend/config/database.php');IntegrationGuard::check($pdo);
$dir=dirname(__DIR__).'/var/integration';$actors=json_decode(file_get_contents($dir.'/actors.json'),true);$f=['sessions'=>[],'slots'=>[]];
foreach($actors as $role=>$actor){SessionService::start();SessionService::login($actor);$f['sessions'][$role]=['id'=>session_id(),'csrf'=>SessionService::csrf()];session_write_close();session_id('');}
$svc=new CitaService(new CitaRepository($pdo));$patient=$actors['Paciente'];$days=DisponibilidadService::window(new DateTimeImmutable('now',new DateTimeZone('America/La_Paz')));$f['days']=$days;
foreach($days as $day){foreach($svc->availabilityChoices($patient)['doctors'] as $d){if($d['estado']!=='activo' || !$d['especialidades']){continue;}$sp=$d['especialidades'][0];foreach($svc->availability($patient,$d['id_medico'],$sp,$day)['slots'] as $slot){if($slot['available']){$f['slots'][]=['medico'=>$d['id_medico'],'especialidad'=>$sp,'fecha'=>$day,'hora'=>$slot['hora_inicio']];if(count($f['slots'])===6){break 3;}}}}}
foreach($svc->availabilityChoices($patient)['doctors'] as $d){if($d['estado']!=='activo' || !$d['especialidades']){continue;}$sp=$d['especialidades'][0];$data=$svc->availability($patient,$d['id_medico'],$sp,$days[1]);foreach(array_reverse($data['slots']) as $r){if(!$r['available']){continue;}$end=Clinica\Services\PaymentPolicy::cashDeadline(Clinica\Services\PaymentPolicy::clock(),new DateTimeImmutable($days[1].' '.$r['hora_inicio'],new DateTimeZone('America/La_Paz')));if($end && Clinica\Services\PaymentPolicy::cashOpen($end->modify('-2 minutes'))){$f['cash']=['medico'=>$d['id_medico'],'especialidad'=>$sp,'fecha'=>$days[1],'hora'=>$r['hora_inicio']];$f['cashClock']=$end->modify('-2 minutes')->format(DATE_ATOM);break 2;}}}
file_put_contents($dir.'/http-sessions.json',json_encode($f));echo "Sesiones separadas de la aplicación principal y franjas de la copia preparadas.\n";
