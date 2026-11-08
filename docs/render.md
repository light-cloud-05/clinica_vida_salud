# Despliegue en Render

> Estado actual: esquema YA importado. No repetir la inicialización ni generar
> contraseñas. Usar la sección «Continuar el despliegue actual: SOLO seed» al final.
> La credencial expuesta debe rotarse antes de continuar.

Este repositorio queda preparado para un **Web Service Docker**. No contiene
credenciales, `.env`, dumps de la base original ni seeds de usuarios reales.
La base de integración local tampoco debe conectarse al servicio público.

## 1. Crear PostgreSQL

1. En Render, crear un recurso **PostgreSQL Free** en la misma región que el
   servicio web.
2. Esperar a que el estado sea `Available`.
3. No copiar la contraseña al repositorio. Usar únicamente la **Internal
   Database URL** que Render muestra en el panel para el Web Service.
4. Conservar el nombre de la base y el usuario generados por Render. No asumir
   que la base se llama `clinica_vida_salud`.

La aplicación lee `DATABASE_URL` (`postgres://` o `postgresql://`) y respeta
`sslmode` de su query string. Si Render no lo incluye, usa `require` cuando
`DATABASE_URL` está presente. Sin esa variable, la configuración local sigue
usando `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` y
`DB_SSLMODE`.

## 2. Inicializar el esquema de forma segura

El esquema reproducible está en [`database/schema/render.sql`](../database/schema/render.sql)
y fue exportado con `pg_dump --schema-only` desde el PostgreSQL aislado
verificado. Fue generado con PostgreSQL 18.6; seleccione PostgreSQL 18 en
Render para conservar compatibilidad de servidor.

El seed reproducible está en [`tools/seed-demo.php`](../tools/seed-demo.php).
No contiene contraseñas ni hashes; genera los hashes al ejecutarse desde
variables de entorno privadas. Antes de habilitar la aplicación pública:

1. Revisar el SQL: contiene estructura, secuencias, índices, restricciones,
   triggers, funciones y dependencias, pero no filas.
2. Cargarlo desde una máquina administrativa usando la **External Database
   URL** de Render, nunca desde el contenedor web:

   ```powershell
   $renderUrl = Read-Host 'External Database URL de Render'
   pg_restore --dbname="$renderUrl" --no-owner --no-acl --single-transaction --exit-on-error .\demo.dump
   Remove-Variable renderUrl
   ```

   Para un SQL plano, usar `psql --dbname="$renderUrl" --set ON_ERROR_STOP=1
   --file=.\demo.sql`. No ejecutar estos comandos contra la base local
   `clinica_vida_salud`.
3. Generar cuatro contraseñas privadas de prueba (16–72 caracteres), definir
   `DEMO_SEED_CONFIRM=EMPTY_DATABASE_ONLY`, las cuatro variables
   `DEMO_*_PASSWORD`, `DEMO_SEED_TARGET=render`, `APP_ENV=production` y
   `DATABASE_URL`, `DEMO_RENDER_RESOURCE=clinica-vida-salud-db`,
   `DEMO_RENDER_DATABASE_NAME` y `DEMO_RENDER_DATABASE_HOST`, y ejecutar:

   ```powershell
   php tools/seed-demo.php
   ```

   El script rechaza la base original, la base de integración, destinos
   desconocidos y cualquier tabla demo que ya tenga filas. Las contraseñas se
   hashean en memoria con `password_hash()` y no se imprimen.
4. Conservar los secretos únicamente en el gestor de variables de Render o
   en el entorno administrativo temporal. No incluirlos en Git.

Para conservar las cuatro credenciales sin publicarlas, se usará
`$HOME\clinica-vida-salud-render-demo-secrets.clixml`. PowerShell 5.1 lo
cifra con DPAPI para el usuario actual de Windows. No moverlo al repositorio;
puede respaldarse manualmente en el gestor de contraseñas de la clínica.

### Comandos PowerShell 5.1

Ejecutar desde la raíz del repositorio, con `psql.exe` de PostgreSQL 18 en
`PATH`. Estos comandos guardan las contraseñas únicamente en ese archivo privado
cifrado y mantienen la URL solo en memoria de la sesión. No imprimen secretos,
no escriben credenciales en el repositorio y no repiten la importación si la
base ya contiene tablas:

