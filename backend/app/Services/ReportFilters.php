<?php
declare(strict_types=1);
namespace Clinica\Services;

use Clinica\Support\Input;

final class ReportFilters
{
    public static function parse(array $query): array
    {
        $filters = ['fecha_inicio'=>null,'fecha_fin'=>null,'medico'=>null,'especialidad'=>null,'estado'=>null];
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
        foreach (['medico','especialidad'] as $field) {
            $value = $query[$field] ?? '';
            if ($value !== '') {
                $filters[$field] = Input::positiveId($value);
                if ($filters[$field] === null) {
                    throw new \InvalidArgumentException('Filtro de catálogo inválido.');
                }
            }
        }
        $state = $query['estado'] ?? '';
        if ($state !== '') {
            if (!is_string($state) || !in_array($state, CitaFilters::STATES, true)) {
                throw new \InvalidArgumentException('Estado de cita inválido.');
            }
            $filters['estado'] = $state;
        }
        return $filters;
    }
}
