<?php
declare(strict_types=1);
namespace Clinica\Middleware;

final class ReportAccess
{
    public static function canGenerate(array $user): bool
    {
        return ($user['id'] ?? 0) > 0 && in_array('generar_reportes', $user['permissions'] ?? [], true);
    }

    public static function canExport(array $user): bool
    {
        return ($user['id'] ?? 0) > 0 && in_array('exportar_reportes', $user['permissions'] ?? [], true);
    }
}
