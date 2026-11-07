<?php
declare(strict_types=1);
namespace Clinica\Services;

final class UserAdminValidator
{
    public static function id(mixed $value): ?int { return \Clinica\Support\Input::positiveId($value); }

    public static function validate(array $input, array $roles, bool $editing): array
    {
        $errors = [];
        $allowed = ['nombre_usuario','correo_acceso','id_rol','estado','contrasena','contrasena_confirmacion','csrf','version'];
        if (array_diff(array_keys($input), $allowed)) { $errors['_form'] = 'El formulario incluye campos no permitidos.'; }
        $username = $input['nombre_usuario'] ?? '';
        $email = $input['correo_acceso'] ?? '';
        $role = $input['id_rol'] ?? '';
        $status = $input['estado'] ?? '';
        foreach (['nombre_usuario' => [$username, 'Nombre de usuario', 80], 'correo_acceso' => [$email, 'Correo de acceso', 160]] as $field => $spec) {
            [$value, $label, $max] = $spec;
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) { $errors[$field] = 'El valor no es válido.'; continue; }
            ${$field} = trim($value);
            if (${$field} === '') { $errors[$field] = "$label es obligatorio."; }
            elseif (mb_strlen(${$field}, 'UTF-8') > $max) { $errors[$field] = "Use como máximo $max caracteres."; }
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['correo_acceso'] = 'Ingrese un correo electrónico válido.'; }
        $roleId = filter_var($role, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $roleIds = array_map(static fn(array $item): int => (int)$item['id_rol'], $roles);
        if ($roleId === false || !in_array((int)$roleId, $roleIds, true)) { $errors['id_rol'] = 'Seleccione un rol válido.'; $roleId = 0; }
        if (!in_array($status, ['activo','inactivo'], true)) { $errors['estado'] = 'Seleccione un estado válido.'; }
        $password = $input['contrasena'] ?? '';
        $confirmation = $input['contrasena_confirmacion'] ?? '';
        if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0")) { $errors['contrasena'] = 'La contraseña no es válida.'; }
        elseif ($password !== '' && strlen($password) < 8) { $errors['contrasena'] = 'Use al menos 8 caracteres.'; }
        elseif ($password !== $confirmation) { $errors['contrasena_confirmacion'] = 'Las contraseñas no coinciden.'; }
        elseif (!$editing && $status === 'activo' && $password === '') { $errors['contrasena'] = 'Una cuenta activa requiere contraseña.'; }
        $data = ['role' => (int)$roleId, 'username' => $username, 'email' => $email, 'status' => $status];
        if ($password !== '') { $data['hash'] = password_hash($password, PASSWORD_DEFAULT); }
        return ['data' => $data, 'errors' => $errors];
    }
}
