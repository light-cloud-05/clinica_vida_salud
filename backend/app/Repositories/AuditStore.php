<?php
declare(strict_types=1);
namespace Clinica\Repositories;

interface AuditStore
{
    public function choices(): array;
    public function count(array $filters): int;
    public function listing(array $filters, int $limit, int $offset): array;
    public function find(int $id): ?array;
    public function appointmentHistory(array $filters, int $limit): array;
}
