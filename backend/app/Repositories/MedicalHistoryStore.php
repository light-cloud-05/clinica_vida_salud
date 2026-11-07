<?php
declare(strict_types=1);

namespace Clinica\Repositories;

interface MedicalHistoryStore
{
    public function history(array $actor, int $patientId): ?array;

    public function appointmentForDoctor(array $actor, int $appointmentId): ?array;

    public function addNote(array $actor, int $appointmentId, string $observations): int;

    public function addTreatment(array $actor, int $appointmentId, string $description, ?string $instructions): int;
}
