<?php
declare(strict_types=1);
namespace Clinica\Services;

final class LocalMailTransport implements MailTransport
{
    public function send(string $recipient, string $subject, string $body): void
    {
        throw new \RuntimeException('SMTP no configurado: no se envio ningun correo.');
    }
}
