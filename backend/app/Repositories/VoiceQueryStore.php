<?php
declare(strict_types=1);
namespace Clinica\Repositories;

interface VoiceQueryStore
{
    public function appointments(array $actor,bool $today,\DateTimeImmutable $now):array;
    public function choices(array $actor):array;
    public function availability(array $actor,?int $specialty,?string $date,\DateTimeImmutable $now):array;
    public function payments(array $actor):array;
}
