<?php
declare(strict_types=1);

namespace Clinica\Controllers;

use Clinica\Middleware\LocalDiagnostic;
use Clinica\Support\Environment as Env;
use Clinica\Services\DatabaseCheckService;

final class DatabaseCheckController
{
    public function index(): void
    {
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        if (!LocalDiagnostic::allowed($_SERVER, Env::get('APP_ENV'), Env::get('DB_CHECK_ENABLED'), Env::get('DB_CHECK_TOKEN'))) {
            http_response_code(404);
            echo json_encode(['error' => 'Recurso no disponible.']);
            return;
        }
        try {
            $result = (new DatabaseCheckService())->check(require dirname(__DIR__, 2) . '/config/database.php');
            if (!$result['matches_expected_count']) {
                http_response_code(503);
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            http_response_code(503);
            echo json_encode(['connection' => false, 'error' => 'No se pudo verificar la conexión. Revise PDO_PGSQL y la configuración local.']);
        }
    }
}
