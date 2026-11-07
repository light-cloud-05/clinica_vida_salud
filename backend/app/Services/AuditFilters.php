<?php
declare(strict_types=1);
namespace Clinica\Services;

use Clinica\Support\Input;

final class AuditFilters
{
    public const OPERATIONS = ['INSERT','UPDATE','DELETE'];

    public static function parse(array $query): array
    {
        $filters = ['usuario'=>null,'fecha_inicio'=>null,'fecha_fin'=>null,'operacion'=>null,'entidad'=>null,'pagina'=>1];
        foreach (['usuario','pagina'] as $field) {
            $value = $query[$field] ?? '';
            if ($value !== '') {
                $filters[$field] = Input::positiveId($value);
                if ($filters[$field] === null) {
                    throw new \InvalidArgumentException('Identificador o página inválidos.');
                }
            }
        }
        foreach (['fecha_inicio','fecha_fin'] as $field) {
            $value = $query[$field] ?? '';
            if ($value !== '') {
                if (!is_string($value) || !ScheduleValidator::date($value)) {
                    throw new \InvalidArgumentException('Rango de fechas inválido.');
                }
                $filters[$field] = $value;
            }
        }
        if ($filters['fecha_inicio'] !== null && $filters['fecha_fin'] !== null && $filters['fecha_inicio'] > $filters['fecha_fin']) {
            throw new \InvalidArgumentException('La fecha inicial no puede ser posterior a la fecha final.');
        }
        $operation = $query['operacion'] ?? '';
        if ($operation !== '') {
            if (!is_string($operation) || !in_array($operation, self::OPERATIONS, true)) {
                throw new \InvalidArgumentException('Tipo de operación inválido.');
            }
            $filters['operacion'] = $operation;
        }
        $entity = $query['entidad'] ?? '';
        if ($entity !== '') {
            if (!is_string($entity) || !mb_check_encoding($entity, 'UTF-8') || str_contains($entity, "\0") || mb_strlen($entity, 'UTF-8') > 80) {
                throw new \InvalidArgumentException('Entidad inválida.');
            }
            $filters['entidad'] = trim($entity);
        }
        return $filters;
    }
}
