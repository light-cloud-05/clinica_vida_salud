# Clínica Vida Salud

- PHP nativo 8.x, PDO_PGSQL, Bootstrap 5 y JavaScript; sin frameworks backend ni React/Vue.
- Leer docs/requisitos antes de cada módulo. No inventar tablas, columnas, relaciones ni permisos; contrastar el esquema real antes de implementarlos.
- La BD existente clinica_vida_salud está protegida: no ejecutar migraciones, seeds, DDL, DROP, TRUNCATE, vaciados ni recreaciones sin autorización explícita. No activar ni modificar los 51 usuarios ficticios.
- Etapa actual: implementar P5-C reprogramación y cancelación reutilizando P5-A/P5-B/P4. No ejecutar pruebas persistentes en la base local sin autorización explícita; usar simulaciones. Mantener APPOINTMENT_CHANGES_ENABLED, APPOINTMENT_WRITES_ENABLED y los indicadores anteriores desactivados por defecto. No modificar .env ni las 60 citas existentes. No implementar pagos (P9), ni activar cuentas.
- Solo public/ debe ser DocumentRoot. Nunca versionar .env ni credenciales. No modificar configuración global ni instalar herramientas innecesarias sin autorización.
- Capas: frontend (presentación), rutas/controladores/middleware (aplicación), servicios (negocio), repositorios/modelos/PDO (persistencia).
- Usar consultas parametrizadas. Reutilizar funciones, procedimientos y restricciones existentes después de verificar sus firmas.
- Zona de negocio America/La_Paz. Reservas en 10 días hábiles incluido hoy con disponibilidad; 30 minutos por defecto/configuración médica; una reprogramación; sin superposiciones ni bloqueos.
- Recepción/caja lunes a viernes 08:00–18:00; efectivo hasta 48 horas condicionado por caja y anticipación. Solo PayPal Sandbox/QR simulado.
- En etapas posteriores validar permisos en interfaz y endpoint, además de propiedad de registros.
- Ejecutar composer test y lint PHP. Reportar comprobaciones realmente ejecutadas y bloqueos; nunca afirmar conexión sin evidencia.
