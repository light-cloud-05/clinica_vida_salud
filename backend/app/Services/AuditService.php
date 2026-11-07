<?php
declare(strict_types=1);
namespace Clinica\Services;

use Clinica\Middleware\AuditAccess;
use Clinica\Repositories\AuditStore;

final class AuditService
{
    public const PAGE_SIZE = 25;
    public function __construct(private AuditStore $store) {}

    private function guard(array $actor): void
    {
        if (!AuditAccess::allowed($actor)) {
            throw new \DomainException('Auditoría no autorizada.');
        }
    }

    public function page(array $actor, array $query): array
    {
        $this->guard($actor);
        $filters = AuditFilters::parse($query);
        $users = $this->store->choices()['users'];
        if ($filters['usuario'] !== null && !in_array($filters['usuario'], array_map('intval', array_column($users, 'id_usuario')), true)) {
            throw new \DomainException('El usuario solicitado no existe.');
        }
        $total = $this->store->count($filters);
        $pages = max(1, (int)ceil($total / self::PAGE_SIZE));
        if ($filters['pagina'] > $pages) {
            $filters['pagina'] = $pages;
        }
        return [
            'filters'=>$filters, 'users'=>$users, 'total'=>$total, 'pages'=>$pages,
            'rows'=>$this->store->listing($filters, self::PAGE_SIZE, ($filters['pagina']-1)*self::PAGE_SIZE),
            'history'=>$this->store->appointmentHistory($filters, 50),
        ];
    }

    public function detail(array $actor, int $id): ?array
    {
        $this->guard($actor);
        return $this->store->find($id);
    }
}
