<?php
declare(strict_types=1);
namespace Clinica\Repositories;

interface PatientStore
{
    public function count(string $search): int;
    public function listing(string $search, int $limit, int $offset): array;
    public function find(int $id): ?array;
    public function duplicates(array $data, ?int $exclude): array;
    public function save(array $data, ?int $id, ?string $version, int $actor): int;
}