```powershell
$privateSecrets = Join-Path $HOME 'clinica-vida-salud-render-demo-secrets.clixml'
$roles = @('ADMIN', 'RECEPTION', 'DOCTOR', 'PATIENT')
if (-not (Test-Path $privateSecrets)) {
    $generated = @{}
    $random = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        foreach ($role in $roles) {
            $bytes = New-Object byte[] 24
            $random.GetBytes($bytes)
            $generated[$role] = ConvertTo-SecureString ([Convert]::ToBase64String($bytes)) -AsPlainText -Force
        }
    } finally { $random.Dispose() }
    $generated | Export-Clixml -Path $privateSecrets
    Write-Output "Credenciales cifradas guardadas fuera del repositorio: $privateSecrets"
}

try {
   $secureUrl = Read-Host 'External Database URL de clinica-vida-salud-db' -AsSecureString
$urlPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureUrl)
try { $renderUrl = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($urlPointer) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($urlPointer) }
$renderUri = [Uri]$renderUrl
if ($renderUri.Scheme -notin @('postgres', 'postgresql') -or $renderUri.Host -in @('localhost', '127.0.0.1', '::1')) { throw 'URL Render no autorizada.' }
$renderDatabaseName = $renderUri.AbsolutePath.Trim('/')
$renderDatabaseHost = $renderUri.Host
$expectedDatabaseName = Read-Host 'Nombre de base mostrado en Render'
$expectedDatabaseHost = Read-Host 'Host mostrado en Render'
if ($expectedDatabaseName -ne $renderDatabaseName -or $expectedDatabaseHost -ne $renderDatabaseHost) { throw 'La URL no coincide con la base Render confirmada.' }
$identity = (& psql.exe --dbname=$renderUrl -X -A -t -F '|' -c "SELECT current_database(), inet_server_addr()::text").Trim()
if ($LASTEXITCODE -ne 0 -or $identity -notlike "$renderDatabaseName|*") { throw 'No se pudo verificar la base Render.' }
$parts = $identity -split '\|', 2
if ($parts[0] -ne $renderDatabaseName) { throw 'La base conectada no coincide con la URL.' }
$tableCount = (& psql.exe --dbname=$renderUrl -X -A -t -c "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'").Trim()
if ($LASTEXITCODE -ne 0 -or $tableCount -ne '0') { throw 'La base no está vacía; no se importará.' }

psql.exe --dbname=$renderUrl -X -v ON_ERROR_STOP=1 --file=database\schema\render.sql
if ($LASTEXITCODE -ne 0) { throw 'Falló la importación del esquema.' }

$env:APP_ENV = 'production'
$env:DATABASE_URL = $renderUrl
$env:DEMO_SEED_TARGET = 'render'
$env:DEMO_RENDER_RESOURCE = 'clinica-vida-salud-db'
$env:DEMO_RENDER_DATABASE_NAME = $renderDatabaseName
$env:DEMO_RENDER_DATABASE_HOST = $renderDatabaseHost
$env:DEMO_SEED_CONFIRM = 'EMPTY_DATABASE_ONLY'
try {
    $storedSecrets = Import-Clixml -Path $privateSecrets
    foreach ($role in $roles) {
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($storedSecrets[$role])
        try { $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
        Set-Item "Env:DEMO_${role}_PASSWORD" $plain
        Set-Item "Env:VERIFY_${role}_PASSWORD" $plain
    }
    php.exe tools\seed-demo.php
    if ($LASTEXITCODE -ne 0) { throw 'Falló el seed ficticio.' }
    php.exe tools\verify-demo.php
    if ($LASTEXITCODE -ne 0) { throw 'Falló la verificación de roles.' }
} finally {
    foreach ($role in $roles) {
        Remove-Item "Env:DEMO_${role}_PASSWORD", "Env:VERIFY_${role}_PASSWORD" -ErrorAction SilentlyContinue
    }
}

} finally {
    Remove-Item Env:DATABASE_URL,Env:DEMO_SEED_TARGET,Env:DEMO_RENDER_RESOURCE,Env:DEMO_RENDER_DATABASE_NAME,Env:DEMO_RENDER_DATABASE_HOST,Env:DEMO_SEED_CONFIRM,Env:APP_ENV -ErrorAction SilentlyContinue
}
```

