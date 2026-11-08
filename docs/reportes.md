# P6 — Reportes administrativos

Entrega de lectura para el 7 de noviembre de 2026. El módulo reutiliza PDO_PGSQL, la sesión y el middleware existentes. No ejecuta INSERT, UPDATE, DELETE, DDL, migraciones, seeds ni activa indicadores de escritura.

## Acceso y permisos reales

El catálogo real de PostgreSQL contiene `generar_reportes` y `exportar_reportes`. `/reportes` exige `generar_reportes`; `/reportes/exportar` exige `exportar_reportes`. Son controles independientes en la ruta y el servicio: la consulta no depende de `consultar_historial_citas`, y la exportación no queda habilitada por ese permiso.

## Rutas

- `/reportes`: panel HTML con filtros por fecha inicial/final, médico, especialidad y estado.
- `/reportes/exportar?formato=csv`: descarga CSV con los filtros de la URL.
- `/reportes/exportar?formato=pdf`: descarga PDF mediante la dependencia existente `dompdf/dompdf`.

El detalle de citas incluye únicamente código, fecha, horario, paciente, médico, especialidad y estado. No consulta pagos, motivos clínicos, documentos ni credenciales.

## Reportes incluidos

El panel presenta detalle de citas, agrupación por médico, agrupación por especialidad, asistencia y resumen administrativo. Los estados son los comprobados en P5: `programada`, `confirmada`, `atendida`, `cancelada` e `inasistencia`. Los porcentajes de asistencia muestran `Sin datos` cuando el filtro no tiene citas; no se fabrican atenciones o inasistencias.

El resumen de pacientes, médicos y especialidades es global; el total de citas corresponde al filtro aplicado. Las agrupaciones conservan médicos/especialidades sin citas para que un cero sea visible y no se confunda con un dato inexistente.

La ampliación añade:

- agenda diaria por médico y agenda semanal agrupada desde el lunes, con citas por estado, horas programadas, horas ocupadas, horas atendidas y espacios libres;
- carga por médico: horas semanales recurrentes, horas del periodo filtrado, horas ocupadas por citas no canceladas, horas efectivamente atendidas (estado `atendida`) y horas disponibles;
- carga por especialidad. Como `medico_especialidad` no define una especialidad principal, las horas semanales de cada médico se reparten en partes iguales entre sus asociaciones. El total por especialidad no duplica la carga total de un médico;
- filtros de fecha, médico, especialidad y estado en el detalle y en los indicadores de citas;
- bloqueos de `bloqueos_horario` como indisponibilidad, sin etiquetarlos como ausencias;
- conflictos de horarios heredados y solapamientos de citas activas, calculados con `DisponibilidadService` y los intervalos `[inicio, fin)` de P4/P5.

El cálculo usa `America/La_Paz`. Las franjas libres se obtienen mediante `DisponibilidadService::day`, no mediante una segunda implementación de disponibilidad. Los datos expuestos en estos cuadros no incluyen pacientes ni motivos clínicos.

## Ausencias y limitación de datos

El esquema inspeccionado contiene horarios, bloqueos y estados de citas, pero no una tabla o registro de asistencia laboral de médicos. Por eso el panel muestra bloqueos/indisponibilidad y una advertencia explícita: vacaciones, permisos u otros bloqueos no se presentan automáticamente como ausencias. `inasistencia` de `citas` corresponde a la cita y no se interpreta como ausencia médica.

## Exportaciones y protección

CSV incluye el detalle filtrado, la carga horaria y los bloqueos del periodo. PDF incluye el detalle y la tabla de carga horaria, respetando los mismos filtros y el permiso `exportar_reportes`. XLSX no está disponible: `composer.json` no contiene PhpSpreadsheet ni otra dependencia compatible. No se instaló una dependencia durante esta entrega para evitar modificar el entorno y el módulo ofrece CSV y PDF verificables.

El proyecto conserva `public/` como única raíz web; los reportes son exclusivamente consultas preparadas de lectura. No se modificó `.env`, ninguna base, cuentas, permisos ni `var/integration/`.

