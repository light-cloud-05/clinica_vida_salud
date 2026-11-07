# Historial clínico básico

## Rutas

- `GET /citas/historial-paciente?id=...`: historial dentro del ámbito del
  personal autorizado.
- `GET /mis-citas/historial?id=...`: historial propio permitido del paciente.
- `POST /citas/notas-medicas`: registra observaciones de una cita atendida.
- `POST /citas/tratamientos`: registra descripción e indicaciones de una cita
  atendida.

El enlace **Historial del paciente** aparece en el detalle de una cita. Los
identificadores enviados por URL o formulario se vuelven a validar en SQL:
un médico solo puede usar citas de su agenda y un paciente solo puede usar su
propio paciente. Los formularios POST exigen CSRF.

## Política por rol

- **Médico** con `gestionar_notas_medicas`: consulta antecedentes, alergias,
  tipo de sangre, citas, notas, tratamientos y cambios de sus pacientes
  vinculados; puede registrar notas y tratamientos únicamente para citas
  `atendida`.
- **Paciente** con `consultar_informacion_medica_propia`: consulta únicamente
  su historial permitido.
- **Recepcionista** y **Administrador**: solo ven identificación, citas y
  cambios administrativos; no reciben notas, tratamientos ni antecedentes
  clínicos. No se inventaron permisos adicionales.

## Esquema utilizado

Se verificaron en PostgreSQL, en modo lectura, las tablas `pacientes`,
`citas`, `medicos`, `notas_medicas`, `tratamientos_recetas` y
`historial_cambios_cita`, junto con sus columnas y claves foráneas. El módulo
usa las columnas existentes: `tipo_sangre`, `alergias`,
`antecedentes_relevantes`, `observaciones`, `descripcion` e `indicaciones`.
La trazabilidad de los nuevos registros conserva la cita, el médico y la fecha
de registro; además configura `app.usuario_id` para los triggers existentes.

## Escrituras y pruebas

`MEDICAL_RECORD_WRITES_ENABLED` permanece desactivado por defecto. Por ello
las pruebas ejecutadas son simuladas y no insertan en PostgreSQL. Se ejecutó
la inspección real de esquema, permisos de aplicación y privilegios SQL en
modo lectura. No se modificaron la base original, `.env`, cuentas ni citas.

La prueba `tests/historial-clinico.php` cubre autorización por rol, rechazo
fuera de ámbito, CSRF/rutas a nivel de integración declarativa, registros
vacíos, escritura bloqueada y asociación simulada de nota/tratamiento. Las
regresiones de citas y asistencia permanecen en la suite existente.

Limitación: no se habilitó persistencia clínica aislada porque la instrucción
de entrega exige mantener las escrituras desactivadas y no se autorizó una
base de pruebas separada.
