# P8 — Consulta de auditoría

## Alcance

El módulo está disponible en `/auditoria` y `/auditoria/detalle`. El acceso se
valida en el enrutador y nuevamente en `AuditService` mediante el permiso real
`consultar_auditoria`, asignado únicamente al rol Administrador.

La consulta es exclusivamente de lectura. Usa `registros_auditoria` para el
listado paginado y `historial_cambios_cita` para mostrar cambios de citas. Los
actores se resuelven mediante `usuarios` y `roles`; cuando `id_usuario` es nulo
se muestra explícitamente “Actor no registrado”. No se muestran hashes,
contraseñas, tokens ni secretos.

## Esquema contrastado

La consulta READ ONLY del 7 de noviembre de 2026 confirmó 392 registros en
`registros_auditoria` y 0 en `historial_cambios_cita`. La auditoría contiene
`id_registro`, `id_usuario`, `fecha_hora`, `operacion`, `entidad_afectada`,
`id_entidad` y `detalles`. El trigger real `auditar_citas` registra
INSERT/UPDATE/DELETE sobre citas; `registrar_cambio_cita` alimenta el historial
de citas. No se ejecutaron migraciones, seeds ni escrituras.

## Funcionalidades

- Filtros validados por usuario responsable, fecha inicial/final, operación y
  entidad afectada.
- Paginación limitada a 25 filas por página.
- Detalle de un movimiento por identificador.
- Historial de cambios de citas con fechas, estados, horarios y actor real.
- Mensajes para resultados vacíos y diseño responsive reutilizando Bootstrap y
  el layout administrativo.
- Navegación desde el panel del Administrador y el menú lateral.

## Pruebas y apertura

La ruta es `http://127.0.0.1:8080/auditoria` con una cuenta Administrador
activa que tenga `consultar_auditoria`. Las pruebas de sintaxis y del módulo
deben ejecutarse con `composer test` y el comando de lint PHP del README.
Las consultas reales indicadas arriba fueron READ ONLY y no modificaron
`clinica_vida_salud`, `clinica_vida_salud_test`, `.env` ni `var/integration/`.

Limitación conocida: el historial de citas real está vacío y la función de
auditoría registra “Actor no registrado” cuando la operación original no
estableció `app.usuario_id`; el módulo no atribuye esos movimientos a otra
cuenta.
