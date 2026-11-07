<?php
declare(strict_types=1);

namespace Clinica\Controllers;

use Clinica\Middleware\MedicalHistoryAccess;
use Clinica\Services\{MedicalRecordConflict, SessionService};
use Clinica\Support\{Input, MedicalHistoryFactory};

final class MedicalHistoryController
{
    public function show(array $user): void
    {
        if (!MedicalHistoryAccess::view($user)) {
            $this->error(403, 'No tiene autorización para consultar historiales.');
            return;
        }
        $patientId = Input::positiveId($_GET['id'] ?? null);
        if ($patientId === null) {
            $this->error(400, 'Identificador de paciente inválido.');
            return;
        }
        try {
            $history = MedicalHistoryFactory::service()->history($user, $patientId);
            if (!$history) {
                $this->error(404, 'El paciente no está dentro de su ámbito autorizado.');
                return;
            }
            $this->render($user, $history);
        } catch (\DomainException $exception) {
            $this->error(403, $exception->getMessage());
        } catch (\Throwable) {
            $this->error(503, 'No se pudo consultar el historial. No se muestran datos clínicos.');
        }
    }

    public function note(array $user): void
    {
        $this->record($user, 'note');
    }

    public function treatment(array $user): void
    {
        $this->record($user, 'treatment');
    }

    private function record(array $user, string $kind): void
    {
        if (!MedicalHistoryAccess::write($user)) {
            $this->error(403, 'No tiene autorización para registrar información clínica.');
            return;
        }
        if (!SessionService::validCsrf($_POST['csrf'] ?? null)) {
            $this->error(419, 'El formulario venció. Vuelva a abrir el historial.');
            return;
        }
        $appointmentId = Input::positiveId($_POST['id_cita'] ?? null);
        if ($appointmentId === null) {
            $this->error(422, 'Identificador de cita inválido.');
            return;
        }
        try {
            $service = MedicalHistoryFactory::service(true);
            $appointment = $service->appointmentForDoctor($user, $appointmentId);
            if (!$appointment) {
                $this->error(403, 'La cita no pertenece al médico autenticado.');
                return;
            }
            if ($kind === 'note') {
                $service->addNote($user, $appointmentId, is_string($_POST['observaciones'] ?? null) ? $_POST['observaciones'] : '');
                $message = 'La nota médica se registró correctamente.';
            } else {
                $instructions = $_POST['indicaciones'] ?? null;
                $service->addTreatment(
                    $user,
                    $appointmentId,
                    is_string($_POST['descripcion'] ?? null) ? $_POST['descripcion'] : '',
                    is_string($instructions) ? $instructions : null
                );
                $message = 'El tratamiento se registró correctamente.';
            }
            $_SESSION['medical_record_notice'] = ['id' => (int) $appointment['id_paciente'], 'message' => $message];
            header('Location: /citas/historial-paciente?id='.$appointment['id_paciente'], true, 303);
        } catch (MedicalRecordConflict $exception) {
            $this->error(409, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            $this->error(422, $exception->getMessage());
        } catch (\RuntimeException $exception) {
            $this->error(423, $exception->getMessage());
        } catch (\Throwable) {
            $this->error(503, 'No se pudo guardar el registro clínico. No se confirmaron cambios.');
        }
    }

    private function render(array $user, array $history): void
    {
        $title = 'Historial clínico del paciente';
        $section = 'citas';
        $sectionLabel = 'Historial clínico';
        $success = null;
        if (isset($_SESSION['medical_record_notice'])
            && (int) $_SESSION['medical_record_notice']['id'] === (int) $history['patient']['id_paciente']) {
            $success = $_SESSION['medical_record_notice']['message'];
            unset($_SESSION['medical_record_notice']);
        }
        $escape = static fn(mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        require dirname(__DIR__, 3).'/frontend/views/layouts/management-header.php';
        require dirname(__DIR__, 3).'/frontend/views/medical-history/show.php';
        require dirname(__DIR__, 3).'/frontend/views/layouts/management-footer.php';
    }

    private function error(int $status, string $message): void
    {
        http_response_code($status);
        echo htmlspecialchars($message, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    }
}
