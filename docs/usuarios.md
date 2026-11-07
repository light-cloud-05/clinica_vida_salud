# P7 — Administración de usuarios y roles

## Alcance implementado

El módulo está disponible en `/usuarios` y solo se publica para una sesión con rol
`Administrador` y permiso `gestionar_usuarios`. El panel permite:

- Buscar por nombre de usuario o correo, filtrar por rol/estado y paginar.
- Consultar datos administrativos, estado, relaciones opcionales con paciente/médico
  y permisos efectivos.
- Ver los permisos de los cuatro roles existentes en PostgreSQL.
- Abrir formularios de alta y edición, con validación en PHP, CSRF, `password_hash()`,
  confirmación de contraseña y control de duplicados.
- Cambiar el estado mediante el formulario sin borrar físicamente cuentas.

Las rutas GET y POST están protegidas en el enrutador y las comprobaciones se repiten
en el servicio. No se muestran `contrasena_hash`. La cuenta del actor no puede
modificarse desde este módulo y no se puede dejar sin administradores activos a la
clínica. Una cuenta vinculada a `pacientes` o `medicos` no puede cambiar a un rol
incompatible.

## Esquema contrastado

La inspección de solo lectura confirmó `usuarios`, `roles`, `permisos` y
`rol_permiso`, los cuatro roles `Administrador`, `Recepcionista`, `Medico` y
`Paciente`, unicidad de `nombre_usuario` y `correo_acceso`, y relaciones opcionales
1:1 desde `pacientes.id_usuario` y `medicos.id_usuario`. Las cuentas de demostración
se consultaron sin revelar contraseñas y no se modificaron.

## Escrituras y pruebas

`USER_WRITES_ENABLED=false` por defecto. Con este valor, los formularios se pueden
validar y muestran claramente que el guardado real está bloqueado. La conexión de
escritura exige además la base autorizada `clinica_vida_salud`; no se habilitó ni se
ejecutó durante P7. No se hicieron migraciones, seeds, DDL, modificaciones de
`.env`, ni cambios en usuarios, pacientes, médicos, citas, pagos u horarios.

Pruebas ejecutadas:

- `php tools/inspect-auth-schema.php` (solo lectura y rollback).
- `php tools/inspect-medical-schema.php` y `php tools/inspect-patient-schema.php`
  (solo lectura y rollback).
- `php -l` en archivos PHP del módulo.
- `composer test` incluye `tests/usuarios.php`, que verifica validación, duplicados,
  hash verificable, CSRF/rutas protegidas, bloqueo de escrituras, relaciones,
  autoprotección y última cuenta administrativa usando simulaciones en memoria.

## Abrir en el navegador

Iniciar el servidor de desarrollo desde la raíz:

```powershell
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

Iniciar sesión con una cuenta Administrador existente y abrir
`http://127.0.0.1:8080/usuarios`. La navegación también aparece en el panel
`/administrador`. No se deben activar escrituras para la entrega sin una copia
aislada de PostgreSQL y autorización explícita.
