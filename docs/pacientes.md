# Gestión de pacientes P2

Implementado el 6 de noviembre de 2026: listado real, búsqueda, paginación, detalle administrativo y formularios de registro/edición, sobre PHP nativo y PDO. El guardado real permanece bloqueado por defecto. No se ejecutaron INSERT, UPDATE, DELETE, migraciones ni seeds en la base real, ni siquiera dentro de transacciones reversibles.

## Abrir el módulo

Desde D:/Proyectos/clinica_vida_salud:

```powershell
composer assets
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

Abrir http://127.0.0.1:8080/login, entrar como Administrador o Recepcionista y elegir **Abrir pacientes** en su panel, o visitar http://127.0.0.1:8080/pacientes. En Apache/XAMPP conservar únicamente public como DocumentRoot y las reglas de reescritura existentes.

La pantalla recupera **30 pacientes**, repartidos en tres páginas de diez. Busca coincidencias parciales por nombres, apellidos o documento; mantiene el término al paginar y permite limpiar la búsqueda. %, _ y ! se buscan como caracteres literales. El listado muestra nombre, código, documento, estado y acciones; no muestra teléfono, correo, nacimiento, cuenta de acceso ni información clínica.

Rutas:

| Ruta | Métodos | Función |
|---|---|---|
| /pacientes | GET | Listado; parámetros q y pagina |
| /pacientes/detalle?id=… | GET | Detalle administrativo |
| /pacientes/nuevo | GET, POST | Formulario y registro |
| /pacientes/editar?id=… | GET, POST | Formulario y actualización |

Todas exigen sesión activa, rol Administrador o Recepcionista y permiso **gestionar_pacientes**, obtenido de roles/permisos/rol_permiso en PostgreSQL. Médico y Paciente reciben 403 aun cambiando la URL, incluyendo POST y aunque se les suministre un rol en el formulario. El paciente no tiene acceso a este directorio, ni siquiera a su propia ficha desde P2: el perfil personal pertenece a otra etapa. Sin sesión se redirige a login. No existe ruta ni método de eliminación física.

## Esquema y reglas comprobadas

Se extrajo y revisó el texto relevante de los dos DOCX en docs/requisitos: RF05, CU04 y P2. No se afirma revisión visual de diagramas o imágenes incrustadas. La BD se inspeccionó por SELECT antes de implementar las consultas del módulo: columnas, tipos, nulabilidad, claves, índices, triggers y permisos de pacientes y usuarios, además de la referencia de citas a pacientes.

Campos administrativos editables y existentes: codigo_paciente (30), documento_identidad (30), nombres (100), apellidos (100), fecha_nacimiento, sexo (30), telefono (30), correo_contacto (160), direccion, estado, contacto_emergencia_nombre, contacto_emergencia_parentesco y contacto_emergencia_telefono (30). Los números entre paréntesis son longitudes máximas reales de VARCHAR, medidas en caracteres en PHP. Código, documento, nombres, apellidos y estado son obligatorios. Los demás campos se convierten a NULL si están vacíos.

La BD permite únicamente activo/inactivo y exige unicidad exacta de código y documento. Se validan obligatorios, tipos escalares, UTF-8, ausencia de NUL, longitudes, formato de correo y fechas reales AAAA-MM-DD en PHP; HTML y JavaScript ayudan en el navegador. No se aplica una expresión arbitraria a nombres, documentos ni teléfonos. Los campos no editables enviados por manipulación del formulario se rechazan. Identificadores positivos dentro del rango de bigint/PHP de 64 bits se validan antes de consultar pacientes.

Las escrituras preparadas afectan únicamente columnas administrativas de pacientes. En edición se compara updated_at con la versión del formulario para evitar sobrescribir cambios concurrentes. Un conflicto devuelve 409. Duplicados detectados antes del guardado devuelven 422; una colisión concurrente de la restricción UNIQUE devuelve 409 sin exponer detalles SQL. El éxito usa redirección 303 y mensaje de sesión para evitar reenvío al actualizar la página.

La vinculación id_usuario es opcional y UNIQUE, y se conserva intacta en edición. El detalle consulta solo el nombre de la cuenta vinculada mediante LEFT JOIN, sin hashes ni correo de acceso. Los pacientes nuevos se crean sin cuenta vinculada (id_usuario NULL); no se crean, activan, modifican ni reasignan usuarios. La gestión explícita del vínculo con cuentas queda pendiente de definir en P7.

No se consultan ni editan tipo_sangre, alergias o antecedentes_relevantes; se mantienen los valores existentes al editar. Estos campos clínicos se reservan para su flujo autorizado posterior. Tampoco se consultan citas desde el detalle. Cambiar el estado del paciente, cuando se autorice, no cambia el estado de su usuario ni modifica sus citas.

Vacíos documentales respetados: no hay formato o secuencia acordada para codigo_paciente (se introduce explícitamente); no hay catálogo obligatorio de sexo, parentesco, documento o teléfono; no se impone una regla de edad mínima/máxima ni un límite de fecha de nacimiento distinto de fecha válida. No se generaron códigos ni catálogos nuevos. La vinculación/creación de cuentas no se ofrece como parte de este formulario.

## Escritura real pendiente de autorización

La política actual **no permite probar altas, ediciones ni desactivaciones persistentes en PostgreSQL**. Los formularios se pueden abrir y revisar; el botón de guardado permanece deshabilitado. Un POST válido enviado manualmente se rechaza con 423, antes de abrir una conexión de escritura. CSRF inválido devuelve 419 y campos inválidos 422. El navegador no puede activar el guardado mediante parámetros.

El indicador PATIENT_WRITES_ENABLED es false por defecto; solo se añadió su documentación a .env.example, sin modificar .env. Después de autorización explícita, puede habilitarse únicamente para un servidor temporal desde PowerShell, sin cambiar .env:

```powershell
# NO ejecutar antes de autorizar las escrituras y acordar los datos de prueba.
$env:PATIENT_WRITES_ENABLED = 'true'
php -S 127.0.0.1:8080 -t public tools/dev-router.php
# Tras detener con Ctrl+C:
Remove-Item Env:PATIENT_WRITES_ENABLED
```

El servidor debe iniciarse de nuevo para recibir el indicador. Este ejemplo corresponde al servidor integrado, no cambia la configuración global de Apache. Las pruebas de esta entrega forzaron explícitamente false en su propio proceso temporal.

Al habilitarlo, PacienteService valida permisos y datos, y PacienteRepository guarda en transacción. Connection comprueba que la BD sea clinica_vida_salud. La identidad real del usuario autenticado se entrega con set_config('app.usuario_id',…,true) para los triggers de auditoría existentes. PostgreSQL asignará id_paciente mediante su identidad y los triggers actualizarán updated_at y añadirán auditoría. No se deshabilitan triggers ni restricciones. Todo el bloque revierte en caso de error previo a commit. No se ejecutó este camino contra PostgreSQL durante las pruebas.

Requieren autorización posterior: crear un paciente real, actualizar o desactivar cualquier paciente existente, y verificar auditoría/persistencia de esas operaciones con datos acordados. También queda pendiente probar el SQL de escritura con PostgreSQL y sus triggers; los tests sobre PDO simulado no equivalen a esa validación. Preferir una BD de prueba autorizada y aislada para ensayos completos; no se creó ninguna.

## Pruebas y resultados reales

```powershell
composer test
composer test:patients-db
pwsh -NoProfile -File tests/http-pacientes.ps1
composer validate --strict
composer check-platform-reqs
Get-ChildItem backend,frontend,public,tests,tools -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

