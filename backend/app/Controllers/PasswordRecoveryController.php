<?php
declare(strict_types=1);
namespace Clinica\Controllers;
use Clinica\Services\SessionService;
use Clinica\Support\PasswordRecoveryFactory;

final class PasswordRecoveryController
{
    public function request(): void
    {
        SessionService::start();
        if (!SessionService::validCsrf($_POST['csrf'] ?? null)) { http_response_code(419); echo 'El formulario venció.'; return; }
        $email = is_string($_POST['correo'] ?? null) ? trim($_POST['correo']) : '';
        $message = 'Si existe una cuenta con ese correo, recibirá instrucciones para continuar.';
        if (PasswordRecoveryFactory::enabled()) {
            try { PasswordRecoveryFactory::service(true)->request($email); }
            catch (\Throwable $e) { http_response_code(503); $message = 'No se pudo procesar la solicitud. Inténtelo nuevamente más tarde.'; }
        } else {
            http_response_code(423);
            $errors = ['_form' => 'La recuperación está temporalmente deshabilitada; no se envió ningún correo.'];
        }
        $errors = $errors ?? []; require dirname(__DIR__, 3) . '/frontend/views/auth/recovery.php';
    }
    public function resetForm(): void
    {
        SessionService::start(); $errors = []; $message = null;
        $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
        require dirname(__DIR__, 3) . '/frontend/views/auth/reset-password.php';
    }
    public function reset(): void
    {
        SessionService::start();
        if (!SessionService::validCsrf($_POST['csrf'] ?? null)) { http_response_code(419); echo 'El formulario venció.'; return; }
        $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        $password = is_string($_POST['contrasena'] ?? null) ? $_POST['contrasena'] : '';
        $confirmation = is_string($_POST['confirmacion'] ?? null) ? $_POST['confirmacion'] : '';
        $errors = []; $message = null;
        if (!PasswordRecoveryFactory::enabled()) { http_response_code(423); $errors['_form'] = 'La recuperación está temporalmente deshabilitada.'; }
        elseif (!PasswordRecoveryFactory::service(true)->reset($token, $password, $confirmation)) { http_response_code(422); $errors['_form'] = 'El enlace no es válido, venció o las contraseñas no coinciden.'; }
        else { $message = 'La contraseña fue actualizada. Las sesiones anteriores deberán autenticarse nuevamente.'; }
        require dirname(__DIR__, 3) . '/frontend/views/auth/reset-password.php';
    }
}
