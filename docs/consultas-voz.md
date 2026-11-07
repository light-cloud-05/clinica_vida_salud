# P10 — Consultas por voz y texto

Entrega: 7 de noviembre de 2026. Implementación de RF29/CU27, sin avanzar a otros módulos ni rehacer P9.

## Funcionalidad disponible

La página `/consultas-voz` permite escribir una consulta o dictarla cuando el navegador ofrece `SpeechRecognition` o `webkitSpeechRecognition`. El reconocimiento se configura con `lang = es-BO`, una frase por activación y resultados finales. La transcripción aparece en un campo editable; el usuario la revisa y pulsa **Consultar en PostgreSQL**. Hablar no envía automáticamente la consulta.

La alternativa escrita es un formulario HTML normal: funciona sin reconocimiento, sin micrófono y también con JavaScript desactivado. Los resultados se renderizan en PHP con resumen, tabla, origen y hora de consulta en `America/La_Paz`. Una consulta sin resultados lo indica; no se generan registros ni respuestas ficticias.

El navegador puede leer el resumen mediante síntesis de voz. La opción está desactivada por defecto. Se debe marcar **Habilitar lectura del resumen de la respuesta** y pulsar **Leer respuesta**. Se intenta seleccionar una voz `es-BO`, después otra voz española disponible. La disponibilidad de esa voz depende del equipo. Desmarcar la opción o pulsar **Detener lectura** cancela el audio. No se leen las filas individuales, códigos, importes ni nombres de pacientes.

## Consultas admitidas

El intérprete utiliza una lista cerrada de expresiones, tolerando mayúsculas, tildes y signos habituales. No es una IA de lenguaje libre ni convierte texto en SQL.

| Ejemplo | Resultado |
| --- | --- |
| «¿Cuáles son mis próximas citas?» | Hasta diez citas futuras programadas o confirmadas, dentro del ámbito del usuario. También admite «Mis próximas citas» y «Muéstrame mis próximas citas». |
| «Muéstrame mi agenda de hoy» | Hasta diez citas de la fecha real de La Paz, con su estado. Para Médico, únicamente las de su usuario vinculado. |
| «¿Qué médicos atienden pediatría?» | Médicos activos autorizados asociados a esa especialidad activa. También admite «Qué médicos atienden medicina general». |
| «¿Qué horarios hay disponibles?» | Hasta diez franjas libres en los próximos diez días hábiles, considerando horarios, duración médica, citas y bloqueos existentes. |
| «Horarios disponibles de medicina general» | La misma consulta filtrada por el nombre de la especialidad registrada. El campo de fecha permite acotar a un día válido de la ventana P5. |
| «¿Cuál es el estado de mi pago?» | Hasta diez pagos de PostgreSQL autorizados, separando estado de pago y de reserva. También admite «Mis pagos». Médico no tiene acceso. |

La fecha opcional solo corresponde a horarios. No cambia la fecha de «hoy» ni el reloj del sistema. Si se deja vacía se usa la ventana completa. Las franjas mostradas no reservan ni garantizan un turno; la consulta completa existente permite continuar revisando el calendario manualmente.

Se comprobó por SELECT que **Pediatría sí está registrada** en la base actual. Si no hay médicos de esa especialidad dentro del ámbito del Médico, se informa la ausencia de resultados autorizados. Para una especialidad inexistente se informa que no se encontró en el catálogo activo, sin inventarla.

En la verificación actual no hay pagos registrados en PostgreSQL. La respuesta informa esa ausencia y explica que los ensayos locales de P9 se consultan por separado en **Pagos de demostración**. No se interpreta esa ausencia como pago confirmado ni como saldo cero. P10 no lee ni modifica el archivo de simulación P9, y tampoco solicita acciones a PayPal.

## Autorización y arquitectura

Antes de implementar se leyeron los requisitos textuales de los dos DOCX de `docs/requisitos`. Se contrastaron columnas y permisos reales por consultas de solo lectura: `realizar_consultas_voz` ya está asignado a los cuatro roles. No se añadieron permisos, migraciones ni seeds.

