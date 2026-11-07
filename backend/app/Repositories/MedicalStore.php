<?php
declare(strict_types=1);
namespace Clinica\Repositories;
interface MedicalStore
{
    public function countDoctors(string $search,?int $specialty): int;
    public function doctors(string $search,?int $specialty,int $limit,int $offset): array;
    public function specialties(): array;
    public function find(string $kind,int $id): ?array;
    public function duplicates(string $kind,array $data,?int $exclude): array;
    public function save(string $kind,array $data,array $specialties,?int $id,?string $version,?string $associationVersion,int $actor): int;
}
