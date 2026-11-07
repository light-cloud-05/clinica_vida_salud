# Autenticación de Clínica Vida Salud

Nota de continuidad: los resultados de cuentas inactivas de este documento describen la entrega original de autenticación. El usuario confirmó después el acceso manual de los cuatro roles; la etapa P2 utiliza esas cuentas ya activas sin modificarlas. Las pruebas actuales de integración admiten ese estado y comprueban también el rechazo de otra cuenta inactiva. Ver docs/pacientes.md.

## Alcance entregado

Inicio con nombre de usuario o correo, verificación mediante password_verify, rechazo de cuentas inactivas, cierre por POST, protección CSRF y cuatro pantallas iniciales. Basado en RF01, CU01 y CU02 del texto de PARCIAL_2_DOCUMENTACION.docx y en las cuatro capas descritas por el documento de implementación. Se revisaron esas secciones textuales; no se verificaron diagramas incrustados.

Antes de escribir consultas se inspeccionaron columnas, claves, restricciones y triggers de public.usuarios, roles, permisos y rol_permiso con SELECT. Campos reales de usuarios: id_usuario, id_rol, nombre_usuario, correo_acceso, contrasena_hash, estado, created_at y updated_at. PostgreSQL exige hash no nulo para estado activo. En la BD el rol médico se llama Medico; la interfaz lo muestra como Médico. No se crearon ni modificaron estructuras SQL.

## Uso web

Desde la raíz del proyecto:

