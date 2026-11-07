<?php
declare(strict_types=1);
namespace Clinica\Services;

final class LocalMailTransport implements MailTransport
{
    public function send(string $recipient, string $subject, string $body): void
    {
        error_log('Correo simulado de Clínica Vida Salud preparado para '.$recipient.' con asunto: '.$subject);
    }
}
