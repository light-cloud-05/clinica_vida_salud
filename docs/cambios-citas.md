# P5-C — Reprogramación y cancelación

Implementación del 6 de noviembre de 2026. P5-A/P5-B se reutilizan; guardado real bloqueado por defecto. No se implementa P9.

## Reglas comprobadas

Se extrajo texto de los dos DOCX de docs/requisitos y se revisaron RF12/RF13, CU09/CU10 y las reglas de cancelación con pagos. No se verificaron sus imágenes ni diagramas. Se revisaron docs/horarios.md, docs/reservas.md y el código existente. Mediante SELECT se comprobaron columnas, permisos, restricciones, triggers y la firma/cuerpo reales de cancelar_cita(bigint,text).

- Solo citas futuras programada/confirmada. Atendida, cancelada e inasistencia no se modifican. El procedimiento admite programada y confirmada; el servicio añade la condición de futuro de los requisitos.
- Una reprogramación como máximo. Se conserva paciente, médico, especialidad, código y estado. RF12 solicita cambiar fecha/hora; no se incorpora cambio de médico/especialidad. Así también se evita cambiar el concepto de un pago existente y se respeta el historial real, que registra fechas/horas/estados.
- El nuevo turno respeta diez días hábiles, zona America/La_Paz, duración/anclaje del horario, médico activo, bloqueos y otras citas. Se excluye exclusivamente la propia cita del cálculo de ocupación. No se acepta una modificación sin cambio de fecha/hora.
- El trigger fn_controlar_reprogramacion incrementa cantidad_reprogramaciones. La aplicación no escribe manualmente ese contador. Los triggers existentes actualizan updated_at, historial_cambios_cita y registros_auditoria.
- Cancelar llama al procedimiento existente, conserva la cita y su motivo opcional y libera la franja: tanto la exclusión PostgreSQL como DisponibilidadService omiten cancelada. No hay eliminación física.
- El motivo tiene un límite técnico de formulario de 2000 caracteres, validado en PHP; el esquema usa text sin ese límite. No se presenta como regla de negocio de PostgreSQL.

## Permisos, rutas y seguridad

Administrador y Recepcionista utilizan gestionar_citas y el acceso existente a disponibilidad. Paciente utiliza gestionar_citas_propias y las consultas restringen pacientes.id_usuario al usuario autenticado. Médico no tiene gestionar_citas y no puede modificar ni siquiera sus citas; mantiene las consultas de P5-A.

| Cuenta | Acceso desde el navegador |
| --- | --- |
| usuario1 | /citas → detalle → Reprogramar o Cancelar cita |
| usuario2 | /citas → detalle → Reprogramar o Cancelar cita |
| paciente01 | /mis-citas → detalle de una cita propia → Reprogramar o Cancelar cita |
| profesional01 | /citas y agenda propias; sin enlaces de gestión; las rutas de cambio devuelven 403 |

Rutas GET/POST: /citas/reprogramar, /citas/cancelar, /mis-citas/reprogramar y /mis-citas/cancelar. Requieren id; reprogramación permite consultar fecha por GET. Los formularios envían CSRF y versión de la cita. El servidor rechaza campos ajenos al formulario (paciente, médico, estado, contador, etc.). Una cita ajena se oculta con 404.

Con guardado habilitado, una transacción usa el mismo orden de bloqueos de P5-B/P4: citas, calendario, médico y fila de cita. La versión se comprueba nuevamente bajo bloqueo; una segunda petición con el formulario anterior falla con 409. Se usa reloj de PostgreSQL, se recalcula disponibilidad y se conserva la exclusión PostgreSQL como protección final. Errores de restricciones, bloqueo y serialización provocan rollback y mensajes sin SQL ni credenciales.

La confirmación de éxito se crea únicamente después de commit y se muestra al volver al detalle. Con guardado bloqueado, POST devuelve 423 y dice expresamente que no se reprogramó ni canceló la cita. No hay simulaciones presentadas como guardados reales.

## Pagos y reservas provisionales

pagos.id_cita y reservas_provisionales.id_cita referencian citas. El esquema admite paypal_sandbox, qr_simulado y efectivo. Actualmente existen 0 pagos y 0 reservas provisionales.

Al cancelar, el procedimiento real cambia a cancelada la reserva provisional pendiente de esa cita. No cambia reservas confirmadas/expiradas ni modifica, elimina o reembolsa pagos: incluso un pago confirmado se conserva con su estado y su vínculo histórico. El procedimiento puede generar auditoría adicional al modificar una reserva pendiente.

Al reprogramar se conserva el id_cita y sus relaciones; no se cambia ningún pago ni la reserva provisional, incluido su vencimiento. Un vencimiento anterior puede seguir aplicándose: falta decidir en P9 cómo coordinar reprogramación y expiración. También quedan pendientes las políticas de devolución, saldo a favor, autorización de devoluciones, tratamiento de pagos pendientes y compensaciones. No se inventaron ni automatizaron esas decisiones. La advertencia aparece en ambos formularios. Notificaciones por correo de CU22 permanecen fuera de esta entrega.

## Cómo revisar sin escribir

Usar el sitio Apache existente con DocumentRoot en public/, o desde la raíz:

```powershell
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

Abrir http://127.0.0.1:8080/login con las cuentas ya habilitadas y seguir la tabla anterior. Para reprogramar, consultar una fecha hábil dentro de la ventana; el formulario muestra franjas y conserva el horario actual solo como referencia. Para cancelar, revisar la advertencia e introducir opcionalmente un motivo. Los botones finales están deshabilitados. No se modificó .env ni se activaron cuentas.

APPOINTMENT_CHANGES_ENABLED=false controla exclusivamente P5-C; APPOINTMENT_WRITES_ENABLED=false continúa controlando P5-B. Las banderas de pacientes, médicos y horarios siguen deshabilitadas. Ninguna bandera habilita automáticamente la otra.

## Pruebas ejecutadas

- composer test: 526 comprobaciones, incluidas las regresiones en memoria anteriores y 54 nuevas de P5-C (31 de políticas/validaciones/servicio/rutas y 23 del repositorio con PDO simulado).
- tests/http-cita-changes.ps1: 56 comprobaciones HTTP con las cuatro cuentas. Incluye formularios reales de lectura, permisos, CSRF 419, versión obsoleta 409, campos manipulados 422, citas ajenas 404 y guardado bloqueado 423 sin mensajes falsos de éxito.
- tests/http-reservas.ps1: 30 comprobaciones HTTP de regresión P5-B.
- tests/citas-database.php: lectura de las 60 citas, seis páginas, filtros y privacidad; Médico tiene 4 propias y Paciente 2. También consulta disponibilidad P4 y la restricción de exclusión.
- composer validate --strict: correcto. Lint PHP: 136 archivos sin errores de sintaxis. git diff --check correcto; .env excluido por Git.
- tools/verify-p4-integrity.php baseline p5c y verify p5c: las 21 tablas (incluidas auditoría e historial), 19 secuencias (last_value/is_called) y .env no cambiaron. Continúan 60 citas, 90 horarios, 0 bloqueos y 30 pacientes. La inspección contó 60 citas futuras al momento de la revisión.

No se ejecutaron INSERT, UPDATE, CALL de negocio ni pruebas de rollback sobre la base real. Los errores de concurrencia se simularon; no se afirma una prueba simultánea real. Para optimizar la entrega no se repitieron todas las suites HTTP anteriores. tests/http-citas.ps1 se actualizó para no esperar 404 en las rutas recién implementadas; su ejecución completa no se repitió en esta entrega.

## Prueba controlada propuesta y habilitación pendiente

Se necesita validar commit real, procedimiento, triggers, actor de auditoría y concurrencia de P5-B/P5-C. Un rollback sobre la base original no garantiza conservar las secuencias, por lo que no se propone usarlo allí.

Propuesta sujeta a autorización: preparar una copia en una instancia/cluster PostgreSQL separado, con otro puerto y la base llamada clinica_vida_salud (las fábricas verifican ese nombre). Mantener completamente separadas sus conexiones del servidor original. El volcado de origen sería solo lectura y su restauración escribiría únicamente en la instancia aislada. No se preparó ni creó esa instancia durante esta entrega.

1. Verificar host/puerto/base destino antes de habilitar las banderas. Configurar variables del proceso del servidor temporal (DB_HOST/DB_PORT, sin editar .env); las credenciales no van en argumentos ni archivos versionados.
2. Solo en la copia, habilitar APPOINTMENT_WRITES_ENABLED y APPOINTMENT_CHANGES_ENABLED. Mantener las otras tres banderas deshabilitadas. No ejecutar los scripts HTTP de guardado bloqueado con estas banderas activas: se niegan a continuar.
3. Crear una cita nueva con paciente01 y otra con usuario2, usando franjas libres. No editar las 60 originales, ni siquiera en la prueba funcional.
4. Reprogramar una de las nuevas, comprobar contador=1, historial/actor/auditoría y liberación de la franja anterior. Rechazar la segunda reprogramación. Cancelarla y comprobar franja libre, conservación del registro e historial. Comprobar que Médico no gestiona y que Paciente no accede a la cita ajena.
5. Con dos conexiones y sesiones distintas, competir por la misma franja y modificar la misma cita nueva con una versión anterior. Comprobar una sola reserva aceptada, rechazo/rollback del conflicto, ausencia de cambios parciales y ningún pago modificado. Las secuencias que avancen serán exclusivamente las de la copia.
6. Deshabilitar banderas al terminar y comparar otra vez la huella de las 21 tablas y 19 secuencias originales. No copiar datos de vuelta ni limpiar citas mediante DELETE en la base original.

Tras superar la prueba aislada, acordar autorización y turnos exactos para una demostración persistente local y habilitar únicamente las dos banderas necesarias. Reservar creará cita/auditoría; reprogramar creará historial/auditoría; cancelar puede además modificar una reserva pendiente y su auditoría. Ningún paso elimina las citas originales. Falta decidir los casos de pagos/reservas descritos arriba antes de integrar P9; las pruebas de esos casos deben hacerse en la copia.

## Archivos P5-C

Nuevos: backend/app/Controllers/CitaChangeController.php; Repositories/CitaChangeStore.php y CitaChangeRepository.php; Services/CitaChangePolicy.php y CitaChangeService.php; Support/CitaChangeFactory.php (bajo backend/app/); frontend/views/citas/change.php; tests/cita-changes.php, cita-changes-repository.php y http-cita-changes.ps1; este documento.

Integración mínima: backend/routes/web.php, backend/app/Repositories/Connection.php, frontend/views/citas/show.php, composer.json, .env.example, AGENTS.md, README.md, docs/citas.md, tests/http-citas.ps1, tools/inspect-appointment-schema.php y tools/verify-p4-integrity.php. No se reescribieron los servicios o repositorios de P5-A/P5-B.
