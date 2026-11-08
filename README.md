# Clínica Vida Salud

Sistema de citas médicas con PHP nativo, PostgreSQL, Bootstrap 5 y Chart.js. Incluye autenticación y recuperación SMTP, pacientes, médicos/especialidades, horarios, reservas, reprogramación y cancelación, asistencia, historial clínico, reportes PDF/CSV, auditoría, notificaciones y consultas por voz. Los pagos son exclusivamente académicos; no se habilitan cobros reales.

URL pública: https://clinica-vida-salud-vgve.onrender.com/ . Estado y evidencia de esta revisión: [ENTREGA_FINAL](docs/ENTREGA_FINAL.md). Las escrituras requieren sus flags independientes y autorización de producción; que una función exista no implica que esté habilitada en Render. No reimportar el esquema ni repetir el seed.

## Requisitos y preparación

Desde PowerShell en `D:\Proyectos\clinica_vida_salud`:

```powershell
php -v
php -m
composer --version
git --version
composer install
composer assets
Copy-Item .env.example .env
```

Editar `.env` localmente con host, puerto, usuario y contraseña existentes de PostgreSQL. No pegar credenciales en chats, capturas ni archivos versionados. El lector de entorno admite `CLAVE=valor`, comillas simples/dobles y comentarios en líneas completas; no interpola variables ni procesa escapes. Las variables del proceso tienen prioridad. Se fija America/La_Paz en PHP y en cada conexión PostgreSQL.

PDO, PDO_PGSQL y mbstring son obligatorios y están disponibles en el PHP CLI comprobado. Revisar también el php.ini utilizado por Apache y reiniciar Apache después de cualquier cambio manual. No se cambió php.ini. `pgsql` no es necesario al usar PDO. La advertencia ZIP observada en la primera entrega ya no aparece en las comprobaciones actuales.

## Abrir la página

Prueba temporal sin cambiar Apache:

```powershell
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

Abrir [Clínica Vida Salud](http://127.0.0.1:8080/) o [Iniciar sesión](http://127.0.0.1:8080/login). Detener con Ctrl+C. El servidor integrado es exclusivamente de desarrollo. Las cuentas inactivas no pueden acceder; no se activan automáticamente.

Para Apache/XAMPP, revisar `docs/apache-vhost.example.conf`: configurar manualmente un VirtualHost con DocumentRoot apuntando exclusivamente a `public/`, acceso local y mod_rewrite activo. Integrar el ejemplo en la configuración de VirtualHosts de XAMPP, evitar duplicar Listen y comprobar con `C:\xampp\apache\bin\httpd.exe -t` antes de reiniciar Apache desde XAMPP. No se instala automáticamente ni se cambia configuración global. No crear un Alias hacia la raíz del proyecto ni copiar el proyecto entero a un directorio público. La raíz debe quedar denegada y solo public autorizada. Abrir la misma URL una vez activo el puerto 8080.

Bootstrap 5.3.3 se carga desde CDN con integridad SRI: requiere Internet para el diseño completo. Los estilos propios y JS se sirven localmente; editar frontend/assets y ejecutar `composer assets` para copiar a public/assets. No se necesitan Node ni npm.

## Diagnóstico protegido y de solo lectura

Desactivado por defecto. Configurar `APP_ENV=local`, `DB_CHECK_ENABLED=true` y un `DB_CHECK_TOKEN` aleatorio de al menos 32 caracteres en `.env`. Puede generarse con `php -r "echo bin2hex(random_bytes(32));"`. No incluir el token en URLs.

Desde PowerShell, con el servidor iniciado:

```powershell
$secret = Read-Host 'Token local'
Invoke-RestMethod -Uri http://127.0.0.1:8080/diagnostico/bd -Headers @{ 'X-Diagnostic-Token' = $secret }
Remove-Variable secret
```

Solo admite GET desde 127.0.0.1 o ::1, entorno local y token válido; en otro caso devuelve 404. No usar detrás de proxies que conviertan accesos remotos en conexiones loopback. Desactivar al terminar. No sustituye la futura autorización por roles.

La respuesta muestra conexión, número y nombres de tablas visibles del esquema configurado. 200 indica conexión y exactamente 21 tablas; 503 indica discrepancia o fallo de conexión/configuración. No certifica estructura, firmas SQL, permisos ni integridad de datos: 21 tablas no garantiza sus identidades. La visibilidad depende de los permisos del usuario PostgreSQL. No se crea ningún rol SQL ni se otorgan permisos. Usar una cuenta existente de privilegios mínimos si está disponible; no introducir superusuario para uso habitual.

La conexión establece transacciones READ ONLY, zona horaria y límites de tiempo de sesión. El diagnóstico usa SELECT parametrizado sobre information_schema y revierte su transacción. No consulta filas de pacientes/usuarios ni llama funciones de negocio. No ejecuta DDL, migraciones, seeds o modificaciones, ni siquiera pruebas temporales sobre datos.

## Organización y verificación

- Presentación: frontend/views y frontend/assets; public es la única raíz web.
- Aplicación: backend/routes, Controllers y Middleware.
- Negocio: Services.
- Persistencia: Repositories y Models; configuración en backend/config.
- database/schema, migrations y seeds son únicamente directorios reservados, sin scripts ejecutables.
- docs/uml reservado para diagramas; tests para comprobaciones.

```powershell
composer validate --strict
composer test
Get-ChildItem backend,frontend,public,tests,tools -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Git se inicializa localmente sin commits automáticos ni remoto. Antes de publicar, revisar `git status` y `git check-ignore .env`. No se creó repositorio GitHub: falta definir cuenta, nombre y remoto. Versionar composer.lock y los recursos públicos.

