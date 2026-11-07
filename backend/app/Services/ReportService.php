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
        if (!$allowed) {
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
        return ['filters'=>$filters,'choices'=>$choices,'report'=>$this->store->dashboard($filters)];
    }
}
