# P9 — Pagos: corrección e integración del 7 de noviembre de 2026

## Alcance y protección de la base

Se conservaron los controladores, servicios, repositorios, rutas y vistas existentes y se corrigieron sus flujos. Los requisitos RF26–RF28/CU24–CU26 se consultaron en los DOCX de `docs/requisitos`; también se revisó `docs/reservas.md`. Se contrastaron por SELECT las columnas, restricciones, precios y la firma real de `fn_vencimiento_pago_efectivo(timestamptz,date,time)`.

P5-B registra una cita `programada`; no crea una reserva provisional mientras su guardado está bloqueado. P9 permite seleccionar una de esas citas existentes, dentro del ámbito del usuario, y realiza solamente un ensayo local. PostgreSQL se consulta mediante `Connection::readOnly`. No se invoca el repositorio de escritura desde estas rutas. No se habilitaron indicadores de escritura, no se editaron `.env`, usuarios ni citas, no se ejecutaron migraciones, seeds ni pruebas SQL persistentes. P10 queda fuera del alcance.

## Hallazgos comprobados y correcciones

1. El formulario mostraba efectivo al Paciente mediante una opción añadida, pero el servicio exigía permiso de caja para iniciarlo. Ahora el Paciente autorizado puede elegirlo y queda **pendiente**; solo una **Recepcionista** con `registrar_pago_efectivo` puede confirmar o rechazar el cobro ficticio. Administrador tiene consulta, no caja.
2. Se retiraron los campos manuales de importe, médico, especialidad, fecha y hora. Se envía solamente el identificador de la cita, método, intención, CSRF y reloj opcional. `PaymentRepository::context()` resuelve cita, precio de `especialidades.precio_consulta`, duración real, titular y reserva provisional si existe. Los valores adicionales falsificados del navegador no determinan el pago. Antes de confirmar se vuelve a consultar y se rechazan citas canceladas, turnos o importes modificados y pagos persistentes activos.
3. `pacientes.id_usuario` se compara con el usuario autenticado; `citas.id_paciente` se conserva como identificador del paciente. Por ejemplo, usuario 22 y paciente 1 son valores distintos en las pruebas; nunca se asigna el usuario como paciente. Una cita ajena se deniega en servidor.
4. El QR es SVG generado con **endroid/qr-code** instalado. Contiene solo referencia de ensayo, importe, moneda y `DEMOSTRACION_SIN_VALOR_BANCARIO`; no contiene cuentas ni datos bancarios. Visualizarlo o escanearlo no confirma un pago. La confirmación local requiere POST autenticado y CSRF.
5. PayPal local y PayPal externo se distinguen. El local registra proveedor `paypal_simulacion_local`, referencias `SIM-` y comprobante académico. El flujo externo dispone de crear orden, redirigir a la aprobación Sandbox, retorno autenticado, POST de captura y verificación de orden, referencia, estado, moneda e importe. Una orden externa no puede aprobarse ni rechazarse con botones simulados. El retorno GET **no confirma** pagos.
6. PHP CLI 8.1.25 dispone de `bcmath` y `curl`; ambas extensiones se declararon en Composer. Se sincronizó `composer.lock` sin instalar ni cambiar versiones de paquetes. `composer validate --strict` y `composer check-platform-reqs` pasan.
7. El ensayo usa `pendiente → confirmado`, `pendiente → rechazado` o `pendiente → vencido`. Un rechazado no vuelve a aprobarse; debe iniciarse otro intento. Al vencer quedan reserva `expirada` y cita **local** `cancelada`. Un confirmado no puede rechazarse y no genera comprobantes duplicados. El esquema real de `pagos` **no admite `vencido`**: ese estado es exclusivamente del simulador; no se añadió columna, estado ni migración. El repositorio persistente también rechaza confirmar un pago rechazado y permanece bloqueado.
8. El archivo compartido está en `var/p9/payment-demo.json`, fuera de `public/`; `/var/` y `.env` están excluidos de Git. Escrituras de archivo serializadas con `flock`, reemplazo temporal y límite de 500 ensayos. Se conservaron los ensayos anteriores del usuario: no se borraron ni se migraron. Los antiguos sin vínculo P5-B no pueden confirmarse; deben iniciarse nuevamente desde una cita.
9. Efectivo se puede **seleccionar** fuera de caja si existe una oportunidad válida antes del plazo. El **cobro** solo se confirma de lunes a viernes, desde 08:00 inclusive hasta antes de 18:00, en `America/La_Paz`. El vencimiento parte del registro original de la cita: mínimo entre registro +48 horas y cita −3 horas, ajustado a la última oportunidad de caja, conforme a la función PostgreSQL inspeccionada. No se reinician 48 horas al elegir el método. Los **30 minutos** corresponden exclusivamente a pagos online **simulados**, con tope en la cita o en la reserva provisional existente. Sandbox externo exige el plazo vigente de una reserva provisional de PostgreSQL, sin inventar un plazo de 30 minutos.
10. Se comprobaron sesión, rol, permiso, propiedad, CSRF, idempotencia por intención y protección frente a otro ensayo activo para la misma cita. Las acciones de caja se ocultan al paciente. La descarga de comprobante académico PDF requiere permiso, propiedad y pago confirmado. No acredita dinero real ni confirma una cita en PostgreSQL.