- 66 comprobaciones existentes de infraestructura, autenticación y sesiones pasaron.
- 63 comprobaciones de pacientes pasaron en memoria: obligatorios, longitudes Unicode, fechas/correo, datos malformados, campos no editables, permisos, alta/edición simuladas, conflictos, paginación, escape HTML y guardado deshabilitado.
- 12 comprobaciones del repositorio sobre PDO simulado pasaron: SQL parametrizado con lista cerrada, identidad de auditoría, comparación de versión, commit y rollback, preservación del vínculo de usuario/campos clínicos y manejo de colisiones. No se ejecutó SQL de escritura real.
- Integración PDO READ ONLY correcta: 30 pacientes distintos en tres páginas de diez; detalle, búsqueda por los tres campos, literales especiales, cero resultados y comprobación de duplicados con exclusión del propio registro. Permisos reales confirmados para los dos roles autorizados.
- 55 comprobaciones HTTP pasaron contra el código de producción y PostgreSQL real en lectura: paneles, listado paginado, detalle y formularios para ambos roles; 403 para Médico/Paciente en GET y POST; redirección anónima; ID inválido/inexistente; CSRF; errores PHP; ausencia de eliminación y bloqueo de POST válidos con 423. Un primer intento encontró un error de construcción de URLs en el script de pruebas; se corrigió y se repitió satisfactoriamente.
- La prueba HTTP creó sesiones locales temporales para las cuatro cuentas ya activas mediante un auxiliar CLI privado, sin pedir contraseñas ni alterar usuarios. Pasaron por el middleware de producción, que volvió a consultar estado y permisos. No se añadió ningún bypass de autenticación a las rutas de la aplicación. Las sesiones y el archivo privado de fixtures se eliminaron al terminar.
- Las huellas de pacientes, usuarios, médicos, horarios_atencion, citas, pagos, registros_auditoria y los valores de secuencias coincidieron antes/después de la prueba HTTP. No se imprimieron datos personales, hashes, contraseñas ni identificadores de sesión en los resultados.
- .env sigue ignorado y no versionado; no fue modificado. No se modificó configuración global ni se instalaron herramientas o paquetes.
- Regresión de autenticación real, solo lectura: las cuatro cuentas que el usuario ya había activado conservan sus roles; se rechazó una contraseña aleatoria y también una cuenta inactiva. Estado actual comprobado: 51 usuarios, 4 activos y 4 con hash; 30 pacientes, 18 médicos y 60 citas. No se imprimieron hashes y la huella de usuarios no cambió.

