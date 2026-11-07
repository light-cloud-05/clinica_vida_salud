<?php
declare(strict_types=1);
namespace Clinica\Controllers;

use Clinica\Services\ReportExport;
use Clinica\Support\ReportFactory;

final class ReportController
{
    public function index(array $user): void
    {
        try {
            $data = ReportFactory::service()->dashboard($user, $_GET);
            $title = 'Reportes administrativos';
            $this->render('index', compact('user','title','data'));
        } catch (\DomainException $exception) {
            http_response_code(403);
            echo 'No tiene autorización para consultar estos reportes.';
        } catch (\InvalidArgumentException $exception) {
            http_response_code(400);
            echo htmlspecialchars($exception->getMessage(), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    public function export(array $user): void
    {
        try {
            $data = ReportFactory::service()->dashboard($user, $_GET, 'exportar_reportes');
            $format = $_GET['formato'] ?? '';
            if (!is_string($format) || !in_array($format, ['csv','pdf'], true)) {
                throw new \InvalidArgumentException('Formato de exportación no disponible.');
            }
            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="reporte-citas.csv"');
                echo ReportExport::csv($data['report']);
                return;
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="reporte-citas.pdf"');
            echo ReportExport::pdf($data['report'], $data['filters']);
        } catch (\DomainException $exception) {
            http_response_code(403);
            echo 'No tiene autorización para exportar estos reportes.';
        } catch (\InvalidArgumentException $exception) {
            http_response_code(400);
            echo htmlspecialchars($exception->getMessage(), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    private function render(string $template, array $variables): void
    {
        extract($variables, EXTR_SKIP);
        $escape = static fn(mixed $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $userBase = '/administrador';
        $section = 'reportes';
        $sectionLabel = 'Reportes administrativos';
        $success = null;
        $views = dirname(__DIR__,3).'/frontend/views/';
        require $views.'layouts/management-header.php';
        require $views.'reportes/'.$template.'.php';
        require $views.'layouts/management-footer.php';
    }
}
