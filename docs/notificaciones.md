# CU22 — Notificaciones y recordatorios

Implementa RF22 y RF23 usando la tabla existente `public.notificaciones`. No se agregaron tablas, columnas, migraciones ni seeds.

## Comportamiento

- Al confirmar una reserva se encola `registro_cita`.
- Al confirmar una reprogramación se encola `reprogramacion_cita`.
- Al confirmar una cancelación se encola `cancelacion_cita`.
- El encolado ocurre después del `commit` de la cita. Un bloqueo transaccional por `id_cita` y tipo evita duplicados ante reintentos.
- El mensaje contiene únicamente código, fecha, hora, médico, especialidad y estado.
- El recordatorio usa `programada` o `confirmada`, excluye canceladas, atendidas y vencidas, y se deduplica por `id_cita` y `recordatorio_cita`.

## Correo y estados

El transporte predeterminado es `local`: no entrega correo y provoca estado `error`; nunca marca un envío ficticio como `enviado`. Un transporte SMTP nativo se activa solamente si `MAIL_TRANSPORT=smtp` está configurado en el entorno de despliegue. Sus variables son `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM` y `MAIL_ENCRYPTION` (`tls` o `ssl`). Nunca se imprimen contraseñas ni tokens.

Los estados persistidos son `pendiente`, `enviado` y `error`. El envío intenta como máximo `NOTIFICATION_MAX_RETRIES` veces (por defecto 2). La escritura de notificaciones requiere `NOTIFICATION_WRITES_ENABLED=true`; permanece desactivada por defecto junto con las escrituras de citas existentes.

## Consulta

- Paciente: `GET /mis-notificaciones`, limitado por `pacientes.id_usuario` a sus propias citas.
- Administrador con `consultar_auditoria`: `GET /notificaciones`, muestra estado y destinatario.

## Recordatorios programados

El comando es:

```text
php tools/send-reminders.php
```

La anticipación predeterminada es `REMINDER_LEAD_MINUTES=1440` (24 horas). Puede sobrescribirse sin modificar archivos:

```text
php tools/send-reminders.php --minutes=120 --limit=100
```

En Windows Task Scheduler se recomienda ejecutar el comando desde la raíz del proyecto cada 15 minutos. En Linux, un cron equivalente es `*/15 * * * * cd /ruta/clinica_vida_salud && php tools/send-reminders.php`. El despliegue debe proporcionar la configuración de base de datos y habilitar explícitamente `NOTIFICATION_WRITES_ENABLED=true`; la aplicación valida el destino de integración antes de escribir.

## Verificación realizada

`tests/cu22-notificaciones.php` usa un repositorio y transporte simulados para comprobar registro, reprogramación, cancelación, deduplicación, recordatorio vigente y correo fallido. No envía correos a pacientes ni modifica PostgreSQL. La suite `composer test` conserva las pruebas de P5 y autenticación.

Revisión final: se utiliza IDENTITY para id_notificacion (verificado en PostgreSQL), evitando MAX(id)+1 en concurrencia. Las fábricas de reservas/cambios solo inyectan notificaciones si NOTIFICATION_WRITES_ENABLED=true. SMTP valida TLS, cabeceras y destinatarios, aplica dot-stuffing y no registra credenciales. SMTP real no probado sin configuración externa.
