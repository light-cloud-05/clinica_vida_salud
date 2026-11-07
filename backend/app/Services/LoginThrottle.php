<?php
declare(strict_types=1);
namespace Clinica\Services;

/** Contador compartido entre sesiones, con bloqueo de archivo. No almacena credenciales. */
final class LoginThrottle
{
    public function __construct(private string $directory) {}

    public function attempt(string $ip, string $identifier, callable $authenticate, ?int $now = null): array
    {
        $now ??= time();
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Control de intentos no disponible.');
        }
        $handle = fopen($this->directory . '/attempts.json', 'c+');
        if (!$handle) { throw new \RuntimeException('Control de intentos no disponible.'); }
        try {
            if (!flock($handle, LOCK_EX)) { throw new \RuntimeException('Control de intentos no disponible.'); }
            $json = stream_get_contents($handle);
            $entries = $json === '' ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($entries)) { throw new \RuntimeException('Control de intentos inválido.'); }
            $entries = array_filter($entries, static fn(array $entry): bool => $entry['until'] > $now);
            $keys = [hash('sha256', 'ip:' . $ip)=>20, hash('sha256', 'account:' . mb_strtolower(trim($identifier), 'UTF-8'))=>5];
            $retry = 0;
            foreach ($keys as $key=>$limit) {
                if (($entries[$key]['count'] ?? 0) >= $limit) { $retry = max($retry, $entries[$key]['until'] - $now); }
            }
            if ($retry > 0) { return ['limited'=>true, 'retry'=>$retry, 'user'=>null]; }
            if (count($entries) > 10000) { throw new \RuntimeException('Control de intentos saturado.'); }
            foreach ($keys as $key=>$limit) {
                $entries[$key] ??= ['count'=>0, 'until'=>$now+900];
                ++$entries[$key]['count'];
            }
            $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new \RuntimeException('Control de intentos no disponible.');
            }
            // Se contabiliza antes de verificar, incluso si la conexión falla. No depende de cookies.
            return ['limited'=>false, 'retry'=>0, 'user'=>$authenticate()];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