Las rutas GET `/consultas-voz` y POST `/consultas-voz/consultar` reutilizan autenticación y autorización del enrutador existente. Se exige sesión vigente y `realizar_consultas_voz`; el POST además exige CSRF. El servicio y el repositorio vuelven a comprobar el permiso de la consulta concreta:

| Rol | Ámbito |
| --- | --- |
| Paciente | Citas relacionadas mediante `pacientes.id_usuario = usuario autenticado`; pagos propios; catálogo y disponibilidad autorizados por P5. No se confunde `id_usuario` con `id_paciente`. |
| Médico | Citas y disponibilidad de `medicos.id_usuario = usuario autenticado`. La consulta de médicos por especialidad se limita a su ámbito médico. Sin pagos. |
| Recepcionista | Agenda y consultas administrativas con `consultar_agenda`; pagos con `consultar_pagos_autorizados`. |
| Administrador | Consultas administrativas sujetas a sus permisos reales; el nombre del rol no omite esas comprobaciones. |

Se reutilizan `CitaAccess`, `PaymentAccess`, `CitaService`, `CitaRepository`, `HorarioRepository`, `CitaAvailability`, `DisponibilidadService` y `PaymentRepository`. Las nuevas consultas de próximas citas necesitan un filtro temporal que el listado paginado anterior no ofrecía; están encapsuladas en el repositorio P10 con proyección mínima, SQL fijo, parámetros y ámbito por usuario.

La disponibilidad reutiliza el motor P4/P5 con lectura de horarios, bloqueos y citas en conjunto, y devuelve solo franjas libres. La conexión se crea exclusivamente con `Connection::readOnly`; no existen métodos de escritura en el contrato `VoiceQueryStore`.

La vista y el JavaScript solo presentan datos y gestionan reconocimiento/síntesis. El servidor decide intención y autorización. Campos de identidad o filtros de paciente/médico ajenos al formulario se rechazan con 422. Una frase que pide la agenda de otro médico no se convierte en un filtro autorizado.

## Seguridad, privacidad y límites

- Solo se inicia el micrófono mediante clic explícito; `continuous=false`. Hay botón de detención, límite de 12 segundos y cancelación al enviar el formulario o salir de la página. No se reinicia automáticamente. Un inicio tardío posterior a la cancelación también se aborta.
- No se utilizan `MediaRecorder`, almacenamiento de audio, historial de transcripciones, `localStorage`, servicios de IA ni claves de pago. La aplicación recibe únicamente el texto que el usuario decide enviar por POST.
- El aviso visible explica que el reconocimiento depende del navegador y puede usar servicios externos de su proveedor. La aplicación no garantiza el tratamiento que ese proveedor realice del audio; conviene dictar solamente preguntas generales sin datos sensibles.
- Se requiere contexto seguro para habilitar el reconocimiento: HTTPS o localhost. Si falta API o contexto seguro, se desactiva el botón y queda disponible el formulario escrito.
- Se manejan permisos denegados, servicio no permitido, ausencia de micrófono, falta de voz, error de red, idioma no soportado, cancelación y fallo al iniciar. La interfaz conserva el texto y permite consultar por escrito.
- Entrada: hasta 240 caracteres UTF-8 y sin caracteres de control. Interpretación cerrada, sin SQL arbitrario, órdenes compuestas, reservas, cancelaciones, reprogramaciones, pagos, diagnósticos ni recetas. Comandos no reconocidos devuelven 422 con orientación.
- Todas las salidas se escapan en HTML. El audio usa solo el resumen generado por el servidor, nunca la consulta original ni una cadena con tokens o credenciales. Los errores de PostgreSQL devuelven un mensaje genérico con 503, sin filas inventadas ni detalles de conexión.
- La página hereda `Cache-Control: no-store` y la política CSP existente. Los indicadores de escritura continúan deshabilitados; no se editó `.env`, ninguna cuenta ni las 60 citas.

