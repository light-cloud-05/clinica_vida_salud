<?php use Clinica\Services\SessionService; ?>
<h1>Nuevo ensayo de pago</h1>
<p><?= !empty($integration) ? 'PRUEBA PERSISTENTE: seleccione una cita NUEVA de P5-B. Se guardará en clinica_vida_salud_test; no se modifica la base original.' : 'Solo demostración. Seleccione una cita registrada en P5-B. El servidor consulta titular, turno e importe en PostgreSQL; la cita real no se modifica.' ?></p>
<form method="post" action="<?= $escape($base) ?>/crear" class="patient-card patient-form">
<input type="hidden" name="csrf" value="<?= $escape(SessionService::csrf()) ?>">
<input type="hidden" name="intent" value="<?= $escape($intent) ?>">
<label for="cita" class="form-label">Cita programada / importe de referencia BOB</label>
<select name="cita" id="cita" class="form-select" required>
<option value="">Seleccione una cita</option>
<?php foreach($appointments as $appointment): ?>
<option value="<?= $escape($appointment['id_cita']) ?>"><?= $escape($appointment['codigo_cita'].' · '.$appointment['fecha'].' '.$appointment['hora_inicio'].' · '.$appointment['precio_consulta'].' BOB · registrada '.$appointment['fecha_registro']) ?></option>
<?php endforeach; ?></select>
<label for="metodo" class="form-label mt-3">Método</label>
<select name="metodo" id="metodo" class="form-select" required>
<?php foreach($methods as $key=>$label): ?><option value="<?= $escape($key) ?>"><?= $escape($label) ?></option><?php endforeach; ?></select>
<?php if(empty($integration)): ?><label for="reloj" class="form-label mt-3">Reloj de ensayo opcional (America/La_Paz)</label>
<input type="datetime-local" name="reloj" id="reloj" class="form-control">
<p class="form-text">Permite revisar citas históricas en simulación local. Efectivo queda pendiente: solo recepción confirma dentro del horario de caja y del plazo de reserva. Nunca cambia el reloj del servidor.</p>
<?php else: ?><p>El reloj de negocio de la prueba, si se usa, se configura al iniciar el servidor aislado. El Paciente solo selecciona efectivo; recepción confirma en horario de caja.</p><?php endif; ?>
<button class="btn btn-clinic" type="submit" <?= !$appointments ? 'disabled' : '' ?>>Crear ensayo</button>
</form>
