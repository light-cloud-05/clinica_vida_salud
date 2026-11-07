# CU20 — Mi perfil

## Ruta y permisos

`GET/POST /mi-perfil` requiere autenticación, rol `Paciente` y permiso `gestionar_perfil_propio`. La consulta siempre usa `pacientes.id_usuario` igual al usuario de sesión; no acepta un `id_paciente` del navegador.

El perfil muestra código, documento, nombre, nacimiento y sexo como datos no editables. Permite editar correo de acceso, teléfono, correo de contacto, dirección, contacto de emergencia, tipo de sangre, alergias y antecedentes relevantes, todos presentes en el esquema real. Se rechazan campos no permitidos y se validan longitudes, codificación y correos.

La edición de usuario y paciente se realiza en una sola transacción y conserva identificación y estado. `PATIENT_PROFILE_WRITES_ENABLED=false` por defecto; con la bandera apagada la pantalla es de consulta y el POST devuelve 423 sin cambiar datos.

## Verificación

`tests/cu15-cu16-cu20.php` comprueba lectura solo del usuario propio, actualización validada y rutas integradas mediante un repositorio simulado. No se modificaron pacientes ni usuarios de PostgreSQL.
