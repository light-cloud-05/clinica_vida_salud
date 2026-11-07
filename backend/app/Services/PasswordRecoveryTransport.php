<?php
declare(strict_types=1);
namespace Clinica\Services;

interface PasswordRecoveryTransport
{
    public function send(string $recipient, string $token): void;
}
