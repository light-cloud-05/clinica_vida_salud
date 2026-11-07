<?php
declare(strict_types=1);
namespace Clinica\Services;

use Clinica\Middleware\AttendanceAccess;
use Clinica\Support\Input;

final class AttendancePolicy
{
    public const ACTIONS = ['confirmar', 'atender', 'inasistencia'];

    public static function input(array $actor, array $input, string $fixedAction): array
    {
        if (!AttendanceAccess::view($actor)) {
            throw new \DomainException('No tiene permiso para controlar la asistencia.');
        }
        $allowed = ['id', 'version', 'csrf'];
        if ($fixedAction === 'asistencia') {
            $allowed[] = 'accion';
        }
        if (array_diff(array_keys($input), $allowed)) {
            throw new \InvalidArgumentException('Hay campos no permitidos.');
        }
        $id = Input::positiveId($input['id'] ?? null);
        $version = $input['version'] ?? null;
        if ($id === null || !is_string($version) || !preg_match('/^[a-f0-9]{64}$/D', $version)) {
            throw new \InvalidArgumentException('Identificador o versión inválidos.');
        }
        $action = $fixedAction === 'asistencia' ? ($input['accion'] ?? null) : $fixedAction;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Acción de asistencia inválida.');
        }
        return ['id' => $id, 'version' => $version, 'action' => $action];
    }

    public static function authorize(array $actor, string $action): void
    {
        $allowed = match ($action) {
            'confirmar' => AttendanceAccess::confirm($actor) || AttendanceAccess::control($actor),
            'atender' => AttendanceAccess::attend($actor),
            'inasistencia' => AttendanceAccess::control($actor),
            default => false,
        };
        if (!$allowed) {
            throw new \DomainException('No tiene permiso para realizar esta acción.');
        }
    }

    public static function check(array $appointment, string $action, \DateTimeImmutable $now): void
    {
        self::authorizeState($appointment['estado'], $action);
        if ($action === 'inasistencia') {
            $start = new \DateTimeImmutable(
                $appointment['fecha'].' '.$appointment['hora_inicio'],
                new \DateTimeZone('America/La_Paz')
            );
            if ($now < $start->modify('+10 minutes')) {
                throw new \DomainException('La inasistencia solo puede registrarse después de 10 minutos desde la hora de inicio.');
            }
        }
    }

    public static function authorizeState(string $state, string $action): void
    {
        $allowed = match ($action) {
            'confirmar' => $state === 'programada',
            'atender', 'inasistencia' => in_array($state, ['programada', 'confirmada'], true),
            default => false,
        };
        if (!$allowed) {
            throw new \DomainException('La transición solicitada no está permitida desde el estado actual.');
        }
    }

    public static function version(array $appointment): string
    {
        return hash('sha256', json_encode(array_map(
            static fn(string $key): string => (string) $appointment[$key],
            ['id_cita', 'id_paciente', 'id_medico', 'id_especialidad', 'fecha', 'hora_inicio', 'hora_fin', 'estado', 'cantidad_reprogramaciones', 'updated_at']
        ), JSON_THROW_ON_ERROR));
    }

    public static function state(string $action): string
    {
        return match ($action) {
            'confirmar' => 'confirmada',
            'atender' => 'atendida',
            'inasistencia' => 'inasistencia',
            default => throw new \InvalidArgumentException('Acción de asistencia inválida.'),
        };
    }
}
