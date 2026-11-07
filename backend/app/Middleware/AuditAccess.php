<?php
declare(strict_types=1);
namespace Clinica\Middleware;

final class AuditAccess
{
    public static function allowed(array $user): bool
    {
        return ($user['id'] ?? 0) > 0
            && in_array('consultar_auditoria', $user['permissions'] ?? [], true);
    }
}
