# P4 Gestión de horarios médicos

Continuidad: P5-A reutiliza DisponibilidadService sin alterar la agenda administrativa de P4. Su consulta de franjas aplica además el límite real de diez días hábiles y ofrece una vista sin datos de pacientes ajenos. Ver [citas](citas.md). Las cifras y pruebas de esta entrega P4 se conservan como evidencia histórica.

Entrega y cierre del 6 de noviembre de 2026. Se continuó el trabajo existente tras la interrupción; no se reinició ni se eliminó el módulo. Incluye horarios habituales, agenda semanal, bloqueos y cálculo informativo de disponibilidad. **Las escrituras reales siguen bloqueadas**. No se implementó P5 ni se reservaron, cancelaron o reprogramaron citas.

## Cómo abrir y probar

Con Apache/XAMPP, mantener el VirtualHost configurado con **public/** como único DocumentRoot. Para una prueba temporal desde la raíz del proyecto:

```powershell
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

Abrir [inicio de sesión](http://127.0.0.1:8080/login), entrar con una cuenta ya habilitada y utilizar Horarios y agenda en su panel. No se cambiaron contraseñas, estados de usuario ni .env. Detener el servidor temporal con Ctrl+C.

| Pantalla | Ruta | Parámetros GET |
|---|---|---|
| Horarios habituales | /horarios | medico, especialidad, dia |
| Detalle de un médico | /horarios/detalle | medico obligatorio; especialidad y dia opcionales |
| Agenda semanal | /horarios/agenda | medico, especialidad, dia, semana=AAAA-MM-DD |
| Bloqueos | /bloqueos | medico, especialidad, dia |
| Crear horario | /horarios/nuevo | Ninguno |
| Editar/desactivar horario | /horarios/editar | id del horario |
| Crear bloqueo | /bloqueos/nuevo | Ninguno |
| Editar bloqueo | /bloqueos/editar | id del bloqueo |

Las cuatro primeras rutas admiten GET. Los formularios admiten GET y POST; no hay rutas DELETE. Una fecha de referencia se convierte en su semana de lunes a domingo. Los horarios configurables son únicamente de lunes a viernes, conforme al CHECK real de PostgreSQL; sábado y domingo se muestran sin atención habitual salvo excepciones/eventos existentes. La agenda permite consultar otras semanas y no usa la ventana de reservas como restricción administrativa.

Prueba manual sin escritura: revisar listado, aplicar los tres filtros, abrir detalle, cambiar la fecha de agenda y revisar los formularios. El botón de guardar permanece deshabilitado. Un POST válido enviado manualmente devuelve 423; un token CSRF inválido devuelve 419 y datos inválidos 422. No habilitar indicadores para esta revisión.

## Permisos reales verificados

| Rol | consultar_agenda | gestionar_horarios | gestionar_bloqueos | Alcance actual |
|---|---|---|---|---|
| Administrador | Sí | Sí | Sí | Consulta todos; formularios de horarios y bloqueos |
| Recepcionista | Sí | No | No | Consulta todos; sin edición |
| Medico | Sí | No | No | Solo su médico vinculado por medicos.id_usuario |
| Paciente | No | No | No | Sin acceso a P4 |

Los permisos se consultan desde roles, permisos y rol_permiso con la autenticación existente. Se verifican en rutas, controlador y servicio. No se cambiaron asignaciones. Si un médico recibiera gestionar_bloqueos mediante una operación autorizada futura, la implementación conservaría el ámbito de su propio registro; no se le asignó ese permiso durante esta entrega.

El médico no recibe el directorio de otros médicos en el selector. Cambiar medico en la URL a un registro ajeno devuelve 403 antes de consultar sus horarios. La consulta por ID de horario/bloqueo también queda limitada por los médicos autorizados. Un médico sin vínculo no obtiene registros. En las pruebas, profesional01 recuperó únicamente sus cinco horarios. Recepción y Administración recuperaron los 90.

La agenda consulta solo médico, fecha, horas y estado de las citas: no consulta ni presenta pacientes, motivos de consulta, diagnósticos, pagos o datos clínicos. El motivo de un bloqueo se muestra únicamente a quien puede gestionarlo. Las salidas están escapadas y la caché de páginas autenticadas sigue desactivada.

## Esquema y documentación revisados

Durante la implementación se revisaron las secciones textuales pertinentes de los dos DOCX de docs/requisitos: RF08, RF09, CU07, CU17, P4 y reglas de disponibilidad. No se afirma revisión visual de diagramas/imágenes incrustadas. Las consultas se basan en columnas, claves, restricciones, índices, triggers y relaciones inspeccionados mediante SELECT, no en nombres supuestos.

**horarios_atencion**: id_horario (identidad), id_medico (FK), dia_semana (1 a 5), hora_inicio, hora_fin, duracion_cita_minutos (entero positivo, predeterminado 30), estado (activo/inactivo), created_at y updated_at. La hora final debe ser posterior a la inicial. Existen triggers de updated_at y auditoría.

**bloqueos_horario**: id_bloqueo (identidad), id_medico (FK), fecha, hora_inicio, hora_fin y motivo opcional. La hora final debe ser posterior. No hay estado, updated_at ni trigger de auditoría en esta tabla; no se inventaron. Por ello se ofrece crear/editar, pero no desactivar bloqueos ni eliminarlos físicamente. La edición usa una huella del contenido para detectar cambios, en lugar de una columna de versión inexistente.

**citas**: se respetan los intervalos [inicio, fin) y la exclusión real que ocupa todas las citas cuyo estado no sea cancelada. El trigger validar_cita exige médico activo, fecha futura, lunes a viernes, duración y ancla del horario, ausencia de bloqueo y una ventana de diez días hábiles. Se inspeccionó su definición; no se invocó como operación de escritura. Tampoco se ejecutaron procedimientos de cancelación o la función de expiración de reservas.

## Funciones y reglas implementadas

- Listado agrupado por médico, relación real y filtros por médico, especialidad y día. Los conteos reflejan los filtros y el ámbito del usuario.
- Detalle de horas de atención y bloqueos de cada médico; enlaces de edición/desactivación solo para usuarios autorizados.
- Formularios de alta y edición para horarios y bloqueos; desactivación de horario mediante su estado real. Duración inicial 30, configurable como entero positivo según el esquema.
- Validación PHP de fechas, horas (incluidos segundos/microsegundos y el límite 24:00 de TIME), duración, estado, identificadores y campos permitidos. HTML/JavaScript asisten con obligatorios, rangos y formato. No se aplica automáticamente la jornada de caja 08:00–18:00 a los médicos.
- Rechazo de horarios **activos** superpuestos para el mismo médico y día; los extremos adyacentes no se solapan. Los horarios inactivos no generan disponibilidad. Se rechazan bloqueos entre sí si coinciden médico, fecha e intervalo.
- Un bloqueo sobre un horario habitual es una excepción esperada, no un error: se resta de sus franjas disponibles. Un bloqueo fuera de atención no añade horas de atención.
- Antes de editar/desactivar/trasladar un horario se comprueba que ninguna cita no cancelada que tenía cobertura pierda la cobertura, duración o alineación permitida. Esto también protege citas históricas; no se cambian sus filas para permitir la operación.
- Un bloqueo nuevo o editado que afectaría citas se rechaza e informa del número y horas afectadas sin identificar pacientes. RF09 prevé resolver esas citas por su flujo autorizado; como P5 aún no está implementado, P4 no confirma bloqueos incompatibles ni cancela automáticamente citas.
- La agenda muestra horario habitual, bloqueos, intervalos ocupados y franjas completas. Cada franja se calcula desde la hora inicial del horario con su duración configurada. El sobrante que no completa una consulta no se presenta como franja disponible.
- Se diferencia disponible, bloqueada, ocupada, ocupada y bloqueada, pasada, médico inactivo y conflicto de horarios heredados. Un horario heredado con solapamientos no se ofrece como disponible. Las franjas que ya empezaron no se ofrecen como libres.
- Las horas con segundos o microsegundos significativos se presentan completas; HH:MM solo se usa cuando no pierde precisión.

La referencia de diez días hábiles se calcula de lunes a viernes, incluyendo el día actual si es hábil, igual que el trigger existente. Que figure hoy en esa referencia no hace disponibles las franjas pasadas. Una franja administrativa fuera de la referencia se identifica como tal; no es una reserva. No se extiende la ventana por no tener disponibilidad hoy.

## Transacciones preparadas, sin ejecutar en la base real

SCHEDULE_WRITES_ENABLED es false por defecto e independiente de PATIENT_WRITES_ENABLED y MEDICAL_WRITES_ENABLED. El cierre comprobó que ninguno de los tres vale true. .env no fue modificado y sigue ignorado/no versionado. Las suites HTTP forzaron sus escrituras de prueba a deshabilitadas.

El camino futuro de escritura usa consultas preparadas y listas cerradas de columnas para horarios_atencion/bloqueos_horario. Comprueba la base clinica_vida_salud, permisos, ámbito y datos; vuelve a validar dentro de una transacción. Compara la versión del formulario (incluye updated_at para horarios y huella de campos para bloqueos). Identifica al actor con set_config('app.usuario_id',…,true) para los triggers existentes.

Como no existe una restricción de solapamiento de horarios/bloqueos en PostgreSQL, el repositorio prepara bloqueos de tabla de citas (SHARE) y horarios/bloqueos (SHARE ROW EXCLUSIVE) para serializar la validación y evitar DML concurrente incompatible. Estos bloqueos y el SQL de escritura **solo se probaron mediante PDO simulado**; no se ejecutaron contra PostgreSQL. Su impacto, permisos necesarios y concurrencia deberán comprobarse en un ensayo autorizado. No se agregó ni modificó ninguna restricción de BD.

Solo se ejecutaría INSERT/UPDATE de la tabla correspondiente. No se eliminan registros ni se modifica otra entidad. El trigger real de horarios actualizaría updated_at e insertaría auditoría como efecto esperado de una futura operación autorizada. Bloqueos no tiene un trigger equivalente y no se inventó uno. Errores de validación, conflictos o restricciones anteriores al commit revierten la operación; no se exponen excepciones PDO al navegador.

## Pruebas del cierre y resultados reales

Se revisaron las piezas existentes y su integración, conservando el trabajo. La documentación pendiente se completó y se añadieron pruebas de edición de bloqueos, versión obsoleta y conflicto con citas. También se corrigió la presentación que antes omitía segundos significativos. Git aún muestra el proyecto como no versionado: sin un commit base no hay un diff histórico fiable que atribuya exactamente un número de archivos a P4. El inventario verificable está al final.

Comandos ejecutados:

```powershell
composer test
php tools/verify-p4-integrity.php baseline
pwsh -NoProfile -File tests/close-p4.ps1
composer validate --strict
composer check-platform-reqs
Get-ChildItem backend,frontend,public,tests,tools -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
git check-ignore .env
git ls-files -- .env
```

El cierre agrupado ejecutó tests/horarios-database.php, auth-database.php, pacientes-database.php y medicos-database.php, además de las suites HTTP http-auth.ps1, http-pacientes.ps1, http-medicos.ps1 y http-horarios.ps1. Son consultas de lectura o simulaciones; no hubo pruebas DML reales ni consumo de identidades seguido de ROLLBACK.

| Comprobación | Resultado real |
|---|---|
| Suite Composer | 360 comprobaciones: 256 anteriores, 75 de P4 y 29 del repositorio P4 simulado |
| HTTP autenticación | 20 comprobaciones pasaron |
| HTTP pacientes | 55 comprobaciones pasaron |
| HTTP médicos/especialidades | 72 comprobaciones pasaron |
| HTTP horarios | 90 comprobaciones pasaron |
| Total HTTP | 237 comprobaciones pasaron |
| Sintaxis y plataforma | 97 archivos PHP sin errores; Composer válido y requisitos cumplidos |
| Integraciones de BD | Cuatro suites de solo lectura pasaron |
| Horarios | 90, pertenecientes a 18 médicos; cinco por médico y 18 por día hábil |
| Bloqueos reales | 0; sus casos de edición/solapamiento se simularon |
| Filtros de especialidad | Nueve filtros contrastados con consultas independientes |
| Disponibilidad | 180 médico-días contrastados entre PHP y SQL; coincidieron |
| Franjas libres en ese instante | 1722; este conteo depende de la hora de ejecución |
| Protección de citas | 60 horarios detectados al simular desactivaciones incompatibles |
| Integridad global del cierre | 21 tablas, auditoría, 19 secuencias y .env sin diferencias |

Los HTTP verificaron roles, enlaces de panel, ámbito propio del médico, manipulación de URL, CSRF, identificación inválida/inexistente, validaciones y ausencia de escrituras. Los POST válidos se rechazaron con 423, no se enviaron a un repositorio de escritura real. La autenticación exitosa con contraseñas reales ya había sido comprobada manualmente por el usuario; los HTTP autenticados del cierre utilizan sesiones locales de prueba para las cuentas existentes y pasan por los middleware de producción. No se añadió un bypass al servidor ni se solicitaron contraseñas.

Las huellas anteriores/posteriores cubrieron las **21 tablas de public**, incluida registros_auditoria, y las **19 secuencias** comparando last_value e is_called mediante SELECT. No se llamó nextval/setval. Se comprobó también la huella de .env sin imprimir su contenido. La línea base es la del inicio de esta continuación: acredita ausencia de diferencias durante el cierre y complementa las verificaciones anterior/posterior de la implementación previa; no es una copia histórica anterior al inicio de P4.

Las sesiones privadas de los fixtures fueron eliminadas al terminar; la línea base var/p4-integrity.json y los resultados var/p4-checks permanecen locales e ignorados para revisión. Solo contienen huellas o resultados controlados; no contienen contraseñas, hashes de contraseña o cuerpos de formularios. Las copias CSS/JS de public coinciden con sus fuentes de frontend. No se instalaron dependencias ni se modificaron configuraciones globales.

## Limitaciones y autorización pendiente

- No existe calendario global de feriados/días no operativos en las 21 tablas inspeccionadas. Se aplican lunes a viernes y bloqueos médicos; no se inventó una tabla ni una lista de feriados.
- La BD no impide directamente horarios/bloqueos superpuestos; la aplicación los controla, pero SQL externo puede saltarse esa validación. La solución definitiva de integridad en BD requeriría autorización estructural, fuera de esta entrega.
- Bloqueos no tiene estado, updated_at ni auditoría propia. No hay desactivación de bloqueos. La huella detecta contenidos distintos, no constituye una nueva columna de revisión ni un historial.
- No hay bloqueos reales para probar su persistencia o edición; se cubren con simulaciones. No se crearon datos para completar esa cobertura.
- No se expiran reservas en P4: una cita que siga no cancelada en PostgreSQL continúa ocupando su intervalo, aunque una reserva asociada hubiera vencido. No se modifican citas ni pagos para liberar disponibilidad.
- La inspección visual manual de escritorio/móvil y la prueba en el VirtualHost Apache siguen pendientes. Los HTTP verifican respuestas y contenido, no certifican el aspecto visual. La agenda tiene desplazamiento horizontal en pantallas pequeñas.
- Requieren autorización posterior los INSERT/UPDATE reales de horarios/bloqueos, desactivaciones, reasignaciones, efectos de los triggers, permisos de bloqueo de tablas y concurrencia real. También cualquier cambio estructural o ampliación de permisos. No se debe habilitar SCHEDULE_WRITES_ENABLED antes de acordar ese ensayo.

Para repetir solo verificaciones: composer test ejecuta memoria/archivos temporales sin BD. Las integraciones de lectura pueden ejecutarse individualmente. tests/close-p4.ps1 espera una línea base existente; tools/verify-p4-integrity.php no sobrescribe una línea base previa. Mantener sesiones/credenciales fuera del repositorio y public como única raíz web.

## Inventario P4 revisado

Piezas del módulo conservadas:

- backend/app/Controllers/HorarioController.php.
- backend/app/Repositories/ScheduleStore.php y HorarioRepository.php.
- backend/app/Services/HorarioService.php, DisponibilidadService.php, ScheduleValidator.php, ScheduleRules.php, ScheduleValidation.php y ScheduleConflict.php.
- backend/app/Middleware/ScheduleAccess.php y backend/app/Support/ScheduleFactory.php.
- frontend/views/horarios/navigation.php, filters.php, index.php, detail.php, blocks.php, agenda.php y form.php.
- tools/inspect-schedule-schema.php.
- tests/horarios.php, horarios-repository.php, horarios-database.php y http-horarios.ps1.

Puntos de integración revisados: AGENTS.md, .env.example, composer.json, backend/routes/web.php, backend/app/Repositories/Connection.php, frontend/views/layouts/management-header.php, frontend/views/dashboard.php, frontend/assets/css/app.css, public/assets/css/app.css y tests/support/patient-http-fixtures.php. También se verificaron el enrutador público, los middleware existentes y los recursos JavaScript compartidos. No se habilitaron indicadores.

Cambios realizados al reanudar:

- Completado docs/horarios.md y actualizado README.md.
- Ajustados ScheduleValidator.php y frontend/views/horarios/agenda.php para no ocultar precisión horaria.
- Ampliados tests/horarios.php y tests/horarios-repository.php para edición de bloqueos y huellas; tests/horarios-database.php comprueba permisos actuales.
- Añadidos tools/verify-p4-integrity.php y tests/close-p4.ps1 para línea base, regresiones e integridad.

No se borraron archivos existentes ni se modificaron datos de PostgreSQL, usuarios, .env, auditorías o secuencias. P5 permanece fuera de alcance.
