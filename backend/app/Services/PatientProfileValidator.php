<?php
declare(strict_types=1);
namespace Clinica\Services;
final class PatientProfileValidator
{
    public const FIELDS = [
        'correo_acceso' => ['Correo de acceso', 160, true],
        'telefono' => ['Teléfono', 30, false],
        'correo_contacto' => ['Correo de contacto', 160, false],
        'direccion' => ['Dirección', null, false],
        'contacto_emergencia_nombre' => ['Nombre del contacto de emergencia', null, false],
        'contacto_emergencia_parentesco' => ['Parentesco del contacto', null, false],
        'contacto_emergencia_telefono' => ['Teléfono de emergencia', 30, false],
        'tipo_sangre' => ['Tipo de sangre', 20, false],
        'alergias' => ['Alergias', null, false],
        'antecedentes_relevantes' => ['Antecedentes relevantes', null, false],
    ];
    public static function validate(array $input): array
    {
        $errors = []; $data = [];
        if (array_diff(array_keys($input), array_merge(array_keys(self::FIELDS), ['csrf']))) { $errors['_form'] = 'El formulario incluye campos no permitidos.'; }
        foreach (self::FIELDS as $field => [$label, $max, $required]) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) { $errors[$field] = 'El valor no es válido.'; continue; }
            $value = trim($value); $data[$field] = $value === '' && !$required ? null : $value;
            if ($required && $value === '') { $errors[$field] = "$label es obligatorio."; }
            elseif ($max !== null && mb_strlen($value, 'UTF-8') > $max) { $errors[$field] = "Use como máximo $max caracteres."; }
        }
        foreach (['correo_acceso', 'correo_contacto'] as $field) {
            if ($data[$field] !== null && !filter_var($data[$field], FILTER_VALIDATE_EMAIL)) { $errors[$field] = 'Ingrese un correo electrónico válido.'; }
        }
        return ['data' => $data, 'errors' => $errors];
    }
}