## Validación

`tests/reportes.php` cubre filtros, permisos, renderizado y CSV/PDF con un repositorio simulado. La comprobación real PostgreSQL de P4/P5 usa `Connection::readOnly`, transacción de lectura y rollback; la ampliación debe validarse en la misma base protegida sin habilitar escrituras. `composer test` y lint PHP son las comprobaciones de regresión del módulo.

## Revisión visual y de cálculos — 7 de noviembre de 2026

### Lo implementado y su SQL

El repositorio `backend/app/Repositories/ReportRepository.php` es la fuente de
P6. `ReportFactory` crea una conexión `Connection::readOnly`. No se añadieron
consultas de escritura ni se modificaron rutas, permisos o autenticación.

| Resultado | Consulta / cálculo efectivo |
|---|---|
| Detalle de citas | CTE `filtered` sobre `public.citas`, con parámetros `fecha_inicio`, `fecha_fin`, `medico`, `especialidad`, `estado`; JOIN a pacientes, médicos y especialidades. |
| Totales por médico y estado | LEFT JOIN de médicos al CTE; `count(f.id_cita)` y `FILTER (WHERE f.estado=...)`, agrupados por médico. |
| Citas por especialidad | LEFT JOIN de especialidades, asociaciones y CTE; `count(DISTINCT f.id_cita)`, `count(DISTINCT me.id_medico)`, GROUP BY especialidad. |
| Atención, inasistencia y cancelación | `count(*)`, `count(*) FILTER (WHERE estado='atendida'/'inasistencia'/'cancelada') FROM filtered`. Los porcentajes usan TODAS las citas filtradas como denominador. |
| Resumen | Tres `SELECT count(*)` globales de pacientes, médicos y especialidades; citas = número de filas del detalle filtrado. Se etiqueta catálogo global. |
| Horarios/capacidad | SELECT de horarios por IDs de médicos; se unen intervalos activos por médico y día en PHP para no duplicar solapamientos. Semanal recurrente y periodo fechado. |
| Ocupadas/atendidas | SELECT de citas por médico/rango; unión de intervalos no cancelados y del subconjunto `atendida`, respetando estado/especialidad seleccionados. Las atendidas están incluidas en ocupadas. |
| Disponibles | SELECT de agenda completa y bloqueos en el periodo; `DisponibilidadService::day` reutiliza P4/P5; se suman solo franjas con estado `disponible`. No se quitan ocupaciones por filtrar el estado o la especialidad. |
| Carga por especialidad | Horas ocupadas/atendidas de citas de esa especialidad. Capacidad programada y libre repartida por igual entre asociaciones médicas: ESTIMADA, no un horario reservado a la especialidad. Puede haber diferencias de redondeo a centésimas. |
| Diaria/semanal | Agrupación PHP por médico/fecha y médico/lunes de la semana, con estados, horas y espacios libres. |
| Bloqueos/conflictos | SELECT `bloqueos_horario` por médico/rango, franjas conflictivas de P4/P5 y comparación de intervalos de citas no canceladas. |

No hay estadísticas de asistencia laboral de médicos. `inasistencia` corresponde
al paciente en una cita. Tampoco hay agregados de pagos, ingresos o asistencia
laboral en este P6: los pagos académicos están en su módulo separado. XLSX
permanece sin implementar; CSV y PDF sí están implementados.

### Correcciones comprobadas

1. El JOIN de especialidades multiplicaba las citas por el número de médicos
   asociados: `count(DISTINCT f.id_cita)` evita esa duplicación.
2. La carga por médico ignoraba estado/especialidad y la carga por especialidad
   ignoraba estado: ahora se aplican esos filtros a la ocupación/atención.
3. Se añadieron horas del periodo, ocupadas, atendidas y libres por especialidad,
   manteniendo explícita la estimación de capacidad y sin duplicar el horario
   completo del mismo médico en cada especialidad.
4. Los intervalos superpuestos heredados ya no inflan las horas programadas.
5. Un filtro con solo fecha final ya no genera un periodo invertido respecto a
   hoy: el cálculo de carga usa ese día.
