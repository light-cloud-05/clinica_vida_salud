<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';

use Clinica\Middleware\MedicalHistoryAccess;
use Clinica\Repositories\MedicalHistoryStore;
use Clinica\Services\MedicalHistoryService;
use Clinica\Services\SessionService;

final class MedicalHistoryStoreFake implements MedicalHistoryStore
{
    public array $historyData = ['patient'=>['id_paciente'=>1], 'appointments'=>[], 'notes'=>[], 'treatments'=>[], 'changes'=>[], 'clinical'=>true];
    public function history(array $actor, int $patientId): ?array { return $patientId === 1 ? $this->historyData : null; }
    public function appointmentForDoctor(array $actor, int $appointmentId): ?array { return $appointmentId === 1 ? ['id_cita'=>1,'id_paciente'=>1,'estado'=>'atendida'] : null; }
    public function addNote(array $actor, int $appointmentId, string $observations): int { return 10; }
    public function addTreatment(array $actor, int $appointmentId, string $description, ?string $instructions): int { return 20; }
}

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FALLO: '.$label); }
    ++$checks;
    echo "OK: $label\n";
};
$doctor = ['id'=>10,'role'=>'Medico','permissions'=>['consultar_historial_citas','gestionar_notas_medicas']];
$foreignDoctor = ['id'=>11,'role'=>'Medico','permissions'=>['consultar_historial_citas','gestionar_notas_medicas']];
$patient = ['id'=>20,'role'=>'Paciente','permissions'=>['consultar_informacion_medica_propia']];
$reception = ['id'=>30,'role'=>'Recepcionista','permissions'=>['consultar_historial_citas']];
$admin = ['id'=>40,'role'=>'Administrador','permissions'=>['consultar_historial_citas']];
$check(MedicalHistoryAccess::view($doctor) && MedicalHistoryAccess::clinical($doctor), 'Médico autorizado consulta datos clínicos');
$check(MedicalHistoryAccess::view($patient) && MedicalHistoryAccess::clinical($patient), 'Paciente consulta su historial permitido');
$check(!MedicalHistoryAccess::clinical($reception) && !MedicalHistoryAccess::clinical($admin), 'Recepción y administración no acceden a notas clínicas');
$check(!MedicalHistoryAccess::view(['id'=>20,'role'=>'Paciente','permissions'=>[]]), 'Paciente sin permiso no consulta historial');
$_SESSION['csrf'] = 'csrf-test';
$check(SessionService::validCsrf('csrf-test') && !SessionService::validCsrf('csrf-other'), 'CSRF válido y token ajeno rechazado');
$store = new MedicalHistoryStoreFake();
$read = new MedicalHistoryService($store);
$check($read->history($doctor, 1)['appointments'] === [], 'Pantalla sin registros conserva colecciones vacías');
$check($read->history($foreignDoctor, 2) === null, 'Paciente/cita fuera de ámbito no devuelve historial');
$writer = new MedicalHistoryService($store, true);
$check($writer->addNote($doctor, 1, 'Observación ficticia') === 10, 'Nota autorizada asociada a cita');
$check($writer->addTreatment($doctor, 1, 'Tratamiento ficticio', 'Indicaciones') === 20, 'Tratamiento asociado a cita y médico');
try { $writer->addNote($doctor, 1, ''); $check(false, 'Rechazo de nota vacía'); } catch (InvalidArgumentException) { $check(true, 'Rechazo de nota vacía'); }
try { (new MedicalHistoryService($store))->addTreatment($doctor, 1, 'Tratamiento', null); $check(false, 'Escrituras bloqueadas'); } catch (RuntimeException) { $check(true, 'Escrituras clínicas bloqueadas por defecto'); }
$routes = require dirname(__DIR__).'/backend/routes/web.php';
$check(isset($routes['/citas/historial-paciente'], $routes['/citas/notas-medicas'], $routes['/citas/tratamientos']), 'Rutas de historial y registro declaradas');
echo "$checks comprobaciones de historial clínico pasaron; sin escrituras PostgreSQL.\n";
