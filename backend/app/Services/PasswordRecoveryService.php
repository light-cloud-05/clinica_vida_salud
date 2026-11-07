<?php
declare(strict_types=1);
namespace Clinica\Services;
use Clinica\Repositories\PasswordRecoveryStore;

final class PasswordRecoveryService
{
    public function __construct(private PasswordRecoveryStore $store, private PasswordRecoveryTransport $transport) {}
    public function request(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email, 'UTF-8') > 160) { return; }
        $user = $this->store->userByEmail($email);
        if (!$user || $this->store->activeRequests((int)$user['id_usuario']) >= 3) { return; }
        $token = bin2hex(random_bytes(32));
        $this->store->create((int)$user['id_usuario'], hash('sha256', $token), new \DateTimeImmutable('+30 minutes'));
        $this->transport->send($user['correo_acceso'], $token);
    }
    public function reset(string $token, string $password, string $confirmation): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token) || strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0") || $password !== $confirmation) {
            return false;
        }
        return $this->store->reset(hash('sha256', $token), $password);
    }
}
