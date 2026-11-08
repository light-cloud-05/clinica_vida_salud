# Entrega final — Clínica Vida Salud

## Identificación y estado comprobado

- URL: https://clinica-vida-salud-vgve.onrender.com/
- Repositorio: https://github.com/light-cloud-05/clinica_vida_salud ; rama main.
- Último commit público previo a esta revisión: 8e3811d (portada y estadísticas).
- Commit de cierre: el commit funcional que contiene este archivo; obtener el
  SHA exacto con `git log -1 --format=%H`. Se confirma también en la respuesta
  de publicación y en `/health` cuando Render haya desplegado esa revisión.
- Servicio Render existente Docker Free. No se creó otro servicio ni una etiqueta.
- La versión pública previa pasó 37 pruebas HTTP reales con las cuatro cuentas.
- El despliegue de esta revisión requiere Manual Deploy > Deploy latest commit;
  Auto-Deploy no se cambió. El acceso automatizado a Firefox no fue autorizado
  a tiempo; no se declara desplegado el código nuevo sin observarlo en Render.

## Arquitectura y tecnologías

PHP nativo 8.x (imagen productiva PHP 8.3 Apache), PostgreSQL 18, PDO_PGSQL,
Bootstrap 5, JavaScript y Chart.js 4.5.1 local con licencia MIT. Dompdf para PDF;
CSV nativo; sin dependencia XLSX instalada. Solo public/ es DocumentRoot.

Capas: frontend/views y assets (presentación), routes/controllers/middleware
(aplicación), Services (reglas), Repositories/PDO (persistencia). Consultas
parametrizadas; America/La_Paz. El contenedor instala bcmath, curl, mbstring,
pdo_pgsql y pgsql antes de Composer, comprueba requisitos y sintaxis Apache.

## Módulos y verificación

| Módulo | Implementado | Evidencia y límite |
|---|---|---|
| Acceso/usuarios | Login, logout, sesiones, roles, CSRF, throttle, estados, hashes | Pruebas memoria y 4 logins HTTPS reales. No se cambiaron contraseñas demo. |
| Recuperación | Token hash de 30 minutos, un uso, límite de solicitudes, SMTP TLS | Pruebas en memoria; envío externo pendiente de SMTP/APP_URL. Local prohibido en producción. |
| Pacientes | Alta, búsqueda, edición, estado, perfil/contactos/emergencia | Validadores y repositorios simulados; mutaciones protegidas por flags. |
| Médicos/especialidades | Catálogo, asociación, edición/estado | Pruebas de dominio/repositorios; consultas reales locales. |
| Horarios/citas | Disponibilidad, reserva, confirmación, reprogramación única, cancelación e historial | Pruebas de ventanas, solapamientos, propiedad, transacciones simuladas; no se ensayaron escrituras sobre bases protegidas. |
| Asistencia/clínica | Confirmar, atender, inasistencia, notas y tratamientos autorizados | Regresiones de transiciones, CSRF y privacidad; no se presenta asistencia laboral médica. |
| Pagos académicos | QR simulado, PayPal Sandbox, efectivo, vencimientos, transacciones y PDF | Simulaciones; PAYMENT_WRITES_ENABLED permanece protegido. Sin cobros reales ni prueba real de PayPal sin credenciales. |
| Paneles | Resumen, estados y próximas citas del ámbito autorizado, accesos por rol | Usa el mismo servicio P6 con scope de servidor; sin cifras decorativas. |
| Reportes | Sidebar interno, resumen sticky, Chart.js, filtros, pacientes/citas/médicos/especialidades, agendas, atención/incidencias, CSV/PDF | 25 pruebas P6, 16 de cálculo; 408 verificaciones PostgreSQL local READ ONLY. Médico/paciente solo datos propios, incluido export. |
| Auditoría | Lectura administrativa e historial, registro mediante triggers/contexto de usuario | Pruebas simuladas; no hay endpoint de borrado/modificación del histórico. |
| Notificaciones | Registro, cambios, cancelación, recordatorio, estados/reintentos, SMTP | Sin SMTP registra error; nunca enviado ficticio. IDENTITY en lugar de MAX+1. No se enviaron correos reales. |
| Voz | Web Speech API + alternativa escrita, consultas prefijadas por rol | Pruebas de interpretación/repositorio; dictado real depende de navegador, permisos e Internet. No diagnostica. |

## Correcciones de este cierre

- Comparación semántica estricta de seis CHECK de estado: tabla, nombre,
  columna, banderas y valores exactos. No se ignoran CHECK ni restricciones.
  Se toleran solo representaciones equivalentes de casts/paréntesis/array.
- Diagnóstico de migración con códigos ASCII cerrados y captura UTF-8 de
  stdout/stderr independiente en PowerShell; no se reflejan excepciones nativas.
- PHP CLI independiente 8.3.36/libpq 14.24 comprobado. mbstring se carga de su
  propio paquete por proceso; no se reemplazó XAMPP ni se cambió php.ini/.env.