```powershell
composer assets
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

Abrir http://127.0.0.1:8080/login. También hay un enlace desde el inicio. Para Apache/XAMPP mantener únicamente public como DocumentRoot, según README. No se cambiaron Apache, php.ini ni .env. Bootstrap requiere la CDN para su presentación completa.

Las rutas iniciales y sus controles de servidor son:

| Ruta | Rol exacto en BD | Permiso existente requerido |
|---|---|---|
| /administrador | Administrador | gestionar_usuarios |
| /recepcionista | Recepcionista | consultar_agenda |
| /medico | Medico | consultar_agenda |
| /paciente | Paciente | gestionar_perfil_propio |

Las pantallas muestran solo la identidad autenticada, texto introductorio y los permisos reales de su propio rol. No consultan ni muestran filas de otros usuarios, citas ni datos clínicos. No aceptan identificadores ni roles enviados en URL/formulario para elegir la identidad. Sin sesión se redirige a /login; con rol o permiso incorrecto se responde 403. No hay módulos operativos detrás de estas pantallas todavía.

## Controles implementados

- Consultas preparadas nativas PDO; toda conexión web de autenticación permanece READ ONLY. Búsqueda por usuario/correo sin distinguir mayúsculas; si la coincidencia resulta ambigua entre cuentas se rechaza.
- Sesión con cookie HttpOnly y SameSite=Lax, exclusivamente por cookies y strict_mode. Cookie Secure cuando la conexión directa usa HTTPS; HTTP se admite para desarrollo local. No se confía en cabeceras de proxy para decidir HTTPS. En despliegue configurar HTTPS y comprobar Secure; no publicar por HTTP.
- Regeneración y eliminación del identificador anterior al entrar; renovación CSRF. Cierre por POST con CSRF, destrucción de sesión y caducidad de cookie. Datos almacenados fuera de public, en var/sessions.
- Inactividad máxima de 30 minutos y duración absoluta de 8 horas (políticas técnicas iniciales de esta entrega, no requisitos atribuidos a la documentación). Se vuelven a consultar estado, rol y permisos en cada solicitud protegida. Un cambio de contraseña o desactivación invalida el acceso posterior. Los hashes no se guardan en la sesión; solo una huella para detectar cambios de credencial.
- CSRF en acceso y salida; 419 ante token inválido, 401 ante acceso rechazado. Mensaje común para inexistente, inactivo, sin contraseña o clave errónea. Las excepciones no exponen configuración, trazas ni hashes al navegador.
- Máximo de 5 intentos por identificador y 20 por IP en ventanas de 15 minutos; incluye intentos exitosos y fallidos. Son límites técnicos iniciales. No dependen de la cookie ni actualizan PostgreSQL. Estado compartido con flock en var/auth/attempts.json, sin contraseñas, nombres de usuario ni IP en claro. 429 y Retry-After cuando corresponde. La prueba HTTP consume un intento real de usuario1 por ejecución; esperar el vencimiento si se repite muchas veces.
- Carpeta var excluida de Git. En Windows conceder acceso NTFS solo al usuario local/servicio Apache que ejecute PHP y a administradores del equipo; PHP 0700 por sí solo no configura las ACL de Windows. El bloqueo de archivo está pensado para una instalación única, no varios servidores.
- No se hace rehash automático ni se escriben fechas de último acceso. No se alteran .env ni hashes existentes al iniciar sesión.

## Herramienta CLI y autorización pendiente

**No se ejecutó configuración de contraseñas ni activación.** La herramienta está preparada para la siguiente etapa, cuando el usuario la autorice. No pedir ni enviar contraseñas por chat: deben introducirse personalmente en la terminal local de VS Code o PowerShell.

Ayuda sin cambios ni conexión:

```powershell
php tools/account.php --help
```

Tras autorizar la configuración, asignar una contraseña distinta y personal a cada cuenta existente:

```powershell
php tools/account.php password usuario1
php tools/account.php password usuario2
php tools/account.php password profesional01
php tools/account.php password paciente01
```

Cada ejecución pide la clave dos veces, oculta y sin asteriscos, y después exige escribir CONFIGURAR seguido del nombre exacto (por ejemplo CONFIGURAR usuario1). Usar 12 a 72 bytes UTF-8 y preferir una frase larga y única; el límite superior evita truncamiento de bcrypt en PHP 8.1. La herramienta usa password_hash(PASSWORD_DEFAULT). No pasar contraseñas en argumentos, archivos, variables del shell ni registros. El comando password conserva el estado: una cuenta inactiva continúa inactiva.

Solo después de que se autorice activar las cuentas, ejecutar por separado:

```powershell
php tools/account.php activate usuario1
php tools/account.php activate usuario2
php tools/account.php activate profesional01
php tools/account.php activate paciente01
```

Cada ejecución exige ACTIVAR seguido del nombre exacto. Solo se permite activar estas cuatro cuentas, con rol esperado, estado inactivo y hash válido previo. La CLI funciona solo con APP_ENV=local y terminal Windows interactiva; no acepta contraseñas por redirección. No hay endpoint web de activación ni registro público. No se activa ninguna cuenta adicional.

La lectura oculta usa un auxiliar PowerShell fijo mediante pipe anónimo en memoria, sin credenciales en argumentos ni archivos. El auxiliar usa -NoProfile y -ExecutionPolicy Bypass únicamente en su proceso hijo porque Windows PowerShell tiene política Restricted en este equipo; no modifica la política global ni de usuario. Si no hay terminal segura o no se puede leer la entrada oculta, falla sin sustituirla por entrada visible. Escape cancela la entrada. Los valores dejan de referenciarse tras generar el hash; no se garantiza borrado físico inmediato de toda la memoria del runtime.

Las operaciones de mantenimiento comprueban que la base conectada sea clinica_vida_salud, seleccionan una cuenta existente por nombre exacto, piden confirmación, bloquean/revalidan la fila y actualizan solo hash o estado en una transacción. Los triggers existentes actualizarán updated_at y añadirán su registro de auditoría habitual (sin hash/contraseña en los detalles del trigger inspeccionado). Estos efectos serán parte de la futura operación autorizada; no se deshabilitan triggers. No se tocan citas, pacientes, médicos ni otras cuentas. No se ejecutan migraciones/seeds.

## Pruebas ejecutadas y límites

```powershell
composer test
composer test:auth-db
pwsh -NoProfile -File tests/http-auth.ps1
php tests/console-secret.php
composer validate --strict
composer check-platform-reqs
Get-ChildItem backend,frontend,public,tests,tools -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Resultados reales:

