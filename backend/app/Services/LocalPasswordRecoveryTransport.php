<?php
declare(strict_types=1);
namespace Clinica\Services;

final class LocalPasswordRecoveryTransport implements PasswordRecoveryTransport
{
    public function __construct(private string $directory) {}
    public function send(string $recipient, string $token): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Transporte local de correo no disponible.');
        }
        $file = $this->directory . '/message-' . bin2hex(random_bytes(12)) . '.txt';
        $message = "Destinatario: $recipient\nEnlace: /restablecer-contrasena?token=" . rawurlencode($token) . "\n";
        if (file_put_contents($file, $message, LOCK_EX) === false) { throw new \RuntimeException('No se pudo guardar el correo de prueba.'); }
        chmod($file, 0600);
    }
}
