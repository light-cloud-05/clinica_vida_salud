<?php
declare(strict_types=1);
namespace Clinica\Repositories;
interface PatientProfileStore
{
    public function findByUser(int $userId): ?array;
    public function update(int $userId, array $data): void;
}
