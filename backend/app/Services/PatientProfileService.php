<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Repositories\PatientProfileStore;
final class PatientProfileService
{
    public function __construct(private PatientProfileStore $profiles, private bool $writesEnabled = false) {}
    public function find(int $userId): ?array { return $this->profiles->findByUser($userId); }
    public function update(int $userId, array $input): void
    {
        if (!$this->writesEnabled) { throw new \RuntimeException('La edición del perfil está deshabilitada.'); }
        $result = PatientProfileValidator::validate($input);
        if ($result['errors']) { throw new PatientValidation($result['errors']); }
        $this->profiles->update($userId, $result['data']);
    }
}
