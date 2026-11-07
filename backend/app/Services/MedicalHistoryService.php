<?php
declare(strict_types=1);

namespace Clinica\Services;

use Clinica\Repositories\MedicalHistoryStore;

final class MedicalHistoryService
{
    public function __construct(private MedicalHistoryStore $store, private bool $writesEnabled = false) {}

    public function history(array $actor, int $patientId): ?array
    {
        return $this->store->history($actor, $patientId);
    }

    public function appointmentForDoctor(array $actor, int $appointmentId): ?array
    {
        return $this->store->appointmentForDoctor($actor, $appointmentId);
    }

    public function addNote(array $actor, int $appointmentId, string $observations): int
    {
        if (!$this->writesEnabled) {
            throw new \RuntimeException('Las escrituras clínicas están deshabilitadas.');
        }
        $observations = trim($observations);
        if ($observations === '' || mb_strlen($observations, 'UTF-8') > 10000) {
            throw new \InvalidArgumentException('Registre observaciones válidas de hasta 10000 caracteres.');
        }
        return $this->store->addNote($actor, $appointmentId, $observations);
    }

    public function addTreatment(array $actor, int $appointmentId, string $description, ?string $instructions): int
    {
        if (!$this->writesEnabled) {
            throw new \RuntimeException('Las escrituras clínicas están deshabilitadas.');
        }
        $description = trim($description);
        $instructions = $instructions === null ? null : trim($instructions);
        if ($description === '' || mb_strlen($description, 'UTF-8') > 10000
            || ($instructions !== null && mb_strlen($instructions, 'UTF-8') > 10000)) {
            throw new \InvalidArgumentException('Registre un tratamiento válido de hasta 10000 caracteres.');
        }
        return $this->store->addTreatment($actor, $appointmentId, $description, $instructions ?: null);
    }
}
