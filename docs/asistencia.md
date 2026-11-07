# Control de asistencia y estados de citas

## Alcance

El módulo reutiliza el listado, filtros y detalle de P5-A, además de la
autenticación y los cambios de P5-C. El estado visible se toma de `citas` y
solo utiliza los estados existentes: `programada`, `confirmada`, `atendida`,
`cancelada` e `inasistencia`.

Las transiciones disponibles son:

| Actor | Permiso | Transición |
| --- | --- | --- |
| Paciente, solo su cita | `confirmar_cita_propia` | `programada` → `confirmada` |
| Médico, solo sus citas | `marcar_cita_atendida` | `programada`/`confirmada` → `atendida` |
| Administrador o Recepcionista | `controlar_asistencia` | `programada`/`confirmada` → `inasistencia` |

La confirmación del paciente no significa que la consulta fue atendida. No se
permite marcar una cita como atendida desde una cuenta de paciente, ni cambiar
citas canceladas, atendidas o ya inasistidas. El registro de inasistencia
requiere que hayan transcurrido diez minutos desde la hora de inicio, usando
`America/La_Paz`.

El esquema real no tiene una columna de llegada ni un actor de llegada
independiente. Por eso esta entrega conserva la llegada únicamente mediante
el estado permitido y el historial de estados; no inventa campos ni ejecuta
migraciones.

## Seguridad y persistencia

Los endpoints vuelven a validar rol, permiso, pertenencia de la cita, CSRF,
estado y versión. La escritura usa una transacción, bloqueo `FOR UPDATE`,
revisión de versión bajo bloqueo y `app.usuario_id` para que los triggers
existentes alimenten `historial_cambios_cita` y `registros_auditoria`. Los
errores de carrera o restricciones provocan rollback y un mensaje claro.

Las escrituras están bloqueadas por defecto mediante
`ATTENDANCE_WRITES_ENABLED=false` (también se mantiene sin modificar el
`.env` local). Con el bloqueo activo se pueden consultar los estados,
acciones autorizadas e historial, pero ningún POST cambia una cita. No se
modificaron las 60 citas originales, permisos, triggers, funciones ni tablas.

## Rutas y navegador

Desde el panel de cada rol se abre **Citas médicas** o **Mis citas**:

```text
http://127.0.0.1:8080/citas
http://127.0.0.1:8080/mis-citas
```

En el detalle aparecen las acciones autorizadas:

- `POST /mis-citas/confirmar` para el paciente.
- `POST /citas/atender` para el médico propietario de la agenda.
- `POST /citas/asistencia` para inasistencia de Administración o Recepción.

El historial se muestra en el detalle de la cita. El listado mantiene los
filtros por médico, fecha y estado de P5-A.

## Verificación

Se añadieron pruebas en memoria para políticas, transiciones, permisos, CSRF,
rutas y bloqueo de escrituras. También se conserva la suite de regresión de
P5-A/P5-B/P5-C. Las pruebas no ejecutan INSERT, UPDATE ni CALL contra la base
protegida; una validación persistente requiere una copia aislada autorizada.