- Privacidad P6 corregida para médico/paciente, también CSV/PDF y carga.
- Sidebar interno, gráficos Chart.js locales y tablas conservadas con ceros.
- Paneles por rol con información real; eliminada promesa de módulos futuros.
- SMTP sin éxito ficticio, cabeceras seguras, TLS y dot-stuffing; recuperación
  por SMTP habilitable mediante configuración privada.
- Notificaciones respetan su flag independiente y usan la identidad PostgreSQL
  existente para evitar colisiones de MAX(id)+1.
- `/health` expone solo liveness/commit, sin diagnósticos de BD ni secretos.
- Backups y DPAPI excluidos; herramientas/SQL/docs no se copian a la imagen.

## Base de datos

Origen local verificado READ ONLY: 21 tablas; 9 especialidades, 18 médicos,
30 pacientes, 18 asociaciones, 90 horarios, 60 citas futuras del 9 al 12 de
noviembre de 2026. No se modificó ningún registro, usuario, secuencia o esquema.

Render: inicialización y cuatro cuentas confirmadas por el usuario; login,
consultas y exportaciones reales de los cuatro roles comprobadas vía HTTPS.
No se volvió a ejecutar render.sql ni seed-demo.php. No hubo -Apply ni DDL.

La simulación con baseline conocido prevé totales 10 especialidades, 20 médicos,
32 pacientes, 20 relaciones, 94 horarios y 60 citas. Conserva Pediatría Render
(Bs 150) frente a local (Bs 30) y mantiene las cuatro cuentas/contraseñas.
Eso NO es un conteo actual de Render ni un plan real autorizado. Falta introducir
privadamente la URL rotada y revisar el SHA256 de una simulación real. Una
migración solo podrá aplicar ese hash y la confirmación explícita existentes.

## Configuración y protecciones

Render: APP_ENV=production, DATABASE_URL=Internal Database URL rotada,
DB_SCHEMA=public, DB_CHECK_ENABLED=false. PORT lo proporciona Render.
Nunca guardar sus valores secretos en Git. Cookies Secure/HttpOnly/SameSite=Lax.

Flags independientes, desactivados por defecto: PATIENT_WRITES_ENABLED,
PATIENT_ACCOUNT_WRITES_ENABLED, PASSWORD_RECOVERY_WRITES_ENABLED,
PATIENT_PROFILE_WRITES_ENABLED, MEDICAL_WRITES_ENABLED,
MEDICAL_RECORD_WRITES_ENABLED, SCHEDULE_WRITES_ENABLED,
APPOINTMENT_WRITES_ENABLED, APPOINTMENT_CHANGES_ENABLED,
ATTENDANCE_WRITES_ENABLED, USER_WRITES_ENABLED, NOTIFICATION_WRITES_ENABLED,
PAYMENT_WRITES_ENABLED. No se activaron flags del servicio durante este cierre.

SMTP: MAIL_TRANSPORT=smtp, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD,
MAIL_FROM, MAIL_ENCRYPTION=tls/ssl, NOTIFICATION_MAX_RETRIES. Para recuperación:
PASSWORD_RESET_TRANSPORT=smtp y APP_URL HTTPS público. Configuración SMTP,
prueba de entrega externa y ejecución programada de recordatorios pendientes.
PayPal: únicamente API Sandbox, sus credenciales privadas y margen de reserva;
no habilitar pagos productivos reales. Los comprobantes son académicos, no fiscales.

## Pruebas y matriz de caja negra

`composer test`, `composer validate --strict`, lint PHP y `git diff --check` son
las verificaciones de cierre. Las suites incluyen auth/roles/pacientes/médicos,
horarios/reservas/cambios/asistencia/clínica, pagos/reportes/export/auditoría,
notificaciones/voz y nuevas regresiones de SMTP/migración. No se atribuyen a
Render las simulaciones en memoria ni las 408 comprobaciones locales de lectura.

La matriz original CN-01 a CN-19 no se encontró en los archivos de requisitos
inspeccionados mediante extracción OOXML; por ello no se inventa una
correspondencia ni se certifican esos casos manuales. La cobertura automatizada
se detalla en las suites y en los resultados anteriores.
Las pruebas manuales de tablet/móvil de todos los módulos, gráficos Chart.js,
PDF extensos, dictado y flujos de escritura no se certifican sin observarlos.
La portada previa se comprobó a escritorio y 390 px; no se afirma prueba a 320 px.

## Acceso seguro y pasos que requieren intervención externa

Las cuentas demo son demo_admin, demo_recepcion, demo_medico y demo_paciente.
Las contraseñas permanecen en el archivo DPAPI privado del usuario Windows o
su gestor de contraseñas; no se incluyen aquí. verify-render-http.ps1 las lee
solo en memoria para login/lectura/logout y oculta errores nativos.

Para cerrar el despliegue: Manual Deploy > Deploy latest commit en el servicio
existente, comprobar Live, comparar el commit de `/health` con origin/main y
repetir verify-render-http.ps1. No crear otro servicio ni repetir SQL/seed.
No se declara terminado un build ni el despliegue si ese resultado no se observó.
