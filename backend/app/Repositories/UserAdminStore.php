<?php
declare(strict_types=1);
namespace Clinica\Repositories;

interface UserAdminStore
{
    public function count(string $search, ?int $roleId, ?string $status): int;
    public function listing(string $search, ?int $roleId, ?string $status, int $limit, int $offset): array;
    public function find(int $id): ?array;
    public function roles(): array;
    public function rolePermissions(): array;
    public function duplicates(string $username, string $email, ?int $exclude): array;
    public function save(array $data, ?int $id, int $actor): int;
    public function activeAdministrators(): int;
}