La comprobación consulta identidad y vacío de la base sin mostrar la URL ni las
contraseñas. Si la URL no corresponde al recurso `clinica-vida-salud-db`, o la
base ya tiene tablas, el procedimiento se detiene antes del esquema y del seed.

## 3. Crear el Web Service

1. En Render elegir **New > Web Service**, conectar GitHub y seleccionar este
   repositorio y la rama que se vaya a publicar.
2. Elegir **Docker**. No usar Static Site.
3. Mantener el `Dockerfile` de la raíz y el contexto del repositorio.
4. Plan Free, región igual a PostgreSQL y un nombre público definitivo.
5. No configurar un Start Command alternativo: el contenedor usa Apache y el
   puerto `PORT` que Render inyecta (10000 por defecto).
6. Agregar estas variables en Render, sin comillas:

   | Variable | Valor |
   |---|---|
   | `APP_ENV` | `production` |
   | `DATABASE_URL` | Internal Database URL de Render PostgreSQL |
   | `DB_SCHEMA` | `public` |
   | `DB_CHECK_ENABLED` | `false` |
   | `PATIENT_WRITES_ENABLED` | `false` inicialmente; `true` tras validar |
   | `APPOINTMENT_WRITES_ENABLED` | `false` inicialmente; `true` tras validar |
   | `APPOINTMENT_CHANGES_ENABLED` | `false` inicialmente; `true` tras validar |

   Mantener desactivados los demás indicadores de escritura hasta comprobar
   cada módulo. En producción, una escritura habilitada exige simultáneamente
   `APP_ENV=production` y `DATABASE_URL`; en local continúa aplicándose la
   protección del nombre `clinica_vida_salud`, y en integración continúa
   aplicándose `IntegrationGuard`.
7. Crear el servicio y revisar los logs de build y runtime. No activar
   auto-deploy hasta aprobar la primera verificación.

## 4. Verificación funcional

Con la URL HTTPS asignada por Render:

1. Abrir `/` y `/login`; comprobar que CSS, JavaScript y Bootstrap cargan.
2. Probar login, logout, expiración de sesión y cookie `CVS_SESSION` con
   `Secure`, `HttpOnly` y `SameSite=Lax`.
3. Verificar por separado las cuatro cuentas ficticias autorizadas:
   **Administrador**, **Recepcionista**, **Médico** y **Paciente**. Confirmar
   que cada rol ve solo sus rutas y ámbito de registros.
4. Consultar citas, disponibilidad y una exportación CSV/PDF. Dompdf está
   incluido en la imagen y genera PDF sin recursos remotos.
5. Solo después de comprobar el flujo normal, habilitar escrituras del módulo
   que corresponda y probar una operación con datos ficticios nuevos.
6. No activar `DB_CHECK_ENABLED` en Internet: el diagnóstico está diseñado
   únicamente para loopback local.

Si el login falla, revisar primero que el esquema cargado corresponde al
esquema real inspeccionado y que `DATABASE_URL` es la **Internal** URL, no
una URL local ni la base de integración. No pegar URLs con credenciales en
issues, logs ni chats.

## Flags de escritura por módulo

Cada flag se configura solo en Render, nunca en `.env` local:

| Módulo | Variable |
|---|---|
| Pacientes | `PATIENT_WRITES_ENABLED` |
| Cuentas de pacientes | `PATIENT_ACCOUNT_WRITES_ENABLED` |
| Recuperación de contraseña | `PASSWORD_RECOVERY_WRITES_ENABLED` |
| Perfil de paciente | `PATIENT_PROFILE_WRITES_ENABLED` |
| Médicos/especialidades | `MEDICAL_WRITES_ENABLED` |
| Historial clínico | `MEDICAL_RECORD_WRITES_ENABLED` |
| Horarios | `SCHEDULE_WRITES_ENABLED` |
| Reservas | `APPOINTMENT_WRITES_ENABLED` |
| Reprogramación/cancelación | `APPOINTMENT_CHANGES_ENABLED` |
| Asistencia | `ATTENDANCE_WRITES_ENABLED` |
| Usuarios | `USER_WRITES_ENABLED` |
| Notificaciones | `NOTIFICATION_WRITES_ENABLED` |
| Pagos | `PAYMENT_WRITES_ENABLED` (no activar en esta entrega) |

