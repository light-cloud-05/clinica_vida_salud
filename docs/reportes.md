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
