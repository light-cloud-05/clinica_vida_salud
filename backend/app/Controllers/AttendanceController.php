<?php
declare(strict_types=1);
namespace Clinica\Controllers;

use Clinica\Middleware\CitaAccess;
use Clinica\Services\{AttendancePolicy, AttendanceService, BookingConflict, SessionService};
use Clinica\Support\AttendanceFactory;

final class AttendanceController
{
    public function staff(array $user): void { $this->store($user, 'asistencia'); }
    public function doctor(array $user): void { $this->store($user, 'atender'); }
    public function patient(array $user): void { $this->store($user, 'confirmar'); }

    private function store(array $user, string $fixedAction): void
    {
        try {
            if (!SessionService::validCsrf($_POST['csrf'] ?? null)) {
                $this->error($user, 419, 'El formulario venció. Vuelva a abrir la cita.');
                return;
            }
            $input = AttendancePolicy::input($user, $_POST, $fixedAction);
            $reader = AttendanceFactory::reader();
            $appointment = $reader->find($user, $input['id']);
            if (!$appointment) {
                $this->error($user, 404, 'La cita no está disponible dentro de su ámbito.');
                return;
            }
            AttendancePolicy::authorize($user, $input['action']);
            AttendancePolicy::check(
                $appointment,
                $input['action'],
                new \DateTimeImmutable('now', new \DateTimeZone('America/La_Paz'))
            );
            if (!AttendanceFactory::enabled()) {
                $this->error($user, 423, 'El guardado de asistencia está deshabilitado. No se modificó la cita.');
                return;
            }
            AttendanceFactory::writer()->change($user, $input['action'], $input);
            $_SESSION['attendance_notice'] = [
                'id' => $input['id'],
                'message' => 'El estado de la cita se actualizó correctamente.',
            ];
            header('Location: '.CitaAccess::base($user).'/detalle?id='.$input['id'], true, 303);
        } catch (\DomainException $exception) {
            $this->error($user, 403, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            $this->error($user, 422, $exception->getMessage());
        } catch (BookingConflict $exception) {
            $this->error($user, 409, $exception->getMessage());
        } catch (\RuntimeException $exception) {
            $this->error($user, 423, $exception->getMessage());
        } catch (\Throwable) {
            $this->error($user, 503, 'No se pudo actualizar la asistencia. Consulte nuevamente el estado de la cita.');
        }
    }

    private function error(array $user, int $status, string $message): void
    {
        http_response_code($status);
        $title = 'Revisar asistencia';
        $base = CitaAccess::base($user);
        $section = ltrim($base, '/');
        $sectionLabel = 'Citas médicas · Asistencia';
        $success = null;
        $escape = static fn(mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $views = dirname(__DIR__, 3).'/frontend/views/';
        require $views.'layouts/management-header.php';
        require $views.'citas/booking_error.php';
        require $views.'layouts/management-footer.php';
    }
}
