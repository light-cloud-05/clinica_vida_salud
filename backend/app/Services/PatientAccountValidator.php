<?php
declare(strict_types=1);
namespace Clinica\Services;

final class PatientAccountValidator
{
    public static function validate(array $input): array
    {
        $errors = [];
        $fields = [
            'nombre_usuario' => ['Nombre de usuario', 80],
            'correo_acceso' => ['Correo electrónico', 160],
            'codigo_paciente' => ['Código de paciente', 30],
            'documento_identidad' => ['Documento de identidad', 30],
            'nombres' => ['Nombres', 100],
            'apellidos' => ['Apellidos', 100],
        ];
        $data = [];
        foreach ($fields as $field => [$label, $max]) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                $errors[$field] = 'El valor no es válido.';
                $data[$field] = '';
                continue;
            }
            $data[$field] = trim($value);
            if ($data[$field] === '') { $errors[$field] = $label . ' es obligatorio.'; }
            elseif (mb_strlen($data[$field], 'UTF-8') > $max) { $errors[$field] = "Use como máximo $max caracteres."; }
        }
        if ($data['correo_acceso'] !== '' && !filter_var($data['correo_acceso'], FILTER_VALIDATE_EMAIL)) {
            $errors['correo_acceso'] = 'Ingrese un correo electrónico válido.';
        }
        foreach (['contrasena' => 'Contraseña', 'contrasena_confirmacion' => 'Confirmación'] as $field => $label) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || strlen($value) > 72 || str_contains($value, "\0")) {
                $errors[$field] = "$label no es válida.";
            }
        }
        $password = is_string($input['contrasena'] ?? null) ? $input['contrasena'] : '';
        $confirmation = is_string($input['contrasena_confirmacion'] ?? null) ? $input['contrasena_confirmacion'] : '';
        if ($password !== '' && strlen($password) < 8) { $errors['contrasena'] = 'Use al menos 8 caracteres.'; }
        if ($password !== $confirmation) { $errors['contrasena_confirmacion'] = 'Las contraseñas no coinciden.'; }
        return ['data' => $data + ['hash' => password_hash($password, PASSWORD_DEFAULT)], 'errors' => $errors];
    }
}
