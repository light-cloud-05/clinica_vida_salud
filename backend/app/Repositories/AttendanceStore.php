<?php
declare(strict_types=1);
namespace Clinica\Repositories;

interface AttendanceStore
{
    public function find(array $actor, int $id, bool $lock = false): ?array;
    public function history(array $actor, int $id): array;
    public function change(array $actor, array $appointment, string $state): void;
}
