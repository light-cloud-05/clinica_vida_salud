<?php
declare(strict_types=1);
namespace Clinica\Services;

final class PasswordService
{
    public static function hash(string $password): string
    {
        // Límite en bytes: evita truncamiento silencioso con bcrypt (PASSWORD_DEFAULT en PHP 8.1).
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new \InvalidArgumentException('Use una contraseña de 12 a 72 bytes, sin caracteres nulos.');
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
