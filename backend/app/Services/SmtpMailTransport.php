<?php
declare(strict_types=1);
namespace Clinica\Services;

final class SmtpMailTransport implements MailTransport
{
    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private string $from,
        private string $encryption = 'tls'
    ) {
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host) || $port < 1 || $port > 65535
            || !in_array($encryption, ['tls','ssl'], true) || !filter_var($from, FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n]/', $from)) {
            throw new \RuntimeException('Configuracion SMTP invalida.');
        }
    }

    public function send(string $recipient, string $subject, string $body): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $recipient.$subject)) {
            throw new \RuntimeException('Destinatario o cabecera SMTP invalidos.');
        }
        $prefix = $this->encryption === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$this->host]]);
        $socket = @stream_socket_client($prefix.$this->host.':'.$this->port, $code, $message, 5, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            throw new \RuntimeException('No se pudo conectar al transporte SMTP.');
        }
        try {
            stream_set_timeout($socket, 10);
            $this->expect($socket, 220);
            $this->command($socket, 'EHLO clinica-vida-salud', 250);
            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('No se pudo negociar TLS SMTP.');
                }
                $this->command($socket, 'EHLO clinica-vida-salud', 250);
            }
            if ($this->username !== '') {
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode($this->username), 334);
                $this->command($socket, base64_encode($this->password), 235);
            }
            $this->command($socket, 'MAIL FROM:<'.$this->from.'>', 250);
            $this->command($socket, 'RCPT TO:<'.$recipient.'>', 250);
            $this->command($socket, 'DATA', 354);
            $headers = "From: {$this->from}\r\nTo: {$recipient}\r\nSubject: ".mb_encode_mimeheader($subject, 'UTF-8')."\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            $body = str_replace(["\r\n", "\r"], "\n", $body);
            $body = preg_replace('/^\./m', '..', $body);
            $this->write($socket, $headers."\r\n".str_replace("\n", "\r\n", (string)$body)."\r\n.\r\n");
            $this->expect($socket, 250);
            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private function command($socket, string $command, int $expected): void
    {
        $this->write($socket, $command."\r\n");
        $this->expect($socket, $expected);
    }

    private function write($socket, string $data): void
    {
        while ($data !== '') {
            $written = fwrite($socket, $data);
            if ($written === false || $written === 0) { throw new \RuntimeException('Conexion SMTP interrumpida.'); }
            $data = substr($data, $written);
        }
    }

    private function expect($socket, int $expected): void
    {
        $line = fgets($socket);
        if ($line === false || (int)substr($line, 0, 3) !== $expected) {
            throw new \RuntimeException('El servidor SMTP rechazó la operación.');
        }
        while (isset($line[3]) && $line[3] === '-') {
            $line = fgets($socket);
            if ($line === false) {
                throw new \RuntimeException('Respuesta SMTP incompleta.');
            }
        }
    }
}