Pendiente: inspección visual en navegador de escritorio/móvil, prueba de los formularios con escrituras reales autorizadas, colisiones/concurrencia reales y verificación de los triggers con actor autenticado. El diseño incluye adaptación a pantallas pequeñas, tabla desplazable, etiquetas, errores accesibles y validación nativa, pero no se realizó inspección visual automatizada.

## Archivos de esta entrega

Creados:

- backend/app/Controllers/PacienteController.php.
- backend/app/Repositories/PatientStore.php y PacienteRepository.php.
- backend/app/Services/PacienteService.php, PatientValidator.php, PatientValidation.php y PatientConflict.php.
- backend/app/Middleware/PatientAccess.php.
- backend/app/Support/PatientFactory.php.
- frontend/views/pacientes/header.php, footer.php, index.php, show.php y form.php.
- tools/inspect-patient-schema.php.
- tests/pacientes.php, pacientes-repository.php, pacientes-database.php, http-pacientes.ps1 y support/patient-http-fixtures.php.
- docs/pacientes.md.

Modificados: AGENTS.md (alcance P2 autorizado), .env.example (indicador desactivado), README.md, composer.json (scripts), backend/app/Repositories/Connection.php, backend/app/Middleware/AuthorizationMiddleware.php, backend/routes/web.php, public/index.php, frontend/views/dashboard.php, frontend/assets/css/app.css y js/app.js, y sus copias en public/assets. Se actualizaron tests/auth-database.php y el texto de tests/http-auth.ps1 para admitir las cuentas que el usuario activó manualmente; docs/autenticacion.md aclara que sus cifras anteriores eran históricas. No se cambiaron hashes, usuarios ni .env.
