# Migración selectiva de registros ficticios a Render

## Estado y autorización

El usuario confirmó que TODOS los registros locales son ficticios y autorizó
PREPARAR la migración. No se ejecutó la importación. No se hizo commit/push.
La aplicación pública existente se conserva; no se crea otro servicio.
No ejecutar `render.sql`, `seed-demo.php` ni `continue-render-seed.ps1`.

## Diagnóstico observado el 7 de noviembre de 2026

Conexión comprobada a `clinica_vida_salud` por loopback, mediante sesión y
transacción REPEATABLE READ READ ONLY. Se inspeccionaron columnas, identidades,
restricciones y funciones de triggers reales; no se ejecutaron sus definiciones.

| Entidad | Origen confirmado | Añadir al baseline demo | Total previsto |
|---|---:|---:|---:|
| Especialidades | 9 | 8 | 10 |
| Médicos | 18 | 18 | 20 |
| Pacientes | 30 | 30 | 32 |
| Médico-especialidad | 18 | 18 | 20 |
| Horarios | 90 | 90 | 94 |
| Citas | 60 | 60 | 60 |

Los totales previstos parten de los datos del seed conocido: 2 especialidades,
2 médicos, 2 pacientes, 2 relaciones, 4 horarios y ninguna cita. NO constituyen
una lectura actual de Render. El usuario confirmó su inicialización y cuatro
cuentas; falta la simulación contra su estado real con URL privada.

Las 60 citas locales están programadas entre el 9 y el 12 de noviembre de 2026;
las 60 eran futuras al inspeccionar. No se encontraron pacientes/asociaciones
huérfanos en citas ni bloqueos locales. Los 18 médicos y 30 pacientes tienen
cuentas locales vinculadas; esas vinculaciones NO se trasladan.

## Conflictos y decisiones del plan propuesto

- Pediatría existe en el seed Render a Bs 150.00; en local está a Bs 30.00.
  Se propone reutilizar la especialidad por nombre normalizado y CONSERVAR
  la tarifa y descripción de Render. El plan avisa la diferencia. No insertar
  otra Pediatría ni actualizar la existente. La simulación real confirmará
  su valor actual y cualquier otra coincidencia.
- Las claves numéricas de ambas bases se superponen. Se omiten las PK al
  insertar; PostgreSQL genera las nuevas. Se remapean las FK en memoria.
- Los médicos nuevos usan código `UNI26-M-ID` y registro `UNI26-R-ID`;
  pacientes, código `UNI26-P-ID` y documento ficticio `UNI26-D-ID`;
  citas, código `UNI26-C-ID`. No se copian los códigos/documentos originales.
  El espacio UNI26 se considera reservado a este origen y lote; no renumerar
  ni reutilizarlo para otra base. Si ya existe con contenido diferente, abortar.
- Todas las fichas importadas quedan con `id_usuario=NULL`. Los perfiles de
  `demo_medico` y `demo_paciente` siguen vinculados a SUS fichas existentes.
  Por tanto, esos dos roles NO verán automáticamente las 60 citas como propias.
  Administración/recepción sí tendrán los registros en sus ámbitos actuales.
  Asignar citas nuevas a los perfiles demo sería un paso separado a acordar;
  este script no reasigna fichas, citas existentes ni permisos.
- Se copian nombres ficticios, estado, nacimiento/sexo ficticios, especialidades,
  tarifas nuevas, horarios y datos operativos de citas. Se omiten teléfonos,
  correos, direcciones, contactos de emergencia, antecedentes, sangre, alergias,
  motivos clínicos/cancelación y timestamps de origen. Los contactos nuevos
  quedan NULL. No se envían correos ni se crean pagos/notificaciones.
- No se consultan hashes locales ni se migran usuarios, roles, permisos,
  sesiones, tokens, recuperación, auditoría histórica, notas, recetas o pagos.
  Los triggers de auditoría del destino permanecen activos y crearán sus
  propios registros transaccionales con actor NULL.
