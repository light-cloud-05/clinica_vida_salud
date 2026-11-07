<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Repositories\PatientAccountStore;

final class PatientAccountService
{
    public function __construct(private PatientAccountStore $accounts) {}

    public function register(array $input): int
    {
        $result = PatientAccountValidator::validate($input);
        if ($result['errors']) { throw new PatientValidation($result['errors']); }
        $d = $result['data'];
        $duplicates = array_filter($this->accounts->duplicates($d['nombre_usuario'], $d['correo_acceso'], $d['codigo_paciente'], $d['documento_identidad']));
        $matching = $this->accounts->matchingPatient($d);
        $identityDuplicate = isset($duplicates['codigo_paciente']) || isset($duplicates['documento_identidad']);
        if ($identityDuplicate && (!$matching || $matching['id_usuario'] !== null)) {
            $duplicates['documento_identidad'] = 'Los datos de identificación ya están registrados. Solicite la vinculación a recepción.';
        } elseif ($identityDuplicate) {
            unset($duplicates['codigo_paciente'], $duplicates['documento_identidad']);
        }
        if ($duplicates) { throw new PatientValidation($duplicates); }
        return $this->accounts->register(
            ['username' => $d['nombre_usuario'], 'email' => $d['correo_acceso'], 'hash' => $d['hash']],
            [
                'codigo_paciente' => $d['codigo_paciente'],
                'documento_identidad' => $d['documento_identidad'],
                'nombres' => $d['nombres'],
                'apellidos' => $d['apellidos'],
            ],
            $matching ? (int)$matching['id_paciente'] : null
        );
    }
}