- 11 comprobaciones de infraestructura, 47 de autenticación/autorización y 8 de sesión: pasaron. Las pruebas positivas usan cuentas y hashes aleatorios exclusivamente en memoria, con 16 combinaciones de roles, ausencia de permiso, CSRF, escape HTML, expiración, invalidación por desactivación/cambio de clave y límites por cuenta/IP. No modifican PostgreSQL.
- Integración real mediante PDO en transacción READ ONLY: las cuatro cuentas existen, tienen el rol esperado, siguen inactivas y fueron rechazadas. Búsqueda real por correo y consultas parametrizadas correctas; permisos obtenidos de rol_permiso/permisos. Huella interna de los registros de usuarios sin cambios durante la prueba, no impresa.
- Estado comprobado al finalizar la integración: 51 usuarios, 0 activos y 0 con hash; 30 pacientes, 18 médicos y 60 citas.
- 20 comprobaciones HTTP pasaron: formulario, cookies, no-store, CSRF, rechazo real de usuario1 inactivo, cuatro rutas protegidas sin sesión, logout exclusivamente por POST y CSRF, invalidación de token y archivos privados inaccesibles. Primer intento del script HTTP tuvo un error de captura de redirecciones en el propio test; corregido y repetido con éxito.
- Entrada oculta comprobada interactivamente con texto temporal de prueba, sin cuenta ni conexión; confirmación correcta, sin imprimir ni guardar el texto. Primero se detectó la política Restricted y se corrigió el alcance del proceso auxiliar. También se comprobó sintaxis PowerShell y la ayuda CLI. No se ejecutaron los UPDATE de mantenimiento, ni siquiera para luego hacer ROLLBACK.
- Composer válido, requisitos PHP/PDO/PDO_PGSQL/mbstring cumplidos y lint PHP correcto. Se actualizó únicamente la metadata del lock sin red ni paquetes nuevos para declarar mbstring ya instalado.

Pendiente: login exitoso y navegación entre roles con las cuatro cuentas reales después de su activación autorizada; validación del VirtualHost Apache, cookies bajo HTTPS e inspección visual en navegador en escritorio/móvil. La lógica de éxito, regeneración y permisos ya se probó aisladamente sin cambiar cuentas existentes. No se implementaron reservas ni pagos.

## Archivos de esta etapa

Creados:

- backend/app/Controllers/AuthController.php y DashboardController.php.
- backend/app/Repositories/AuthRepository.php y UserRepository.php.
- backend/app/Services/AuthService.php, SessionService.php, LoginThrottle.php y PasswordService.php.
- backend/app/Middleware/AuthMiddleware.php y AuthorizationMiddleware.php.
- backend/app/Support/AuthFactory.php y ConsoleSecret.php.
- frontend/views/auth/login.php y frontend/views/dashboard.php.
- tools/account.php, tools/read-secret.ps1 y tools/inspect-auth-schema.php.
- tests/auth.php, tests/session.php, tests/auth-database.php, tests/http-auth.ps1 y tests/console-secret.php.
- docs/autenticacion.md.

Modificados: AGENTS.md (alcance autorizado actual), README.md, composer.json, composer.lock, backend/app/Repositories/Connection.php (conexión de mantenimiento exclusivamente CLI), backend/routes/web.php, public/index.php, frontend/views/home.php, frontend/assets/css/app.css, frontend/assets/js/app.js y sus copias public/assets/css/app.css y public/assets/js/app.js.

No modificados: .env, .gitignore y la configuración de conexión existente de backend/config/database.php y Support/Environment.php. .env sigue ignorado y no versionado.
