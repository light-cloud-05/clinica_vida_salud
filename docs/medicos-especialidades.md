# P3 Gestión de médicos y especialidades

Entrega del 6 de noviembre de 2026. Catálogo conectado a PostgreSQL, formularios y gestión de asociaciones implementados; guardado real bloqueado por defecto. No se ejecutaron INSERT, UPDATE o DELETE en PostgreSQL, ni siquiera dentro de pruebas que luego hicieran ROLLBACK. No se crearon tablas, bases, migraciones ni seeds. No se modificó .env, credenciales o configuración global.

## Revisión previa

Se extrajo y revisó texto relevante de ambos DOCX de docs/requisitos: RF06, RF07, CU05, CU06 y paquete P3. No se afirma revisión visual de los diagramas incrustados. Antes de escribir las consultas del módulo se inspeccionaron por SELECT columnas, nulabilidad, identidades, claves, índices, triggers y permisos reales de medicos, especialidades y medico_especialidad, además de sus referencias. No se ejecutó el SQL de creación que aparece en los documentos o en las definiciones devueltas por los catálogos.

Datos comprobados: **18 médicos, 9 especialidades y 18 asociaciones**. medicos tiene código y registro profesional únicos; id_usuario es opcional y único. especialidades exige precio_consulta NUMERIC(12,2) mayor que cero y nombre único según lower(btrim(nombre)). Ambas tablas permiten únicamente activo/inactivo. medico_especialidad tiene PK compuesta (id_medico,id_especialidad). citas referencia esa pareja mediante FK; no puede eliminarse una asociación mientras tenga referencias, aunque sean citas históricas.

Permisos inspeccionados: únicamente Administrador tiene gestionar_medicos. Los 24 nombres existentes no incluyen gestionar_especialidades. Esta implementación utiliza **Administrador + gestionar_medicos** para todo P3 (datos médicos administrativos, especialidades y asociaciones), de acuerdo con el actor administrativo de CU05/CU06. No se inventó un permiso nuevo ni se modificaron asignaciones. La autorización granular independiente de especialidades queda pendiente de definición si el proyecto la requiere después. Recepcionista, Médico y Paciente no tienen acceso a estas rutas de gestión. No hay edición del propio perfil médico desde P3.

## Abrir las pantallas

