<?php
declare(strict_types=1);
namespace Clinica\Repositories;
interface ScheduleStore
{
    public function doctors(?int $userId): array;
    public function specialties(): array;
    public function hours(array $doctors): array;
    public function blocks(array $doctors,?string $from=null,?string $to=null): array;
    public function appointments(array $doctors,?string $from=null,?string $to=null): array;
    public function find(string $kind,int $id,array $doctors): ?array;
    public function save(string $kind,array $data,?int $id,?string $version,array $actor): int;
}
