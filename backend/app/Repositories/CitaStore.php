<?php
declare(strict_types=1);
namespace Clinica\Repositories;
interface CitaStore
{
    public function count(array $actor,array $filters): int;
    public function listing(array $actor,array $filters,int $limit,int $offset): array;
    public function find(array $actor,int $id): ?array;
    public function choices(array $actor): array;
    public function availabilityChoices(array $actor): array;
    public function availabilityData(array $actor,int $doctor,string $date): array;
}