## Cómo probar desde la web

Mantener Apache con **solo `public/` como DocumentRoot**, o iniciar desde la raíz:

```powershell
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

El JavaScript ya está publicado en `public/assets/js/consultas-voz.js`. La herramienta `composer assets` también lo copia desde `frontend/assets/js/consultas-voz.js`; no requiere instalar dependencias.

1. Abrir [inicio de sesión local](http://127.0.0.1:8080/login) con una cuenta de demostración existente y su contraseña habitual. No activar usuarios adicionales. Se pueden usar `paciente01`, `profesional01`, `usuario2` y `usuario1` para los cuatro roles.
2. En el panel, pulsar **Abrir consultas por voz**; también aparece **Consultas por voz** en la navegación de gestión. Alternativamente abrir `/consultas-voz`.
3. **Prueba escrita:** introducir «¿Cuáles son mis próximas citas?» y pulsar **Consultar en PostgreSQL**. Repetir con agenda de hoy, Pediatría, horarios y estado de pago. Al ser sábado 7 de noviembre, una agenda vacía de hoy es un resultado válido; no se crean citas para rellenarla.
4. **Prueba de voz:** si el botón está habilitado, pulsar **Usar micrófono**, autorizar el acceso manualmente en el navegador si se solicita y dictar una sola consulta. Revisar la transcripción y pulsar **Consultar en PostgreSQL**. Se puede corregir cualquier palabra antes de enviar.
5. **Prueba de lectura:** con una respuesta visible, marcar la opción de lectura y pulsar **Leer respuesta**. Probar **Detener lectura** y desmarcar la opción. Si no hay síntesis, se informa y permanece el resultado visual.
6. **Permisos:** Paciente no debe obtener citas ajenas; Médico solo ve su agenda y obtiene 403 al pedir pagos. Administrador y Recepcionista ven el ámbito administrativo permitido.
7. **Sin micrófono:** denegar el permiso o utilizar un navegador sin API. La alternativa escrita debe continuar funcionando. Con JavaScript desactivado, el POST HTML sigue devolviendo resultados reales, sin voz ni lectura.
8. **Entradas inválidas:** probar «Cancela mi cita» o «SELECT * FROM usuarios». Debe aparecer 422, sin ejecutar la instrucción. Una fecha de horarios fuera de ventana también se rechaza.

No hay que habilitar escrituras, crear registros ni cambiar `.env` para estas pruebas.

## Navegadores y reconocimiento realmente comprobado

**No se comprobó reconocimiento acústico real en ningún navegador durante esta entrega.** Tampoco se verificó audio audible de síntesis, permiso físico del micrófono ni disponibilidad real de una voz boliviana. No se afirma que se haya probado Chrome, Edge, Safari o Firefox con micrófono.

Se verificó la lógica del cliente con DOM y Web Speech simulados en Node.js: API estándar y prefijada, alternativa sin API, contexto inseguro, errores, transcripción, límite temporal, inicio tardío, detención, lectura opcional y recurso publicado. Esas pruebas no sustituyen una frase dictada y reconocida por un proveedor real. La alternativa escrita y el backend sí se comprobaron mediante HTTP real con PostgreSQL.

Antes de la exposición conviene ejecutar los pasos de voz y lectura anteriores en el mismo equipo y navegador que se usará. Si el reconocimiento falla, la presentación puede continuar íntegramente con texto. La disponibilidad se detecta en tiempo de ejecución; no se promete soporte por el nombre del navegador. Referencias de API consultadas: [SpeechRecognition](https://developer.mozilla.org/en-US/docs/Web/API/SpeechRecognition) y [start()](https://developer.mozilla.org/en-US/docs/Web/API/SpeechRecognition/start).

## Pruebas ejecutadas

```powershell
composer test:voice
node tests/consultas-voz-js.cjs
node --check public/assets/js/consultas-voz.js
pwsh -NoProfile -File tests/http-consultas-voz.ps1
php tests/citas.php
php tests/horarios.php
php tests/pagos.php
php tests/session.php
composer validate --strict
```

Resultados:

- `composer test:voice`: **59** comprobaciones de interpretación, permisos y servicio; **37** del repositorio con PDO simulado. Sin conexión a PostgreSQL.
- JavaScript: **31** comprobaciones con dobles de DOM/Web Speech; sin micrófono ni llamadas a servicios externos. Sintaxis JavaScript correcta; fuente y archivo publicado idénticos.
- HTTP: **109** comprobaciones con las cuatro cuentas activas existentes, sus permisos reales y conexión PostgreSQL de solo lectura. Cubren paneles, formulario nativo sin JavaScript, cinco consultas, ámbitos de citas, catálogo real, franjas disponibles, datos inexistentes, pagos denegados al Médico, CSRF 419, entradas 422, método 405, sesión obligatoria y recurso público.
- Regresiones esenciales: **65** P5-A, **75** P4, **30** P9 y **8** sesiones, con datos simulados/temporales. No se repitió la suite completa antigua; se añadió P10 a `composer test` para futuras ejecuciones y se ejecutó el comando específico `composer test:voice` en esta entrega.
- Composer estricto y `git diff --check`: correctos. Lint: **165 archivos PHP** de `backend/`, `frontend/`, `public/`, `tests/` y `tools/` sin errores.
- Integridad PostgreSQL y `.env`: **21 tablas**, auditoría y **19 secuencias** (`last_value` e `is_called`) sin cambios respecto de la huella privada anterior a P10. Se conservan **60 citas, 90 horarios, 0 bloqueos y 30 pacientes**. Las sesiones temporales HTTP se eliminaron; no se activaron usuarios.

Durante la prueba se corrigió una expectativa equivocada sobre la existencia de Pediatría y se incorporó el JavaScript a la lista cerrada del router de desarrollo. No se modificaron datos para hacer pasar las pruebas.

Pendiente: prueba física de voz/síntesis por navegador, permisos reales denegados por el sistema, servicios de reconocimiento sin conexión y revisión visual interactiva del formulario en navegadores de escritorio/móviles. No se ejecutaron transacciones SQL de escritura ni pruebas persistentes.

## Archivos de esta implementación P10

Nuevos:

- `backend/app/Controllers/ConsultaVozController.php`
- `backend/app/Middleware/VoiceAccess.php`
- `backend/app/Services/VoiceIntent.php`
- `backend/app/Services/VoiceQueryService.php`
- `backend/app/Repositories/VoiceQueryStore.php`
- `backend/app/Repositories/VoiceQueryRepository.php`
- `backend/app/Support/VoiceQueryFactory.php`
- `frontend/views/consultas-voz/index.php`
- `frontend/assets/js/consultas-voz.js` y su copia publicada `public/assets/js/consultas-voz.js`
- `tests/consultas-voz.php`, `tests/consultas-voz-repository.php`, `tests/consultas-voz-js.cjs`, `tests/http-consultas-voz.ps1`
- `docs/consultas-voz.md`

Modificados para integrar P10:

- `backend/routes/web.php`: dos rutas protegidas.
- `frontend/views/dashboard.php` y `frontend/views/layouts/management-header.php`: accesos sujetos a permiso.
- `tools/publish-assets.php` y `tools/dev-router.php`: publicación y entrega del JavaScript.
- `tests/support/patient-http-fixtures.php`: códigos de citas autorizadas para comparar ámbitos en HTTP, sin cambiar las cuentas.
- `composer.json`: pruebas P10 y comando específico, sin nuevas dependencias.

Los cambios previos de P9 y sus archivos de trabajo se conservaron. En P10 no se modificaron `composer.lock`, `.env.example`, servicios/controladores/vistas de P9 ni el repositorio de pagos; se reutiliza su lectura existente.