```powershell
composer assets
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

Entrar en http://127.0.0.1:8080/login como Administrador y usar la sección Médicos y especialidades de su panel. En Apache/XAMPP se conserva el VirtualHost existente con únicamente public como DocumentRoot.

| Ruta | Métodos | Pantalla |
|---|---|---|
| /medicos | GET | Listado de médicos; parámetros q, pagina, especialidad |
| /medicos/detalle?id=… | GET | Datos del profesional y especialidades |
| /medicos/nuevo | GET, POST | Registro de médico y sus asociaciones |
| /medicos/editar?id=… | GET, POST | Edición de médico y sus asociaciones |
| /especialidades | GET | Catálogo de especialidades, precios y número de médicos |
| /especialidades/nueva | GET, POST | Registro de especialidad |
| /especialidades/editar?id=… | GET, POST | Edición de especialidad |

El listado muestra páginas de 10 y 8 médicos. Busca parcialmente por nombres, apellidos, código o registro profesional, y filtra por una especialidad existente sin duplicar médicos. Conserva búsqueda/filtro al paginar. Hay mensajes cuando no se encuentran resultados. El catálogo muestra las nueve especialidades, descripción opcional, estado, precio en BOB y enlaces al listado filtrado.

Se reutilizan los componentes visuales de pacientes, ahora en layouts compartidos; las barras de navegación muestran solo enlaces permitidos. Las tablas admiten desplazamiento horizontal y los formularios/cuadrículas se adaptan a pantallas pequeñas. El listado médico no expone contacto, usuario vinculado ni otros datos innecesarios. El detalle autorizado muestra contacto profesional y nombre de cuenta vinculada; nunca hashes ni correo de acceso. No se muestran notas clínicas, recetas, datos de pacientes ni agendas.

## Formularios y reglas

Campos de médicos: codigo_medico (30), registro_profesional (40), nombres/apellidos (100), telefono (30), correo_contacto (160), estado y selección de especialidades existentes. Código, registro, nombres, apellidos y estado son obligatorios. Contacto es opcional. La vinculación con usuarios se conserva intacta y no se ofrece para modificación; nuevos médicos quedan con id_usuario NULL. No se crean ni activan usuarios.

Campos de especialidades: nombre (100), descripcion opcional, precio_consulta obligatorio y estado. Se respetan la unicidad del nombre normalizado por PostgreSQL, el importe positivo y la precisión NUMERIC(12,2). La validación decimal trabaja con cadenas, sin float: máximo 9999999999.99 y hasta dos decimales; no se aceptan notación exponencial o importes negativos/cero. Los valores con menos de dos decimales se completan de forma exacta. Cambiar una tarifa no actualiza pagos ni citas existentes.

PHP valida campos obligatorios, longitudes Unicode reales, UTF-8, ausencia de NUL, correo, estado, tipos escalares, identificadores positivos, selección no duplicada y existencia de especialidades. Campos como id_usuario, id_medico, rol o columnas no editables enviados en el formulario se rechazan. El navegador ayuda con required, maxlength, email, min/max/step y validación JavaScript compartida; no decide permisos o reglas definitivas.

Vacíos documentales conservados: no hay formato de autogeneración de código o registro, por lo que se introducen explícitamente. No se impone mínimo de una especialidad, porque el esquema permite cero; tampoco se rechazan asociaciones por estado inactivo, ya que no hay restricción que lo establezca. Las especialidades inactivas se identifican visualmente. Estos puntos deben definirse antes de incorporar reglas adicionales. No se implementó disponibilidad, agenda, bloqueo ni reserva.

## Guardado preparado y bloqueado

MEDICAL_WRITES_ENABLED es false por defecto, está documentado solo en .env.example y no activa PATIENT_WRITES_ENABLED. Mientras esté deshabilitado, el botón Guardar aparece deshabilitado y un POST válido manual recibe 423 antes de abrir la conexión de escritura. CSRF incorrecto devuelve 419, validación incorrecta 422, acceso no autorizado 403 y un identificador válido inexistente 404.

Después de autorización explícita y de acordar datos de prueba, puede habilitarse un servidor temporal sin modificar .env:

```powershell
# No ejecutar antes de autorizar las pruebas de escritura real.
$env:MEDICAL_WRITES_ENABLED = 'true'
php -S 127.0.0.1:8080 -t public tools/dev-router.php
# Después de detenerlo con Ctrl+C:
Remove-Item Env:MEDICAL_WRITES_ENABLED
```

El proceso debe reiniciarse para recibir ese indicador. El ejemplo no cambia la configuración de Apache y no habilita pacientes. Esta entrega no ejecutó estos comandos de habilitación.

Cuando se habilite, la lógica implementada:

- Verifica permisos en ruta, controlador y servicio, valida de nuevo y conecta exclusivamente a clinica_vida_salud.
- Utiliza consultas preparadas nativas PDO y una lista cerrada de tablas/columnas. No hay operaciones de eliminación física de médicos o especialidades.
- Guarda el registro y sus asociaciones en una sola transacción. Configura app.usuario_id con la identidad autenticada para los triggers existentes de auditoría.
- Bloquea el registro padre y compara updated_at para detectar edición concurrente; compara también una huella de los IDs asociados, ya que la tabla intermedia no tiene updated_at. Los conflictos devuelven 409.
- Bloquea/verifica las especialidades seleccionadas. Calcula adiciones y retiradas por diferencia, conserva las parejas sin cambios y no borra/recrea todas las asociaciones.
- Impide retirar una asociación referenciada por citas: se señala en el formulario, se valida en servicio y se vuelve a comprobar en la transacción. La FK real sigue siendo la última garantía ante concurrencia; un error de FK revierte todo. No se modifica ninguna cita para permitir retirar la asociación.
- Añade únicamente parejas nuevas y elimina únicamente parejas desmarcadas sin referencias. Esta eliminación afecta solo a la relación, no al médico ni a la especialidad. Los triggers actuales auditan la actualización del médico y actualizan su fecha; no se inventó un trigger de auditoría para la tabla intermedia, que no lo tiene.
- Usa redirección 303 y mensaje de éxito tras commit. Revierte antes de informar un conflicto/error; no expone SQL, datos personales ni detalles de la conexión en los errores.

## Pruebas realmente ejecutadas

```powershell
composer test
php tests/medicos-database.php
pwsh -NoProfile -File tests/http-medicos.ps1
pwsh -NoProfile -File tests/http-pacientes.ps1
composer validate --strict
composer check-platform-reqs
Get-ChildItem backend,frontend,public,tests,tools -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

