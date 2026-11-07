<?php
declare(strict_types=1);
namespace Clinica\Controllers;

use Clinica\Middleware\AuthorizationMiddleware;
use Clinica\Support\NotificationFactory;

final class NotificationController
{
    public function index(array $user): void
    {
        $patient = ($user['role'] ?? '') === 'Paciente';
        if ($patient && !AuthorizationMiddleware::allows($user, 'Paciente', 'gestionar_citas_propias')) {
            http_response_code(403);
            echo 'Consulta de notificaciones no autorizada.';
            return;
        }
        if (!$patient && !AuthorizationMiddleware::allows($user, 'Administrador', 'consultar_auditoria')) {
            http_response_code(403);
            echo 'Consulta de envíos no autorizada.';
            return;
        }
        $rows = NotificationFactory::reader()->listing($user, 100, 0);
        $title = $patient ? 'Mis notificaciones' : 'Estado de notificaciones';
        $base = $patient ? '/mis-notificaciones' : '/notificaciones';
        $section = ltrim($base, '/');
        $sectionLabel = 'Notificaciones';
        $escape = static fn(mixed $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $views = dirname(__DIR__, 3).'/frontend/views/';
        require $views.'layouts/management-header.php';
        require $views.'notificaciones/index.php';
        require $views.'layouts/management-footer.php';
    }
}
