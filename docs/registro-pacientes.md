# CU15 — Registro público de paciente

## Rutas

- `GET/POST /registro`: formulario público protegido por CSRF.
- El login enlaza a `Crear cuenta`.

El formulario solicita usuario, correo, código, documento, nombres, apellidos y contraseña. El servidor valida tipos, tamaños, correo, contraseña mínima de ocho caracteres y campos permitidos. El rol no se recibe del navegador: la transacción consulta `roles.nombre='Paciente'` y crea exclusivamente ese rol.

Si código y documento ya existen, la cuenta solo puede vincularse cuando coinciden también nombres y apellidos y el paciente todavía no tiene `id_usuario`. Una coincidencia parcial o un paciente ya vinculado se rechaza y se indica solicitar la vinculación a recepción. Así no se asocia una cuenta automáticamente por un único dato.

La creación de `usuarios` y `pacientes`, o la vinculación segura de un paciente existente, ocurre en una única transacción PDO con consultas preparadas. `PATIENT_ACCOUNT_WRITES_ENABLED=false` por defecto; con la bandera apagada el formulario no escribe.

## Verificación

`tests/cu15-cu16-cu20.php` prueba validación, duplicados, rol Paciente y vinculación con identidad completa usando repositorios simulados. No se ejecutó una alta contra PostgreSQL ni se modificaron cuentas demo.
