<?php
declare(strict_types=1);
namespace Clinica\Repositories;

interface PasswordRecoveryStore
{
    public function userByEmail(string $email): ?array;
    public function activeRequests(int $userId): int;
    public function create(int $userId, string $hash, \DateTimeImmutable $expires): void;
    public function reset(string $hash, string $newPassword): bool;
}
