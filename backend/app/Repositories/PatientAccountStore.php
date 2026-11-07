<?php
declare(strict_types=1);
namespace Clinica\Repositories;

interface PatientAccountStore
{
    public function duplicates(string $username, string $email, string $code, string $document): array;
    public function matchingPatient(array $identity): ?array;
    public function register(array $account, array $patient, ?int $existingPatientId = null): int;
}
