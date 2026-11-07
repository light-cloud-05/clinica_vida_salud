<?php
declare(strict_types=1);
namespace Clinica\Support;

final class ConsoleSecret
{
    public static function read(string $prompt): string
    {
        if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Windows' || !stream_isatty(STDIN)) {
            throw new \RuntimeException('Se requiere una terminal interactiva de Windows; no se admite entrada redirigida.');
        }
        fwrite(STDOUT, $prompt);
        $script = dirname(__DIR__, 3) . '/tools/read-secret.ps1';
        // Proceso fijo, sin interpolación de contraseñas ni argumentos de usuario.
        // La política se aplica solo a este proceso auxiliar; no altera la política global de Windows.
        $process = proc_open(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script], [0=>STDIN, 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('No se pudo abrir la entrada oculta.'); }
        $encoded = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]); // No reenviar excepciones del proceso auxiliar.
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        fwrite(STDOUT, PHP_EOL);
        $decoded = base64_decode($encoded, true);
        if ($exit !== 0 || $decoded === false) { throw new \RuntimeException('Entrada oculta cancelada o no disponible.'); }
        return $decoded;
    }
}
