<?php
declare(strict_types=1);
namespace Clinica\Repositories;

interface AuthRepository
{
    public function findByLogin(string $login): ?array;
    public function findById(int $id): ?array;
    public function permissions(int $roleId): array;
}