Resultados:

- **256 comprobaciones automatizadas** pasaron: 141 existentes, 88 de P3 con datos en memoria y 27 del repositorio P3 sobre PDO simulado. Se verificaron validaciones, decimales exactos, permisos, bloqueo de escritura, versiones, asociaciones, listas cerradas, escape HTML, commit/rollback y manejo de FK/duplicados. Las simulaciones no ejecutan SQL sobre PostgreSQL.
- Integración real exclusivamente READ ONLY: 18 médicos distintos en páginas de 10/8, 9 especialidades y sus nueve filtros; detalle, búsqueda por los cuatro campos, caracteres literales %, _ y !, ausencia de resultados, duplicados de códigos/registros y nombre normalizado de especialidad. Permiso confirmado solo para Administrador; 18 asociaciones sin cambios.
- **72 comprobaciones HTTP P3** pasaron contra rutas de producción con PostgreSQL en lectura. Incluyen catálogo y paginación reales, filtro, formularios, detalle, navegación, rechazo 403 de los otros tres roles, CSRF, errores de campos/precio/ID, inexistentes y bloqueo de los cuatro POST válidos con 423. Una primera ejecución del test detectó que PowerShell no codificaba correctamente la lista de especialidades; se corrigió el script de prueba y la repetición pasó.
- **55 comprobaciones HTTP de pacientes** pasaron como regresión por el uso de layouts y navegación compartidos. El listado conserva sus 30 pacientes y sus restricciones.
- Las pruebas HTTP usaron sesiones locales temporales para las cuatro cuentas ya activadas por el usuario, sin pedir contraseñas, cambiar usuarios ni añadir rutas de bypass al servidor de producción. El servidor temporal forzó ambos indicadores de escritura a false. Los fixtures y sesiones de prueba se eliminaron al terminar; no se mostraron identificadores de sesión en los resultados.
- Las huellas anteriores/posteriores de pacientes, usuarios, médicos, especialidades, medico_especialidad, horarios_atencion, bloqueos_horario, citas, pagos, registros_auditoria y valores de secuencias coincidieron. No hubo modificaciones reales.
- Lint de **74 archivos PHP**, validación estricta de Composer y requisitos de plataforma correctos. No se instalaron dependencias adicionales. .env permanece ignorado y no versionado; no se modificó.

Pendiente de autorización: probar INSERT/UPDATE reales de médicos y especialidades, altas/retiradas reales de asociaciones, desactivaciones, efecto de triggers de auditoría y concurrencia real de estas operaciones. Aunque las transacciones y fallos se simularon, esto no certifica el SQL de escritura contra PostgreSQL ni todos sus efectos. No se consumieron identidades en pruebas reversibles. También queda pendiente inspección visual manual de escritorio/móvil y comprobación del VirtualHost Apache en navegador. No se avanzó a horarios o citas.

## Archivos de esta etapa

Creados:

- backend/app/Controllers/CatalogoMedicoController.php.
- backend/app/Repositories/MedicalStore.php y MedicoRepository.php.
- backend/app/Services/MedicoService.php, MedicalValidator.php, MedicalValidation.php y MedicalConflict.php.
- backend/app/Middleware/MedicalAccess.php.
- backend/app/Support/MedicalFactory.php y Input.php.
- frontend/views/layouts/management-header.php y management-footer.php.
- frontend/views/medicos/index.php, specialties.php, show.php y form.php.
- tools/inspect-medical-schema.php.
- tests/medicos.php, medicos-repository.php, medicos-database.php y http-medicos.ps1.
- docs/medicos-especialidades.md.

Modificados: AGENTS.md (alcance P3), .env.example (indicador desactivado), README.md, composer.json (scripts), backend/routes/web.php, backend/app/Repositories/Connection.php, backend/app/Services/PatientValidator.php (delegación del mismo validador de ID a Input compartido), frontend/views/dashboard.php, frontend/views/pacientes/header.php y footer.php (layouts compartidos), frontend/assets/css/app.css y js/app.js y sus copias public/assets. Se amplió tests/support/patient-http-fixtures.php para incluir en las huellas especialidades, asociaciones y bloqueos, y proporcionar IDs de prueba de P3. No se modificaron .env, permisos, roles, usuarios ni registros de las tablas.
