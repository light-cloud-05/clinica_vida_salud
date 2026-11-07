<?php
declare(strict_types=1);
namespace Clinica\Services;

interface MailTransport
{
    public function send(string $recipient, string $subject, string $body): void;
}