## Documentación y siguiente etapa

La preparación para Render (Docker Web Service y PostgreSQL separado) está en
[docs/render.md](docs/render.md). No se incluyen credenciales, dumps ni
migraciones inventadas; la inicialización requiere un dump de demostración
aislado y autorizado.

Se extrajo texto OOXML de los dos DOCX de docs/requisitos y se revisaron las secciones relevantes de implementación, requisitos y actores. No se validaron diagramas/imágenes incrustados ni diseño visual. Una salida extensa de extracción se truncó; no se afirma revisión íntegra de todo su contenido. No hay scripts SQL independientes disponibles en la carpeta. Los nombres de entidades citados por el diseño no se toman como prueba del esquema instalado.

La etapa de autenticación verificó el esquema real y RF01/CU01/CU02. El usuario confirmó posteriormente el acceso manual a los cuatro roles. P2 verificó RF05/CU04 y el esquema de pacientes, y reutiliza las cuentas existentes sin modificarlas. Abrir /pacientes con Administrador o Recepcionista con gestionar_pacientes. La escritura permanece deshabilitada; ver docs/pacientes.md para resultados reales, límites y acciones pendientes de autorización. No activar ni cambiar cuentas desde este módulo.

P3 verificó RF06/RF07, CU05/CU06 y las tablas medicos, especialidades y medico_especialidad. Abrir /medicos o /especialidades como Administrador con gestionar_medicos. PostgreSQL devuelve 18 médicos y 9 especialidades. MEDICAL_WRITES_ENABLED es false por defecto e independiente del bloqueo de pacientes. No se modificó .env ni se realizaron escrituras reales durante esta entrega.

P4 está cerrado con 90 horarios reales, 0 bloqueos y disponibilidad contrastada con PostgreSQL. Abrir /horarios, /horarios/agenda o /bloqueos: Administrador gestiona según permisos, Recepcionista consulta y Médico consulta solo su ámbito vinculado a usuarios. SCHEDULE_WRITES_ENABLED sigue deshabilitado. El inventario, los resultados de las pruebas y las limitaciones están en [docs/horarios.md](docs/horarios.md).

P5-A permite consultar las 60 citas mediante /citas (personal) o /mis-citas (paciente titular), detalle, agenda diaria y disponibilidad. El acceso del Médico se limita a sus citas y el del Paciente a las propias mediante condiciones SQL. Las rutas /citas/disponibilidad y /mis-citas/disponibilidad solo informan; no retienen ni crean un turno. P5-C y P9 permanecen fuera de alcance. P5-B está implementado con escritura deshabilitada; ver [docs/reservas.md](docs/reservas.md). Ver [docs/citas.md](docs/citas.md).

## P5-C: cambios de citas

Reprogramación única y cancelación implementadas con escrituras bloqueadas. Instrucciones por rol, resultados y prueba aislada propuesta en [docs/cambios-citas.md](docs/cambios-citas.md). P9 permanece pendiente.
