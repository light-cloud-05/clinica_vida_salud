# Portada pública — revisión visual

Nueva bienvenida en `frontend/views/home.php` y estilos aislados en
`frontend/assets/css/home.css`, publicados en `public/assets/css/home.css`.
Se mantienen PHP nativo y Bootstrap 5; no se añade framework, fuente remota,
servicio de imágenes ni acceso nuevo a PostgreSQL en la portada.

- Azul médico, turquesa y blanco; tipografía del sistema, ilustración e iconos
  SVG locales, navegación con anclas, estructura responsive y foco visible.
- Reservar cita → `/mis-citas/reservar` (sin sesión redirige a `/login`).
- Registrarme como paciente → `/registro`; Iniciar sesión → `/login`.
- Secciones de especialidades, médicos, gestión de citas y pasos de reserva.
  No se publican nombres, cifras, especialidades o prestaciones inventadas.
- Recepción y caja: lunes a viernes 08:00–18:00, hora de Bolivia. Se distingue
  ese horario de los horarios de consulta de cada profesional.
- Identificación académica discreta al pie. Eliminadas las frases de primera
  entrega, preparación futura y entorno de desarrollo.

El registro y las reservas conservan sus requisitos y flags existentes;
la portada no habilita escrituras. Se conservan sesiones, CSP, CSRF y rutas.
`tools/dev-router.php` permite el nuevo CSS de forma explícita y
`tools/publish-assets.php` lo incluye al publicar recursos.

Comprobado en navegador local: portada de escritorio (1265 px), móvil de
390 px, sin desbordamiento horizontal observado; Reservar cita sin sesión
llega a login y Crear cuenta abre el formulario de registro. No se envió ningún
formulario. El navegador no aplicó un ancho efectivo de 320 px, así que no se
afirma haberlo probado a ese tamaño.

La vista previa estática de P6 en `var/visual-review/reportes.html` se generó
con lecturas reales locales y queda fuera de Git/DocumentRoot. El navegador
automatizado rechazó abrir el protocolo `file:`; no se eludió esa restricción.
Su revisión visual autenticada queda pendiente. No se añadió una ruta pública
que eluda los permisos para previsualizar reportes.

Sin cambios en `.env`, credenciales o bases. Sin commit, push o despliegue.

## Archivos de esta revisión

Portada y recursos:
- `frontend/views/home.php`
- `frontend/assets/css/home.css` y `public/assets/css/home.css`
- `frontend/assets/css/app.css` y `public/assets/css/app.css` (estilos P6 aislados)
- `tools/dev-router.php` y `tools/publish-assets.php` (publicación del nuevo CSS)

P6:
- `backend/app/Repositories/ReportRepository.php`
- `backend/app/Services/ReportExport.php`
- `frontend/views/reportes/index.php`
- `frontend/views/reportes/charts.php`

Validación y documentación:
- `tests/run.php`, `tests/reportes.php`, `tests/reportes-repository.php`
- `tools/verify-p6-readonly.php`
- `composer.json` (incluye la regresión P6 en la suite)
- `docs/reportes.md` y `docs/portada.md`

Los seis archivos previos de preparación de migración permanecen sin tocar.
Validación final: composer test y composer validate correctos; lint de 276
archivos PHP correcto; git diff --check correcto; ambas parejas CSS fuente/public
idénticas. Las pruebas de P6 suman 22 casos de servicio/exportación/render y 16
de cálculo con PDO simulado, además de las 400 comprobaciones de lectura local.