## Cómo probar en el navegador

Usar Apache con **solo `public/` como DocumentRoot**, o desde la raíz del proyecto:

```powershell
$env:PAYMENT_DEMO_STORE_NAME='entrega-7nov'
php -S 127.0.0.1:8080 -t public tools/dev-router.php
```

`PAYMENT_DEMO_STORE_NAME` es opcional: permite un archivo de ensayo separado dentro de `var/p9/` sin borrar el trabajo previo. Solo admite letras, números y guiones. Al finalizar el servidor, retirar únicamente esa variable del proceso con `Remove-Item Env:PAYMENT_DEMO_STORE_NAME`. No editar `.env` ni habilitar escrituras.

Abrir [inicio de sesión local](http://127.0.0.1:8080/login). Usar las contraseñas de demostración ya existentes; esta entrega no las cambia ni las publica. Las cuatro cuentas verificadas son `paciente01` (Paciente), `usuario2` (Recepcionista), `usuario1` (Administrador) y `profesional01` (Médico). Las pruebas HTTP utilizaron sesiones privadas temporales de esas identidades activas y verificaron sus permisos; no probaron contraseñas introducidas en el formulario ni activaron usuarios adicionales.

### Efectivo ficticio

1. Entrar como `paciente01` en `/mis-pagos/nuevo`; elegir una cita propia y **Efectivo**. El selector muestra código, fecha de cita, precio de referencia y fecha original de registro. El servidor vuelve a obtener el importe.
2. Si el plazo original ya venció, usar un reloj de ensayo inmediatamente posterior al registro mostrado, antes del vencimiento y de la cita. Se puede elegir efectivo fuera del horario de caja siempre que exista una oportunidad futura. No sirve elegir una fecha que reinicie las 48 horas.
3. Crear el ensayo y entrar en **Ver ensayos**. Debe aparecer pendiente y sin botón de confirmación para el paciente.
4. Entrar como `usuario2` en `/pagos`. Para un ensayo con reloj, avanzar ese reloj hasta una oportunidad de caja anterior al vencimiento y pulsar **Confirmar cobro ficticio en caja**. Fuera del horario se rechaza. Avanzar hasta el vencimiento marca el ensayo vencido. El reloj nunca retrocede ni cambia la hora real del servidor.
5. Descargar el comprobante académico PDF de un ensayo confirmado. La cita en PostgreSQL conserva su estado original.

### QR de demostración

Entrar como `paciente01`, elegir una cita sin ensayo activo y **QR de demostración**. Puede usarse un reloj de ensayo previo a la cita para revisar datos históricos. Crear el ensayo y pulsar **Ver ensayos**: aparece el QR visual, aún pendiente. Pulsar **Confirmar simulación local** o **Rechazar simulación**. Recepción puede avanzar el reloj más allá de 30 minutos para comprobar el vencimiento. No realizar transferencias ni interpretar el QR como una cuenta bancaria.

### PayPal local

Elegir **PayPal Sandbox / simulación local**, crear el ensayo y, desde el listado, pulsar **Confirmar simulación local** o rechazarlo. No se abre PayPal ni se envía una solicitud al proveedor. El evento se identifica como `paypal_simulacion_local`; el PDF declara que no acredita una transacción auténtica.

No se pueden crear dos ensayos activos para la misma cita. Para ensayar otra modalidad, usar otra cita propia, rechazar el intento pendiente o dejarlo vencer. Un confirmado local no se revierte. Para una nueva demostración independiente se puede reiniciar el servidor con otro `PAYMENT_DEMO_STORE_NAME`, conservando los archivos anteriores. Esto no crea ni cancela citas reales.

### PayPal Sandbox externo: preparado y bloqueado por defecto

Se mantiene `PAYMENT_SANDBOX_ENABLED=false` por defecto; no se modificó el entorno actual. Requiere credenciales Sandbox, conversión BOB/USD autorizada, URL de retorno y **una reserva provisional real vigente** ya existente. Las variables leídas son `PAYPAL_SANDBOX_CLIENT_ID`, `PAYPAL_SANDBOX_CLIENT_SECRET`, `PAYPAL_SANDBOX_BOB_PER_USD`, `PAYPAL_SANDBOX_RETURN_BASE`. Nunca se muestran sus valores.

Solo después de autorizar y preparar un entorno aislado con esa reserva, configurar variables del proceso y `PAYMENT_SANDBOX_ENABLED=true`, manteniendo las escrituras PostgreSQL bloqueadas. Desde un ensayo PayPal sin reloj, pulsar **Ir a PayPal Sandbox externo**; aprobar con una cuenta Sandbox, regresar a `/mis-pagos/sandbox/retorno` y pulsar **Verificar y capturar en Sandbox**. Cancelar en PayPal regresa al listado y deja el intento sin confirmar hasta vencer. Una orden rechazada o no aprobada no supera la captura. Solo un resultado verificado se guarda en el archivo local, nunca en PostgreSQL. No hay endpoint Live ni dinero real.

**No se ejecutó una transacción externa PayPal en esta entrega.** La prueba de integración del cliente usa un transporte ficticio identificado como prueba; no demuestra conectividad, credenciales ni captura real. Tampoco se habilitaron escrituras para generar la reserva provisional que falta en P5-B. Referencia consultada: [integración oficial de PayPal Checkout](https://developer.paypal.com/studio/checkout/standard/integrate).

## Pruebas ejecutadas y repetición

Primero se ejecutaron `php tests/pagos.php`, `php tests/reservas.php` y `php tests/reservas-repository.php`. La prueba P9 inicial fallaba porque exigía que no existiera un archivo local previo; ahora comprueba que su huella no cambie. P5-B pasó desde el inicio.

```powershell
php tests/pagos.php
php tests/pagos-integration.php
php tests/pagos-sandbox.php
composer test
composer validate --strict
composer check-platform-reqs
pwsh -NoProfile -File tests/http-pagos.ps1
pwsh -NoProfile -File tests/http-reservas.ps1
```

- P9: 30 comprobaciones en archivo temporal; integración P9/P5-B: 10 con PDO simulado; Sandbox: 13 con transporte ficticio, sin solicitudes al proveedor. Incluidas en `composer test`, junto con las regresiones P2–P5.
- HTTP P9: 38 comprobaciones de las cuatro cuentas, tres modalidades, importe falsificado ignorado, titular real, cita ajena, CSRF, duplicados, caja, expiración, QR, PDF y Sandbox externo bloqueado. Archivo de simulación aislado y eliminado al finalizar; no se toca el almacén original.
- HTTP P5-B: 30 comprobaciones, incluidas reserva bloqueada con 423, CSRF y propiedad. Se comprobaron las cuatro cuentas activas existentes sin cambios SQL.
- Lint PHP: 155 archivos correctos de `backend/`, `frontend/`, `public/`, `tests/` y `tools/`; `git diff --check`; exclusión Git de `.env` y del almacén local.
- Integridad por lectura: 21 tablas, auditoría, 19 secuencias (`last_value` e `is_called`) y huella de `.env` sin cambios respecto de la línea base privada de esta corrección; se conservan las **60 citas**. No se restauró ni borró ningún dato.

No se probó commit real de pagos/reservas ni concurrencia real en PostgreSQL. Las simulaciones y pruebas HTTP no sustituyen esa validación futura autorizada. Los datos de demostración y sesiones permanecen fuera de `public/` y de Git.
