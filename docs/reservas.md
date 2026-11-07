# P5-B — Reserva de citas

Entrega del 6 de noviembre de 2026. Implementación preparada, escrituras persistentes deshabilitadas. P5-C y P9 no implementados.

## Alcance y esquema comprobado

Se retomaron los requisitos textuales RF10/CU08 de los DOCX, docs/citas.md, docs/horarios.md y el motor DisponibilidadService. La revisión documental no incluye validación de imágenes/diagramas incrustados. Se contrastaron columnas, claves, permisos, restricciones y triggers del PostgreSQL existente mediante lectura.

El registro inserta solamente una fila en public.citas: paciente, médico, especialidad, código aleatorio de 28 caracteres, fecha, inicio, fin calculado y estado programada. Los demás campos utilizan los valores predeterminados existentes. El trigger de auditoría genera su registro y recibe el usuario autenticado mediante set_config local a la transacción. No se cambian tablas ni funciones. No se crean pagos ni reservas_provisionales: su vencimiento depende del método de pago pendiente de P9; no se inventa un plazo.

Paciente requiere gestionar_citas_propias y su id_paciente se resuelve mediante pacientes.id_usuario de la sesión. Enviar un campo paciente desde esa cuenta se rechaza. Recepcionista y Administrador necesitan gestionar_citas y acceso a disponibilidad; seleccionan un paciente existente. Médico no puede reservar. Se conservan los ámbitos de lectura de P5-A en la confirmación.

## Flujo y seguridad

1. Entrar a disponibilidad desde Citas, elegir médico, especialidad, fecha y una hora libre; consultar la selección y pulsar **Continuar a reserva**.
2. Revisar el resumen; recepción selecciona además el paciente existente.
3. Con escritura autorizada y habilitada, confirmar por POST con CSRF e intención individual de 15 minutos. La sesión PHP serializa los envíos de la misma sesión; una intención completada redirige a la misma cita al repetirse.
4. Dentro de una transacción se bloquean las tablas de calendario frente a cambios y la fila del médico frente a otras reservas del módulo. Se vuelve a consultar disponibilidad con reloj de PostgreSQL en America/La_Paz, duración y anclaje del horario. El fin y el estado no se aceptan del navegador.
5. Se inserta la cita y se confirma la transacción. El código se muestra en una confirmación restringida al ámbito del usuario. P5-A consulta las mismas tablas y muestra inmediatamente la nueva cita.

La exclusión PostgreSQL por médico e intervalo para estados distintos de cancelada es la última protección ante otras aplicaciones concurrentes. Los triggers conservan las validaciones de ventana de diez días hábiles, horario, duración, médico y bloqueos. Errores de exclusión, claves, validación o bloqueo provocan rollback y mensajes sin detalles SQL. Los errores de resultado incierto solicitan revisar el listado antes de reintentar.

La idempotencia es local a la sesión/formulario, no una garantía duradera entre sesiones o tras perder la sesión. La exclusión del turno permanece en PostgreSQL. No se ejecutaron INSERT ni pruebas con rollback contra la base real: una secuencia puede avanzar incluso si se revierte una transacción.

## Cómo probar

Mantener el sitio Apache existente con DocumentRoot en public/. Alternativa local, desde la raíz:

```powershell
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

Abrir http://127.0.0.1:8080/login. Con paciente01, ir a /mis-citas/disponibilidad; con usuario2, a /citas/disponibilidad. Seleccionar una fecha hábil futura dentro de la ventana y una franja disponible. El formulario de paciente muestra únicamente su titular; recepción puede elegir uno de los 30 pacientes. Actualmente se puede revisar el flujo, pero el botón final está deshabilitado y un POST válido devuelve 423.

APPOINTMENT_WRITES_ENABLED está ausente/deshabilitado en el entorno actual y vale false por defecto; se documenta en .env.example. No se modificó .env. Solo después de autorizar expresamente una prueba persistente puede habilitarse para un servidor temporal mediante variable de proceso, sin editar .env:

```powershell
$env:APPOINTMENT_WRITES_ENABLED='true'
php -S 127.0.0.1:8080 -t public tools/dev-router.php
# Al terminar el servidor con Ctrl+C:
Remove-Item Env:APPOINTMENT_WRITES_ENABLED
```

Estos comandos no configuran Apache; para Apache la bandera requiere configuración específica autorizada. No habilitar PATIENT_WRITES_ENABLED, MEDICAL_WRITES_ENABLED ni SCHEDULE_WRITES_ENABLED.

Cada confirmación persistente creará una cita programada y el registro de auditoría correspondiente, avanzando sus secuencias. Probar una reserva con cada cuenta crearía dos citas nuevas y sus auditorías; deben acordarse previamente pacientes, médicos y turnos exactos. No borrar las citas después como limpieza automática. No se solicitaron ni ejecutaron estas escrituras durante la entrega.

## Verificación ejecutada

- composer test: 472 comprobaciones; incluye 28 nuevas de entradas, permisos, intenciones e integración con almacenamiento simulado y 19 transaccionales sobre PDO simulado.
- composer validate --strict: correcto. Lint: 127 archivos PHP sin errores.
- tests/http-reservas.ps1: 30 comprobaciones HTTP con sesiones temporales de las cuatro cuentas, CSRF 419, intención inválida 409, suplantación 403, confirmación ajena 404 y bloqueo persistente 423. El script se niega a ejecutarse con reservas habilitadas.
- tests/http-citas.ps1: 144 comprobaciones de regresión HTTP P5-A.
- tests/citas-database.php: 60 citas distintas, seis páginas de diez, filtros y ámbitos reales (Médico: 4 propias; Paciente: 2 propias), disponibilidad y exclusión PostgreSQL.
- tools/verify-p4-integrity.php verify p5b: 21 tablas, incluida auditoría, y 19 secuencias (last_value e is_called) idénticas a la línea base privada previa. .env sin cambios. Continúan 60 citas, 90 horarios, 0 bloqueos y 30 pacientes. .env sigue excluido por Git.

Pendiente: INSERT/commit real, respuesta de triggers/auditoría durante una reserva nueva y dos solicitudes concurrentes en un entorno aislado o bajo autorización concreta. Las simulaciones cubren SQLSTATE de conflicto, rollback, turno ocupado y duración de 45 minutos; no equivalen a una prueba concurrente real. No se repitieron todas las pruebas HTTP de P2/P3/P4; sus regresiones en memoria sí pasaron dentro de composer test.

## Archivos

Nuevos: Controllers/ReservaController; Middleware/BookingAccess; Repositories/BookingStore y ReservaRepository; Services/BookingInput, BookingIntent, BookingConflict y ReservaService; Support/BookingFactory, todos bajo backend/app/. Vistas frontend/views/citas/booking.php, booking_error.php y confirmation.php. Pruebas tests/reservas.php, reservas-repository.php y http-reservas.ps1. Este documento.

Modificados: backend/app/Repositories/Connection.php, backend/routes/web.php, frontend/views/citas/availability.php, composer.json, .env.example, AGENTS.md, README.md, docs/citas.md y tools/verify-p4-integrity.php. Sin migraciones, seeds, cambios de credenciales ni configuración global.

Continuidad: P5-C ya está implementado con guardado bloqueado; ver [cambios-citas.md](cambios-citas.md). Las referencias anteriores a P5-C pendiente describen el cierre histórico de P5-B.
