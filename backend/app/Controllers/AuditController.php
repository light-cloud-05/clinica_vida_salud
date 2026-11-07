<?php
declare(strict_types=1);
namespace Clinica\Controllers;

use Clinica\Support\AuditFactory;
use Clinica\Support\Input;

final class AuditController
{
    public function index(array $user): void
    {
        try {
            $data = AuditFactory::service()->page($user, $_GET);
            $title = 'Consulta de auditoría';
            $this->render('index', compact('user','title','data'));
        } catch (\DomainException $exception) {
            http_response_code(403);
            echo 'No tiene autorización para consultar auditorías.';
        } catch (\InvalidArgumentException $exception) {
            http_response_code(400);
            echo htmlspecialchars($exception->getMessage(), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    public function show(array $user): void
    {
        try {
            $id = Input::positiveId($_GET['id'] ?? null);
            if ($id === null) {
                throw new \InvalidArgumentException('Identificador de auditoría inválido.');
            }
            $audit = AuditFactory::service()->detail($user, $id);
            if (!$audit) {
                http_response_code(404);
                echo 'Registro de auditoría no encontrado.';
                return;
            }
            $title = 'Detalle de auditoría';
            $this->render('show', compact('user','title','audit'));
        } catch (\DomainException $exception) {
            http_response_code(403);
            echo 'No tiene autorización para consultar auditorías.';
        } catch (\InvalidArgumentException $exception) {
            http_response_code(400);
            echo htmlspecialchars($exception->getMessage(), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    private function render(string $template, array $variables): void
    {
        extract($variables, EXTR_SKIP);
        $escape = static fn(mixed $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $userBase='/administrador';$section='auditoria';$sectionLabel='Consulta de auditoría';$success=null;
        $views=dirname(__DIR__,3).'/frontend/views/';
        require $views.'layouts/management-header.php';
        require $views.'auditoria/'.$template.'.php';
        require $views.'layouts/management-footer.php';
    }
}
