# P5-A Consulta y disponibilidad de citas médicas

Entrega del 6 de noviembre de 2026. Implementado exclusivamente el módulo de consulta, detalle, agenda diaria y selección informativa de franjas. Se reutilizan la autenticación, las sesiones, los middleware, PDO y el motor de disponibilidad de P4. No existen operaciones de creación, reprogramación, cancelación, confirmación, cobro o retención de turnos en P5-A.

## Abrir el módulo

Desde D:/Proyectos/clinica_vida_salud, si no se utiliza el VirtualHost existente de Apache/XAMPP:

```powershell
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

Abrir http://127.0.0.1:8080/login y entrar con una cuenta ya habilitada. Cada panel autorizado tiene un enlace a sus citas y a disponibilidad. Se conserva public como única raíz web. No se cambió .env, configuración global ni credenciales; no se instalaron paquetes.

| Pantalla | Personal autorizado | Paciente |
|---|---|---|
| Listado e historial | /citas | /mis-citas |
| Detalle | /citas/detalle?id=… | /mis-citas/detalle?id=… |
| Agenda diaria | /citas/agenda?medico=…&fecha=AAAA-MM-DD | Sus citas por fecha desde /mis-citas |
| Disponibilidad | /citas/disponibilidad | /mis-citas/disponibilidad |

Todas son rutas **GET**. POST/PUT/DELETE reciben 405; no hay endpoints de nuevas citas, cancelación o reprogramación. El POST de cierre de sesión sigue siendo el componente existente, protegido con CSRF. Los formularios de consulta de P5-A no modifican datos y no necesitan CSRF para una operación de escritura inexistente.

## Funciones implementadas

El listado recupera 60 citas para Administración/Recepción, con diez por página y seis páginas. Permite búsqueda parcial por código de cita, nombres/apellidos autorizados o especialidad, y filtros fecha, medico, especialidad, paciente y estado. Los parámetros de URL son q, fecha, medico, especialidad, paciente, estado y pagina. Los caracteres %, _ y ! se buscan literalmente; no alteran el SQL. Los filtros se mantienen al paginar y existe un mensaje cuando no hay resultados.

La agenda diaria usa fecha y médico, con filtros adicionales y paginación. Si no se especifican, usa la fecha local actual y el primer médico permitido. El detalle muestra código, fecha, intervalo, nombre del paciente autorizado, médico, especialidad y estado. No consulta documentos, contactos, motivos de consulta/cancelación, antecedentes ni datos de pago. Estas pantallas no muestran acciones para alterar la cita.

La disponibilidad permite seleccionar médico, una especialidad asociada, fecha y opcionalmente hora. El único botón es Consultar disponibilidad. Las horas no disponibles se muestran deshabilitadas; seleccionar una libre vuelve a consultar y confirma únicamente la franja consultada. No crea, bloquea o garantiza una reserva.

El diseño reutiliza Bootstrap 5, los layouts compartidos, tablas adaptables y tarjetas de la clínica. Los nombres y parámetros se escapan al renderizar. No se añadieron frameworks ni servicios externos.

## Documentación y esquema comprobados antes de implementar

Se leyeron docs/horarios.md y las secciones textuales relevantes de ambos DOCX de docs/requisitos: P5, RF10/RF11/RF15/RF16, CU12/CU13 y reglas de disponibilidad. No se afirma revisión visual de los diagramas. Las extracciones extensas tuvieron salida truncada; se revisaron las secciones necesarias y se completó por separado la inspección de restricciones reales.

La inspección de solo lectura cubrió citas, pacientes, medicos, especialidades, medico_especialidad, horarios_atencion, bloqueos_horario, pagos, reservas_provisionales y transacciones_pago: columnas, relaciones y, para las tablas de citas/pagos/reservas, restricciones, índices y triggers. Se leyeron las definiciones de validar_cita, control de reprogramación, historial y auditoría; no se ejecutaron sus operaciones de escritura. P4 ya documenta la función de expiración de reservas; tampoco se invoca en esta etapa.

Campos de citas utilizados: id_cita, codigo_cita, id_paciente, id_medico, id_especialidad, fecha, hora_inicio, hora_fin y estado. La relación médico-especialidad está protegida por una FK compuesta. Paciente y médico se vinculan a la sesión a través de sus columnas id_usuario existentes, no por nombres ni parámetros del navegador.

Estados admitidos por el CHECK real: programada, confirmada, atendida, cancelada e inasistencia. Las 60 filas actuales están en **programada**. No se creó una tabla de estados ni se interpretaron estados de pago como estados de cita. Los pagos y reservas provisionales actuales tienen cero registros. Se inspeccionaron sus estructuras, pero P5-A no necesita unirlos para listar citas: así evita duplicar citas o exponer datos de pagos.

## Roles, permisos y privacidad

| Rol | Permiso real utilizado | Ámbito de citas |
|---|---|---|
| Administrador | consultar_historial_citas; consultar_agenda para agenda/disponibilidad | Todas las autorizadas; 60 actuales |
| Recepcionista | consultar_historial_citas; consultar_agenda | Todas las autorizadas; 60 actuales |
| Medico | consultar_historial_citas; consultar_agenda | Solo citas cuyo médico tenga id_usuario igual al usuario autenticado |
| Paciente | gestionar_citas_propias | Solo citas cuyo paciente tenga id_usuario igual al usuario autenticado |

No existe una nueva asignación o permiso creado para P5-A. gestionar_citas_propias se utiliza para la consulta del titular existente, sin habilitar sus futuras operaciones de escritura.

El router verifica sesión/rol/permiso. El servicio y el repositorio vuelven a validar el acceso, y count/listing/find incluyen siempre la condición SQL del ámbito del actor. Un parámetro id_usuario, rol o paciente ajeno no puede reemplazar la identidad autenticada. Un detalle ajeno devuelve 404; un filtro fuera del ámbito permitido devuelve 403. El Médico no puede consultar citas de otros médicos aunque compartan paciente.

Los selectores respetan el ámbito: Médico solo ve su médico y pacientes vinculados a sus atenciones; Paciente solo obtiene su propio registro y médicos de sus citas para los filtros del listado. Una cuenta sin vínculo obtiene cero citas. Las especialidades son datos del catálogo, sin información clínica personal.

La disponibilidad del paciente usa el directorio público administrativo de médicos/especialidades, pero **requiere sesión** en esta entrega; no se añadió un endpoint anónimo. No identifica a los pacientes que ocupan los intervalos ni expone motivos de bloqueo. La consulta de disponibilidad del Médico mantiene su ámbito propio. Los datos internos necesarios para calcular ocupación se proyectan a intervalos/estados, sin retornar filas de pacientes o citas ajenas al navegador.

Pruebas reales con las cuentas existentes: profesional01 obtuvo **4 citas** y paciente01 obtuvo **2**. Sus detalles ajenos fueron rechazados por SQL. Administración y Recepción recuperaron las 60; no se activó ni modificó ninguna cuenta.

## Disponibilidad y ventana de reserva

CitaAvailability delega la generación de intervalos en DisponibilidadService de P4. HorarioRepository proporciona horarios, bloqueos e intervalos ocupados con la misma conexión de lectura. No se duplicó el cálculo de solapamiento o anclaje.

- Zona America/La_Paz en PHP y PDO.
- La duración sale de duracion_cita_minutos de cada horario; su valor predeterminado real es 30.
- Solo se consideran horarios activos del médico, completos y alineados con su hora inicial. No se impone la jornada de recepción 08:00–18:00 a los profesionales.
- No se ofrecen consultas pasadas o ya iniciadas. Un médico inactivo no produce horas seleccionables.
- Un bloqueo, aunque ocupe solo una parte, invalida la franja que solape. Los intervalos adyacentes se permiten según [inicio, fin).
- La exclusión real de PostgreSQL incluye toda cita con estado **distinto de cancelada**. Programada, confirmada, atendida e inasistencia ocupan su intervalo. Solo cancelada lo libera si no existe otro impedimento. No se liberan turnos por inferencias sobre pagos o reservas vencidas.
- Fechas anteriores, fines de semana y fechas fuera de los diez días hábiles producen mensajes claros y ninguna franja seleccionable. También se informa cuando falta atención configurada o no quedan franjas libres.
- No se usa calendario de feriados: no está contemplado por el esquema inspeccionado ni por la regla SQL existente.

Matiz confirmado en la implementación de validar_cita: el límite de diez días cuenta hoy cuando es lunes a viernes, incluso si ya terminaron todas sus franjas. P5-A solo ofrece horas futuras; si no queda ninguna hoy, no ofrece un turno hoy y **no amplía** el límite a un undécimo día, que PostgreSQL rechazaría. Se conservó la coherencia con la función y con P4. Cualquier cambio del criterio de cómputo necesitaría aclaración y autorización posterior; no se cambió el trigger.

El estado inactivo de una especialidad no tiene una prohibición adicional en validar_cita; no se inventó una regla nueva. La asociación debe existir y el médico debe ser activo para que existan franjas libres. Este punto puede precisarse antes de P5-B.

## Preparación para concurrencia, sin implementar reservas

La BD ya dispone de EXCLUDE USING gist sobre id_medico y tsrange(fecha+hora_inicio,fecha+hora_fin,'[)'), con predicado estado <> cancelada. Esa garantía protege al médico incluso si se seleccionan especialidades distintas. El índice y la restricción se verificaron por lectura y se conservaron intactos.

La selección de hora se revalida contra las franjas calculadas; no basta con recibir una hora desde el navegador. La simulación demuestra que dos lectores pueden observar el mismo hueco y que, tras añadir una ocupación solo en memoria, una segunda selección deja de ser válida. No se simuló esto insertando datos reales ni consumiendo secuencias.

P5-A no retiene turnos y una página puede quedar desactualizada inmediatamente después de consultarse. En P5-B será necesario revalidar y crear la cita en una transacción, dejando que PostgreSQL decida el conflicto concurrente y tratando el SQLSTATE 23P01. Esa transacción, los bloqueos necesarios y la prueba de carreras reales están pendientes: no se incorporó un INSERT, un endpoint de reserva o una garantía ficticia en esta entrega.

## Pruebas ejecutadas y resultados

```powershell
composer test
php tests/citas-database.php
pwsh -NoProfile -File tests/http-citas.ps1
php tools/verify-p4-integrity.php baseline p5a
pwsh -NoProfile -File tests/close-p5a.ps1
composer validate --strict
composer check-platform-reqs
Get-ChildItem backend,frontend,public,tests,tools -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
git check-ignore .env
git ls-files -- .env
```

La línea base se creó antes de las pruebas de P5-A, sin sobrescribir la de P4. El cierre conserva registros locales en var/p5a-checks y huellas privadas en var/p5a-integrity.json, todos ignorados por Git. El verificador no sobrescribe una línea base existente; su etiqueta opcional permite repetir otras etapas con una línea base independiente, manteniendo el comportamiento anterior de P4.

| Comprobación | Resultado real |
|---|---|
| Composer test | 425 comprobaciones: 360 anteriores y 65 nuevas de P5-A |
| HTTP P5-A | 144 comprobaciones pasaron |
| Regresión HTTP autenticación | 20 pasaron |
| Regresión HTTP P2 | 55 pasaron |
| Regresión HTTP P3 | 72 pasaron |
| Regresión HTTP P4 | 90 pasaron |
| Total HTTP del cierre | 381 comprobaciones pasaron |
| Integración de lectura | Cinco suites: citas, horarios, autenticación, pacientes y médicos |
| Citas leídas | 60 distintas, seis páginas de diez |
| Ámbito médico/paciente de prueba | 4 y 2 citas respectivamente |
| Estado actual | 60 programadas; 0 pagos y 0 reservas provisionales |
| Disponibilidad de P4 | 180 médico-días nuevamente contrastados entre PHP y SQL |
| Sintaxis | 113 archivos PHP sin errores |
| Composer/plataforma | Configuración válida; requisitos PHP/PDO/PDO_PGSQL/mbstring cumplidos |
| Integridad global | 21 tablas, auditoría, 19 secuencias y .env sin diferencias |

Se probaron todos los filtros, búsqueda parametrizada, paginación sin duplicados, detalle propio/ajeno, manipulación de identidad en URL, agenda, disponibilidad, selección no vinculante, hora no disponible, ventana, estados de ocupación y rechazo de métodos de escritura. Los estados distintos de programada y los bloqueos se probaron con datos simulados: no existen ejemplos reales de esos casos en el conjunto actual. Las comparaciones SQL de P4 contrastan la disponibilidad con los datos reales existentes.

Las pruebas HTTP autenticadas utilizaron sesiones privadas temporales para las cuentas que el usuario ya había habilitado. Pasaron por los middleware de producción; no se creó un bypass web ni se pidieron contraseñas. Los fixtures y sesiones correspondientes se eliminaron al terminar. Los registros de prueba no contienen credenciales, hashes de contraseña ni cuerpos de formularios.

La comparación final cubrió las 21 tablas de public, incluida auditoría, y last_value/is_called de las 19 secuencias, sin llamar nextval o setval. También verificó .env por huella sin imprimir su contenido. Se mantuvieron 60 citas, 90 horarios, 0 bloqueos y 30 pacientes. No hubo modificaciones ni siquiera dentro de transacciones DML reversibles: las transacciones reales de los tests solo leyeron y finalizaron con ROLLBACK.

## Limitaciones pendientes

La inspección visual manual de escritorio/móvil y del VirtualHost Apache permanece pendiente; los HTTP comprueban contenido y seguridad, no certifican apariencia visual. Bootstrap sigue usando la CDN existente.

El catálogo actual no permite probar persistencia de bloqueos, estados mixtos, reservas o pagos. Tampoco se probaron carreras de INSERT concurrentes contra PostgreSQL, porque la escritura está fuera del alcance. Seleccionar una hora no garantiza su disponibilidad al reservar posteriormente. No se exponen motivos clínicos ni información de pagos en el detalle; cualquier ampliación necesitará definir permisos específicos y minimización.

El formulario admite seleccionar médico y especialidad del catálogo; PHP rechaza una pareja no asociada con un mensaje 400. No se creó una relación nueva para resolverla. No hay endpoint anónimo de disponibilidad, exportación, historial de cambios detallado, recordatorios, confirmación ni acciones de cobro.

P5-B (creación), P5-C (reprogramación/cancelación) y P9 (pagos) siguen pendientes de autorización y desarrollo. Ninguna bandera de escritura anterior se habilitó. No existe una bandera que permita convertir las consultas de P5-A en escrituras: CitaRepository/CitaService no tienen método save y su fábrica solo utiliza Connection::readOnly.

## Archivos de esta entrega

Creados:

- backend/app/Controllers/CitaController.php.
- backend/app/Middleware/CitaAccess.php.
- backend/app/Repositories/CitaStore.php y CitaRepository.php.
- backend/app/Services/CitaFilters.php, CitaAvailability.php y CitaService.php.
- backend/app/Support/CitaFactory.php.
- frontend/views/citas/navigation.php, filters.php, index.php, show.php y availability.php.
- tools/inspect-appointment-schema.php.
- tests/citas.php, citas-database.php, http-citas.ps1 y close-p5a.ps1.
- docs/citas.md.

Modificados: AGENTS.md (alcance P5-A autorizado), backend/routes/web.php, frontend/views/layouts/management-header.php, frontend/views/dashboard.php, frontend/assets/css/app.css y public/assets/css/app.css, composer.json (scripts de prueba), tests/support/patient-http-fixtures.php (metadatos privados de citas para las pruebas), tools/verify-p4-integrity.php (etiqueta de línea base opcional), README.md y nota de continuidad de docs/horarios.md.

No se alteraron .env, credenciales, permisos, datos, auditoría, secuencias, restricciones, funciones o triggers. El motor de disponibilidad y las operaciones existentes de P2/P3/P4 se conservaron.