## Continuar el despliegue actual: SOLO seed (7 noviembre 2026)

El esquema ya está importado. **No ejecutar la sección de inicialización anterior**.
No reutilizar la contraseña expuesta: rotarla en el panel PostgreSQL de Render
antes de copiar la nueva External Database URL. El script exige una confirmación
explícita de esa rotación; no puede demostrarla por sí solo.

### PHP CLI separado en Windows

XAMPP 8.1.25 carga libpq 11.4 (comprobado con `--ri pdo_pgsql` y `--ri pgsql`).
libpq incorporó SNI en PostgreSQL 14. `sslmode=require` activa TLS, pero no
agrega SNI a libpq antigua. No cambiar DLL de XAMPP, usar IP ni desactivar TLS.

1. Descargar el ZIP oficial de **PHP 8.3 x64 Non Thread Safe**, último parche
   disponible, desde https://www.php.net/downloads.php?os=windows . Comparar
   `Get-FileHash archivo.zip -Algorithm SHA256` con el SHA256 oficial.
2. Extraer el paquete COMPLETO a una carpeta independiente, por ejemplo
   `D:\Herramientas\php83-render`. No modificar PATH global ni XAMPP.
   Si falta el runtime de Visual C++, instalar el redistribuible x64 que
   indica la página oficial de PHP.
3. Copiar `php.ini-production` a `php.ini` DENTRO de esa carpeta. Configurar:

   ```ini
   extension_dir="D:\Herramientas\php83-render\ext"
   extension=pdo_pgsql
   extension=pgsql
   extension=mbstring
   extension=curl
   extension=bcmath
   ```

4. Abrir una consola nueva y comprobar ambos controladores:

   ```powershell
   & 'D:\Herramientas\php83-render\php.exe' --ini
   & 'D:\Herramientas\php83-render\php.exe' --ri pdo_pgsql
   & 'D:\Herramientas\php83-render\php.exe' --ri pgsql
   ```

   Ambas salidas deben indicar libpq >= 14 y soporte habilitado. La versión
   de PHP por sí sola no garantiza la versión de libpq. Si el ZIP elegido
   falla esta comprobación, detenerse y seleccionar un paquete oficial más
   reciente con ambas extensiones; nunca mezclar DLL de paquetes diferentes.

### Ejecutar desde PowerShell 5.1

Copiar el hostname externo SIN credenciales desde el panel del recurso
`clinica-vida-salud-db`. Ejecutar desde la raíz del proyecto:

```powershell
.\tools\continue-render-seed.ps1 `
  -Php 'D:\Herramientas\php83-render\php.exe' `
  -ExpectedHost 'dpg-REEMPLAZAR.REGION-postgres.render.com' `
  -RotatedCredentialConfirmed
