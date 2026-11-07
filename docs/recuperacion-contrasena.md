# CU16 — Recuperación de contraseña

## Rutas y flujo

- `GET/POST /recuperar-contrasena`: solicita instrucciones.
- `GET/POST /restablecer-contrasena`: presenta y consume el token.

La respuesta de solicitud es neutra exista o no el correo. El token tiene 32 bytes aleatorios, se entrega únicamente al transporte, se almacena como SHA-256 en `recuperacion_contrasena.token_hash`, vence en 30 minutos y se consume una sola vez mediante `fecha_uso`. Se permiten como máximo tres tokens activos por cuenta.

El transporte seleccionado por `PASSWORD_RESET_TRANSPORT` es local por defecto. El transporte local escribe un mensaje de prueba con permisos 0600 en `var/private-mail/`, fuera de Git; no lo publica ni lo registra en logs. Ese mensaje no demuestra entrega externa. Si se selecciona otro transporte sin una implementación SMTP verificada, la aplicación falla explícitamente y no afirma que envió el correo.

El cambio de contraseña actualiza `usuarios.contrasena_hash` con `password_hash`. Al cambiar el hash, las sesiones anteriores dejan de ser válidas porque `AuthService` compara la versión de credencial en cada solicitud. `PASSWORD_RECOVERY_WRITES_ENABLED=false` por defecto.

## Verificación

La prueba simulada cubre correo inexistente, hash distinto del token, token válido, reutilización, formato inválido, confirmación y rutas. No se insertaron tokens en PostgreSQL.
