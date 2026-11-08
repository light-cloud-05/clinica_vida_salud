<?php
declare(strict_types=1);
namespace Clinica\Services;

use Clinica\Middleware\ReportAccess;
use Clinica\Repositories\ReportStore;

final class ReportService
{
    public function __construct(private ReportStore $store) {}

    private function guard(array $actor, string $permission): void
    {
        $allowed = $permission === 'exportar_reportes'
            ? ReportAccess::canExport($actor)
            : ReportAccess::canGenerate($actor);
        if (!$allowed || !in_array($actor['role'] ?? '', ['Administrador','Recepcionista','Medico','Paciente'], true)) {
            throw new \DomainException('Reportes no autorizados.');
        }
    }

    public function dashboard(array $actor, array $query, string $permission = 'generar_reportes'): array
    {
        $this->guard($actor, $permission);
        $filters = ReportFilters::parse($query);
        $choices = $this->store->choices();
        foreach (['medico'=>'doctors','especialidad'=>'specialties'] as $field=>$collection) {
            if ($filters[$field] !== null && !in_array($filters[$field], array_map('intval', array_column($choices[$collection], 'id_'.$field)), true)) {
                throw new \DomainException('El filtro solicitado no existe.');
            }
        }
        $scoped = $filters;
        if (in_array($actor['role'], ['Medico','Paciente'], true)) {
            $scoped['_scope'] = ['role'=>$actor['role'], 'user_id'=>(int)$actor['id']];
        }
        return ['filters'=>$filters,'choices'=>$choices,'report'=>$this->store->dashboard($scoped)];
    }
}