```

Pegar la nueva URL únicamente cuando aparezca la solicitud oculta. No pasarla
como argumento ni pegarla en el chat. Usar el mismo usuario y equipo Windows
que creó `$HOME\clinica-vida-salud-render-demo-secrets.clixml`.
El archivo debe ser el Hashtable original con ADMIN, RECEPTION, DOCTOR y
PATIENT como SecureString. Si falta o no se puede descifrar, el proceso se detiene.

El procedimiento no invoca SQL de estructura ni psql. Primero comprueba
identidad, servidor 18, TLS, nombres exactos de las 21 tablas y vacío de todas.
Después ejecuta el seed transaccional y `verify-demo.php`. Las variables de
proceso se restauran a su estado anterior en `finally` (las nuevas se eliminan).
Los secretos solo se descifran temporalmente en memoria y no se escriben.
No ejecutar con transcripción/depuración que capture variables o entorno.

Resultado esperado: Administrador, Recepcionista, Medico y Paciente: OK.
La verificación usa el servicio real de autenticación, sin guardar sesiones ni
modificar las cuentas. Si el seed se confirma pero la verificación falla,
NO repetir el seed: recuperar las mismas contraseñas y ejecutar únicamente
la verificación; la protección de vacío bloqueará un segundo seed.

### Docker de Render y publicación pendiente

El PHP CLI de Windows solo administra el seed. La imagen `php:8.3-apache`
compila `pdo_pgsql` y `pgsql` contra `libpq-dev` de su distribución Linux;
no usa XAMPP ni sus DLL. El build ahora rechaza libpq sin soporte SNI.
El DocumentRoot sigue siendo únicamente `public/`.

Después de obtener las cuatro verificaciones OK:

1. Revisar los cambios actuales (incluidos los anteriores a esta continuación),
   comprobar `.dockerignore` y exclusión de `.env`/secretos. No se ha hecho
   commit ni push durante esta continuación.
2. Cuando se autorice publicar, hacer commit y push de los archivos de despliegue
   y aplicación revisados a GitHub. No incluir el CLIXML ni URLs privadas.
3. Crear Web Service Docker con el Dockerfile raíz, misma región que la base,
   sin Start Command alternativo. Usar la **Internal Database URL rotada**
   como secreto `DATABASE_URL` y `APP_ENV=production`, `DB_SCHEMA=public`.
4. Mantener TODOS los indicadores de escritura y `DB_CHECK_ENABLED` en `false`;
   pagos también deshabilitados. No configurar importación o seed al arrancar.
5. Revisar build y acceso HTTPS a `/login`; probar los cuatro roles y logout.
   No se declara servicio desplegado hasta observar estos resultados.

### Comprobaciones de esta continuación

- Ejecutado `composer test`: todas las suites configuradas pasaron, con
  simulaciones y sin PostgreSQL persistente.
- Ejecutado lint de 269 archivos PHP: correcto.
- Analizado el script con el parser de Windows PowerShell 5.1: correcto.
- Ejecutado preflight sin destino autorizado: bloqueo antes de conectar.
- Confirmado localmente: PHP 8.1.25 y libpq 11.4 en ambos controladores.
- No ejecutados: conexión Render, seed, autenticación de las cuatro cuentas
  remotas ni build Docker. Requieren PHP independiente y URL rotada.
- Sin modificación de `.env`, sin DDL/importaciones, sin commit/push.
- Eliminada directiva `Listen 80` duplicada del vhost; el puerto lo configura
  `ports.conf` mediante el entrypoint existente.

## Cierre operativo del servicio existente

No crear otro Web Service. Mantener Auto-Deploy según la configuración existente.
Tras el push autorizado usar Manual Deploy > Deploy latest commit, y verificar
el hash con GET /health: expone solo status y RENDER_GIT_COMMIT (si Render lo
proporciona), nunca consulta PostgreSQL ni devuelve configuración o secretos.
La variable está documentada en https://render.com/docs/environment-variables .
El build ejecuta check-platform-reqs y apache2ctl -t; Docker no está disponible
en este equipo, por lo que el build completo se verifica en Render.

La inicialización de las secciones anteriores es histórica: PostgreSQL ya está
inicializado y los cuatro logins funcionan. NO repetir SQL ni seed.
Conservar DB_CHECK_ENABLED=false y PAYMENT_WRITES_ENABLED=false. No se cambiaron
flags ni variables secretas de Render durante esta revisión.

SMTP pendiente de configuración real: MAIL_TRANSPORT=smtp y MAIL_HOST, MAIL_PORT,
MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM, MAIL_ENCRYPTION=tls/ssl. Para recuperación:
PASSWORD_RESET_TRANSPORT=smtp y APP_URL=https://clinica-vida-salud-vgve.onrender.com .
No se habilitan flags de correo sin probar el servidor. Sin SMTP se registra error,
no enviado. Recordatorios requieren ejecutar tools/send-reminders.php de forma
administrativa y programada con las flags autorizadas; no se programó un servicio
nuevo ni se enviaron correos reales durante las pruebas.
