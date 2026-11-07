<?php
declare(strict_types=1);

namespace Clinica\Middleware;

final class LocalDiagnostic
{
    public static function allowed(array $server, string $environment, string $enabled, string $expected): bool
    {
        $supplied = $server['HTTP_X_DIAGNOSTIC_TOKEN'] ?? '';
        return $environment === 'local' && $enabled === 'true'
            && in_array($server['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
            && strlen($expected) >= 32 && is_string($supplied)
            && hash_equals($expected, $supplied);
    }
}
