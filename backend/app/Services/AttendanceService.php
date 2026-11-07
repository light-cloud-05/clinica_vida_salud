<?php
declare(strict_types=1);
namespace Clinica\Services;

use Clinica\Repositories\AttendanceStore;
use Clinica\Support\Environment;

final class AttendanceService
{
    public function __construct(private AttendanceStore $store, private bool $enabled = false) {}

    public function find(array $actor, int $id): ?array
    {
        if (($actor['id'] ?? 0) < 1) {
            throw new \DomainException('Consulta no autorizada.');
        }
        return $this->store->find($actor, $id);
    }

    public function history(array $actor, int $id): array
    {
        return $this->store->history($actor, $id);
    }

    public function change(array $actor, string $action, array $input): void
    {
        AttendancePolicy::authorize($actor, $action);
        if (!$this->enabled) {
            throw new \RuntimeException('Las escrituras de asistencia están deshabilitadas.');
        }
        $appointment = $this->store->find($actor, $input['id']);
        if (!$appointment) {
            throw new \DomainException('La cita no está disponible dentro de su ámbito.');
        }
        if (!hash_equals(AttendancePolicy::version($appointment), $input['version'])) {
            throw new BookingConflict('La cita cambió. Vuelva a abrir el formulario.');
        }
        AttendancePolicy::check(
            $appointment,
            $action,
            new \DateTimeImmutable('now', new \DateTimeZone('America/La_Paz'))
        );
        $this->store->change($actor, $appointment, AttendancePolicy::state($action));
    }

    public static function enabledByEnvironment(): bool
    {
        return Environment::get('ATTENDANCE_WRITES_ENABLED') === 'true';
    }
}