6. Las cantidades de agenda diaria/semanal se etiquetan registros médico/día
   y médico/semana; no se confunden con días o semanas únicos.

### Gráficos y cero registros

Antes había tablas y tarjetas, sin gráficos. La vista ahora incorpora barras
SVG servidas por PHP para estados, horas por médico y horas por especialidad.
Todos los valores proceden del reporte consultado, sin JavaScript, CDN de
gráficos, aleatoriedad o cifras promocionales. Las etiquetas están escapadas;
las cifras se muestran como texto accesible. Con cero registros no se divide
por cero ni se muestra un porcentaje inventado; aparece `Sin datos`.
Se conservan todas las tablas y exportaciones. CSV/PDF incluyen la nueva
carga por especialidad y explican los conceptos de horas.

Sin fechas, el detalle de citas sigue abarcando todos los resultados mientras
la carga horaria usa HOY. Con un único extremo temporal la carga usa ese día.
Con ambos extremos usa el rango completo. Los cuadros explican ese periodo.
Las franjas futuras libres del periodo no equivalen a citas reservables fuera
de la ventana de diez días hábiles, que se sigue comprobando al reservar.

### Roles y alcance conservados

Las rutas permiten Administrador, Recepcionista, Medico y Paciente, siempre con
`generar_reportes` para consultar o `exportar_reportes` para exportar. El servicio
vuelve a comprobar cada permiso, de forma independiente. Un rol sin el permiso
no basta para acceder. La asignación existente del seed incluye ambos permisos
en los cuatro roles.

**Alcance existente:** P6 no aplica un filtro de propiedad por el usuario médico
o paciente; es un reporte global para quien tenga esos permisos. Esta revisión
NO lo convierte en un reporte personal ni cambia permisos. Los gráficos usan
el mismo alcance. Cambiar ese alcance requeriría una decisión separada.

### Evidencia ejecutada

- 22 pruebas P6: filtros, autorización independiente, render, PDF/CSV, gráficos
  vacíos, horas/porcentajes y escape de etiquetas.
- 16 regresiones del repositorio con PDO simulado: horas, unión de intervalos,
  filtros, reparto de capacidad, bloques, inasistencias y cero citas.
- 400 comprobaciones con PostgreSQL LOCAL en REPEATABLE READ READ ONLY:
  contraste con SQL independiente de conteos, citas por especialidad, horas
  programadas/ocupadas/atendidas/disponibles; siete escenarios de filtros,
  incluido periodo vacío; generación real de CSV y PDF por Dompdf.
- No se ejecutó la migración pendiente ni se escribió en local o Render.

### Pendiente manual / producción

Se comprobó la generación por Dompdf y la cabecera `%PDF-`; falta revisar
visualmente documentos extensos y descargas desde una sesión del navegador.
No se autenticó en Render ni se comprobó su base. Falta revisar los gráficos
dentro de una sesión autenticada, su vista móvil y los cuatro roles en el
servicio público cuando se autorice publicar. No se eludió autenticación para
hacer una vista previa.

## Versión final de Reportes

Chart.js 4.5.1 UMD se sirve localmente con licencia MIT, sin CDN de scripts ni
cambios de CSP. Los tres gráficos usan exactamente las series filtradas que
alimentan tablas/barras SVG, que se conservan como alternativa accesible.
Se añadió sidebar interno con categorías, tarjetas superiores sticky, pacientes
de las citas filtradas y listado de cancelaciones/inasistencias. Pagos enlaza
solo al módulo permitido por PaymentAccess; no se inventan estadísticas de pagos.

Se corrigió el alcance: médicos solo consultan/exportan sus citas y su carga;
pacientes solo sus citas/resumen, sin bloqueos ni carga de otros médicos.
Administración y recepción conservan el ámbito administrativo autorizado.
El servidor impone user_id desde la identidad, ignorando _scope del navegador.
408 comprobaciones READ ONLY locales incluyen privacidad por rol y exportaciones.
25 pruebas P6 de servicio/presentación y 16 regresiones de cálculo pasaron.