- Se conservan fechas, horas, estados y contador de reprogramaciones. No se
  desplazan citas automáticamente. Las futuras deben seguir dentro de los
  diez días hábiles, con médico activo, franja/duración válidas, sin bloqueos,
  reservas pendientes ni cruces de médico/paciente. Si la fecha de ejecución
  hace inválida una cita, se aborta el lote completo y se requiere otro plan.

## Herramientas preparadas

- `tools/inspect-demo-source.php`: diagnóstico local de solo lectura, sin
  contactos, credenciales ni datos clínicos en la salida.
- `tools/demo-migration-plan.php`: planificador puro, solo arrays en memoria.
- `tools/migrate-demo-records.php`: simulación por defecto; modo de aplicación
  separado, exige destino exacto, frase de confirmación y SHA256 del plan REAL.
- `tools/migrate-demo-records.ps1`: entrada oculta de URL y limpieza del entorno.
- `tests/demo-migration-plan.php`: 25 comprobaciones puramente en memoria.

La simulación NO hace INSERT ni ROLLBACK de escrituras de prueba, ni consume
secuencias. La simulación real abre Render en READ ONLY, exige libpq >=14,
PostgreSQL 18/TLS, 21 tablas, esquema compatible con el origen y exactamente
las cuatro cuentas demo conocidas. No utiliza la base de integración.

La aplicación futura vuelve a leer el origen sin escribirlo, bloquea brevemente
las tablas de destino implicadas (timeout 5 segundos), recalcula el plan y
comprueba su SHA256. Si hubo cambios, aborta antes de insertar. Todo el lote
se inserta en UNA transacción SERIALIZABLE. No usa UPDATE, DELETE, DDL,
restauraciones ni desactiva triggers/restricciones.

Se verifica antes de COMMIT: conteos, FK, mapeo, idempotencia y huellas de TODOS
los registros anteriores en las seis tablas y en usuarios. Las huellas de
usuarios se calculan en PostgreSQL; no se recuperan contraseñas ni hashes.
Cualquier fallo revierte las filas nuevas. Una repetición compatible reutiliza
las filas UNI26 y no duplica el lote. Las funciones existentes de PostgreSQL
constituyen la comprobación definitiva en la ejecución real.

### Secuencias

No se insertan PK locales ni se llama a `setval`: los DEFAULT/IDENTITY generan
PK nuevas y avanzan las secuencias de forma natural, incluida auditoría.
Antes de escribir se exige que el próximo valor de cada secuencia sea superior
a su máximo actual. Si alguna está atrasada, el script bloquea: se prepararía
una reparación puntual tras revisar el resultado, nunca un reajuste ciego.
Un fallo transaccional puede dejar huecos de secuencia; no deja filas parciales.
No se promete continuidad de numeración ni se retroceden secuencias.

## Comandos PowerShell 5.1

Desde `D:\Proyectos\clinica_vida_salud`.

### 1. Simulación local con baseline conocido (ya ejecutada)

```powershell
.\tools\migrate-demo-records.ps1 `
  -Php 'C:\xampp\php\php.exe' `
  -SimulateSeedBaseline
```

Este modo no conecta a Render y no sirve para autorizar una importación real.

### 2. Simulación REAL contra Render, sin escrituras (pendiente)

Usar el PHP independiente con ambos controladores y libpq >=14 que completó
la inicialización. Sustituir la ruta si está instalado en otra ubicación.
El host debe copiarse del panel PostgreSQL, sin usuario ni contraseña.

```powershell
.\tools\migrate-demo-records.ps1 `
  -Php 'D:\Herramientas\php83-render\php.exe' `
  -ExpectedHost 'dpg-REEMPLAZAR.REGION-postgres.render.com'
```

La External Database URL ROTADA se introduce SOLO en la solicitud oculta.
No pasarla como argumento, no pegarla en chats, no guardar en `.env`, ni usar
transcripción/depurador que capture variables. Las variables nuevas se eliminan
y las preexistentes se restauran en `finally`.

Revisar el JSON: `modo=SIMULACION_RENDER_SOLO_LECTURA`, conteos, advertencias y
`plan_sha256`. El informe no imprime las filas ni secretos. Si el esquema,
las secuencias o los registros difieren, detenerse; no forzar excepciones.

