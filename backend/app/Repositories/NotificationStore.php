<?php
declare(strict_types=1);
namespace Clinica\Repositories;

interface NotificationStore
{
    public function appointment(int $id): ?array;
    public function enqueue(int $appointmentId, string $type, string $recipient, string $subject, ?string $scheduledAt = null): ?int;
    public function pending(int $limit): array;
    public function upcoming(\DateTimeImmutable $now, int $leadMinutes, int $limit): array;
    public function markSent(int $id): void;
    public function markError(int $id): void;
    public function listing(array $actor, int $limit, int $offset): array;
}
