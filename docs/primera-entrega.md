# Evidencia de primera entrega

Fecha: 6 de noviembre de 2026. No se conectó a PostgreSQL ni se modificó su base de datos. No se creó .env con credenciales.

## Entorno comprobado

PHP CLI 8.1.25 en C:/xampp/php/php.exe; Composer 2.10.3; Git 2.55.0.windows.5; Apache 2.4.58. PDO cargado, PDO_PGSQL ausente aunque existe php_pdo_pgsql.dll. PHP usa C:/xampp/php/php.ini. Advertencia de carga dinámica ZIP detectada. PostgreSQL 18 es información proporcionada por el usuario, no verificada mediante conexión.

## Comandos ejecutados

- Get-Location, rg --files, Get-ChildItem y Get-Command para inventario.
- Lectura de los DOCX mediante System.IO.Compression.ZipFile y XML, sin modificar originales; extracción extensa truncada y búsqueda posterior de secciones relevantes.
- php -v, php -m, php --ini, composer --version, git --version y httpd.exe -v.
- php tools/publish-assets.php.
- composer install --ignore-platform-req=ext-pdo_pgsql --no-interaction --no-plugins --no-scripts: primer intento falló por DNS; reintento autorizado completó lock y autoload, sin paquetes externos. La omisión de plataforma fue solo para preparar la página y las pruebas; no habilita PostgreSQL.
- git init, git status --short y git check-ignore .env (ignorado). Sin commits ni remoto.
- composer validate --strict: pasó.
- php -l sobre los 15 archivos PHP: pasó.
- composer test: primer intento falló por autoload aún no generado; después de instalar, pasaron las 11 comprobaciones sin consultar la BD.
- composer check-platform-reqs: PHP/PDO correctos; falla ext-pdo_pgsql.
- Servidor temporal: php -d display_errors=0 -d display_startup_errors=0 -S 127.0.0.1:8080 -t public tools/dev-router.php; peticiones Invoke-WebRequest y cierre posterior del servidor.

## Resultado HTTP real

Inicio y CSS/JS: 200. Diagnóstico desactivado: 404. Intentos de acceder a /.env y /backend/config/database.php: 404. No se hizo inspección visual en navegador ni prueba del VirtualHost Apache. No se comprobó la descarga CDN desde el navegador.

## Pendiente

Habilitar manualmente PDO_PGSQL en el PHP de CLI/Apache, revisar advertencia ZIP, completar .env y ejecutar el diagnóstico con token. Aún no se verificaron conexión ni 21 tablas. Configurar VirtualHost manualmente y definir remoto GitHub. Después, verificar el esquema real y hashes antes de implementar autenticación.