### 3. Aplicación: SOLO después de revisar y aprobar la simulación real

**NO EJECUTADA. No ejecutar todavía.**

```powershell
.\tools\migrate-demo-records.ps1 `
  -Php 'D:\Herramientas\php83-render\php.exe' `
  -ExpectedHost 'dpg-REEMPLAZAR.REGION-postgres.render.com' `
  -Apply `
  -PlanHash 'SHA256-DE-LA-SIMULACION-REAL-REVISADA'
```

Además de la URL oculta, pide escribir exactamente:
`IMPORTAR_FICTICIOS_EN_clinica-vida-salud-db`.
El modo simulado con baseline no permite `-Apply`.

Tras el COMMIT, repetir el comando del paso 2: debe mostrar cero inserciones
pendientes y los mismos conteos finales. Verificar en la aplicación los cuatro
logins y los listados autorizados. No activar flags por este procedimiento.

## Evidencia y límites

- Diagnóstico local y simulación con baseline: ejecutados correctamente.
- composer test: pasó, sin PostgreSQL persistente.
- Lint PHP de 273 archivos: correcto. Parser Windows PowerShell 5.1: correcto.
- git diff --check: correcto; archivos nuevos revisados sin secretos ni espacios finales.
- 25 comprobaciones del plan en memoria: pasaron, incluidos FK, conservación
  de tarifa, idempotencia, fechas, duración, bloqueos, reservas y solapamientos.
- Simulación REAL contra Render: pendiente de URL introducida privadamente.
- Importación real: NO ejecutada. Atomicidad/triggers del destino se verificarán
  en la ejecución aprobada; no se hicieron ensayos persistentes en ninguna BD.
- No se modificó `.env`, no se escribió en la BD local y no hubo commit/push.

## Cierre de migración: comparación semántica y errores seguros

PHP independiente comprobado: D:\Herramientas\php83-render\php.exe,
8.3.36 NTS x64, libpq 14.24 en pdo_pgsql y pgsql. Le faltaba mbstring.
El wrapper ahora carga exclusivamente su propia ext/php_mbstring.dll para ese
proceso y verifica el resultado; no modifica php.ini, XAMPP ni .env.

PowerShell captura stdout/stderr mediante ProcessStartInfo con UTF-8 y ambas
lecturas concurrentes; no mezcla stderr con ErrorRecord de PowerShell 5.1.
Las etapas y motivos usan códigos ASCII cerrados. Solo refleja el protocolo
MIGRATION_ERROR validado, nunca mensajes nativos, URL, usuario o contraseña.
El origen usa DB_* locales, de modo independiente de DATABASE_URL.

El bloqueo posterior informado fue SCHEMA_CONSTRAINTS_DIFFER. Los seis CHECK
de estado se comparan por tabla, nombre, columna estado, tipo de restricción,
banderas y valores exactos permitidos, preservando mayúsculas de los literales.
Se aceptan únicamente casts text/character varying, paréntesis y orden/dobles
valores de pertenencia semánticamente equivalentes. Los otros CHECK, PK, FK,
UNIQUE y exclusiones conservan comparación estricta. No se ignoran CHECKs.
17 pruebas cubren equivalencia y diferencias reales, sin modificar PostgreSQL.

Simulación local con baseline conocida: correcta con 9/18/30/18/90/60 filas
locales. Proyección: 10 especialidades, 20 médicos, 32 pacientes, 20 relaciones,
94 horarios y 60 citas; conserva Pediatría Render a Bs 150 frente a Bs 30 local.
El SHA256 baseline NO autoriza una migración real.

Falta una nueva simulación REAL con la URL rotada introducida de forma privada.
No se ejecutó -Apply, no se ajustaron secuencias ni se importaron datos. La
política de ejecución del Windows PowerShell 5.1 lanzado desde esta sesión
bloqueó el script completo; no se cambió esa política. El parser 5.1 pasó y el
wrapper completo se verificó en PowerShell 7 con READ ONLY local.
