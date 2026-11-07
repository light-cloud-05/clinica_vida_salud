<?php
declare(strict_types=1);
namespace Clinica\Controllers;

use Clinica\Middleware\AuthMiddleware;
use Clinica\Services\AuthService;
use Clinica\Services\SessionService;
use Clinica\Services\LoginThrottle;
use Clinica\Support\AuthFactory;
use Clinica\Support\PatientAccountFactory;

final class AuthController
{
    public function form(): void
    {
        if (isset($_SESSION['auth']) && ($user = AuthMiddleware::current(AuthFactory::service()))) {
            header('Location: ' . AuthService::roles()[$user['role']], true, 303);
            return;
        }
        // Si la cuenta fue revocada, el middleware destruyó la sesión anterior.
        SessionService::start();
        $error = null;
        $identifier = '';
        require dirname(__DIR__, 3) . '/frontend/views/auth/login.php';
    }

    public function login(): void
    {
        if (!SessionService::validCsrf($_POST['csrf'] ?? null)) {
            http_response_code(419);
            echo 'El formulario venció. Recargue la página e inténtelo de nuevo.';
            return;
        }
        // No permitir que una autenticación anterior sobreviva a otro intento.
        unset($_SESSION['auth']);
        $identifier = is_string($_POST['identifier'] ?? null) ? trim($_POST['identifier']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (strlen($identifier) > 320) { $identifier = ''; }
        $result = (new LoginThrottle(dirname(__DIR__, 3) . '/var/auth'))->attempt($_SERVER['REMOTE_ADDR'] ?? 'unknown', $identifier, static fn() => AuthFactory::service()->authenticate($identifier, $password));
        unset($password, $_POST['password']);
        if ($result['limited']) {
            http_response_code(429);
            header('Retry-After: ' . $result['retry']);
            $error = 'Demasiados intentos. Espere unos minutos antes de volver a intentarlo.';
        } elseif ($result['user']) {
            SessionService::login($result['user']);
            header('Location: ' . AuthService::roles()[$result['user']['role']], true, 303);
            return;
        } else {
            http_response_code(401);
            $error = 'No se pudo iniciar sesión. Revise sus credenciales o consulte al administrador.';
        }
        require dirname(__DIR__, 3) . '/frontend/views/auth/login.php';
    }

    public function logout(): void
    {
        if (!SessionService::validCsrf($_POST['csrf'] ?? null)) {
            http_response_code(419);
            echo 'El formulario venció. Recargue la página.';
            return;
        }
        SessionService::logout();
        header('Location: /login?salida=1', true, 303);
    }

    public function registerForm(): void
    {
        SessionService::start();
        $values = []; $errors = []; $success = null;
        require dirname(__DIR__, 3) . '/frontend/views/auth/register.php';
    }

    public function register(): void
    {
        SessionService::start();
        if (!SessionService::validCsrf($_POST['csrf'] ?? null)) { http_response_code(419); echo 'El formulario venció.'; return; }
        $values = $_POST; $errors = []; $success = null;
        if (!PatientAccountFactory::writesEnabled()) {
            http_response_code(423);
            $errors['_form'] = 'El registro está temporalmente deshabilitado mientras se valida el entorno.';
        } else {
            try {
                PatientAccountFactory::service(true)->register($_POST);
                $success = 'La solicitud fue registrada. Ya puede iniciar sesión cuando la cuenta sea habilitada.';
                $values = [];
            } catch (\Clinica\Services\PatientValidation $e) { http_response_code(422); $errors = $e->errors; }
            catch (\Throwable $e) { http_response_code(503); $errors['_form'] = 'No se pudo completar el registro. No se confirmaron cambios.'; }
        }
        require dirname(__DIR__, 3) . '/frontend/views/auth/register.php';
    }

    public function recoveryForm(): void
    {
        SessionService::start();
        $errors = []; $message = null;
        require dirname(__DIR__, 3) . '/frontend/views/auth/recovery.php';
    }
}
