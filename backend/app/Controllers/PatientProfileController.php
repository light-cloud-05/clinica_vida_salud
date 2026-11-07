<?php
declare(strict_types=1);
namespace Clinica\Controllers;
use Clinica\Services\{PatientProfileValidator,PatientValidation,SessionService};
use Clinica\Support\PatientProfileFactory;
final class PatientProfileController
{
    public function show(array $user): void
    {
        $profile = PatientProfileFactory::service()->find((int)$user['id']);
        if (!$profile) { http_response_code(404); echo 'Perfil de paciente no encontrado.'; return; }
        $errors = []; $message = null;
        $this->render('show', compact('user', 'profile', 'errors', 'message'));
    }
    public function update(array $user): void
    {
        if (!SessionService::validCsrf($_POST['csrf'] ?? null)) { http_response_code(419); echo 'El formulario venció.'; return; }
        $profile = PatientProfileFactory::service()->find((int)$user['id']);
        if (!$profile) { http_response_code(404); echo 'Perfil de paciente no encontrado.'; return; }
        $errors = []; $message = null; $values = array_merge($profile, $_POST);
        if (!PatientProfileFactory::writesEnabled()) { http_response_code(423); $errors['_form'] = 'La edición real está deshabilitada hasta autorizar la prueba aislada.'; }
        else {
            try { PatientProfileFactory::service(true)->update((int)$user['id'], $_POST); $message = 'El perfil se actualizó correctamente.'; $profile = PatientProfileFactory::service()->find((int)$user['id']); $values = $profile ?: $values; }
            catch (PatientValidation $e) { http_response_code(422); $errors = $e->errors; }
            catch (\Throwable $e) { http_response_code(503); $errors['_form'] = 'No se pudo guardar el perfil. No se confirmaron cambios.'; }
        }
        $this->render('show', compact('user', 'profile', 'values', 'errors', 'message'));
    }
    private function render(string $template, array $variables): void
    {
        $variables['title'] = 'Mi perfil'; $variables['section'] = 'mi-perfil'; $variables['sectionLabel'] = 'Mi perfil';
        extract($variables, EXTR_SKIP); $escape = static fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        require dirname(__DIR__, 3) . '/frontend/views/layouts/management-header.php';
        require dirname(__DIR__, 3) . '/frontend/views/perfil/' . $template . '.php';
        require dirname(__DIR__, 3) . '/frontend/views/layouts/management-footer.php';
    }
}
