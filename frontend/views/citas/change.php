<?php use Clinica\Services\SessionService; ?>
<div class="patient-page-heading"><div><span class="eyebrow text-secondary">GESTIÓN DE CITAS</span><h1><?= $escape($title) ?></h1><p><?= $escape($appointment['codigo_cita']) ?> · <?= $escape($appointment['fecha'].' '.$appointment['hora_inicio'].'–'.$appointment['hora_fin']) ?> · <?= $escape($appointment['estado']) ?></p></div></div>
<div class="alert alert-warning">Los cambios conservan historial y auditoría. No se modifican pagos ni se realizan devoluciones. Al cancelar, el procedimiento existente también cancela una reserva provisional pendiente.</div>
<?php if(!$enabled): ?><div class="alert alert-info">El guardado está deshabilitado. Este formulario no ha modificado la cita.</div><?php endif; ?>
<?php if($action==='reprogramar'): ?>
<form method="get" action="<?= $escape($base) ?>/reprogramar" class="patient-card mb-4"><input type="hidden" name="id" value="<?= $escape($appointment['id_cita']) ?>"><label for="fechaConsulta" class="form-label">Consultar nueva fecha (America/La_Paz)</label><input type="date" id="fechaConsulta" name="fecha" value="<?= $escape($date) ?>" class="form-control" required><button class="btn btn-outline-primary mt-3">Consultar franjas</button></form>
<div class="alert alert-info"><?= $escape($availability['message']) ?></div>
<?php endif; ?>
<form method="post" action="<?= $escape($base.'/'.$action) ?>" class="patient-card patient-form">
<input type="hidden" name="csrf" value="<?= $escape(SessionService::csrf()) ?>"><input type="hidden" name="id" value="<?= $escape($appointment['id_cita']) ?>"><input type="hidden" name="version" value="<?= $escape($version) ?>">
<?php if($action==='reprogramar'): ?>
<h2 class="h5">Nueva hora con el mismo médico y especialidad</h2><p class="text-secondary">Se permite una sola reprogramación. El horario anterior se liberará después de guardar.</p><input type="hidden" name="fecha" value="<?= $escape($date) ?>">
<div class="appointment-slots"><?php foreach($availability['slots'] as $slot): $same=$date===$appointment['fecha'] && $slot['hora_inicio']===$appointment['hora_inicio']; ?><label class="appointment-slot <?= $slot['available'] && !$same ? 'slot-disponible' : 'slot-ocupada' ?>"><input type="radio" name="hora" value="<?= $escape($slot['hora_inicio']) ?>" required <?= !$slot['available'] || $same ? 'disabled' : '' ?>><span><strong><?= $escape($slot['hora_inicio'].'–'.$slot['hora_fin']) ?></strong><small><?= $same ? 'Horario actual' : $escape($slot['estado']) ?></small></span></label><?php endforeach; ?></div>
<?php else: ?>
<h2 class="h5">Confirmar cancelación</h2><p>Se liberará el horario y se conservará la cita como cancelada.</p><label for="motivo" class="form-label">Motivo (opcional, máximo 2000 caracteres)</label><textarea class="form-control" name="motivo" id="motivo" maxlength="2000" rows="3"></textarea>
<?php endif; ?>
<div class="patient-form-actions"><a class="btn btn-outline-secondary" href="<?= $escape($base) ?>/detalle?id=<?= $escape($appointment['id_cita']) ?>">Volver sin cambios</a><button class="btn <?= $action==='cancelar' ? 'btn-danger' : 'btn-clinic' ?>" <?= !$enabled ? 'disabled' : '' ?>><?= $action==='cancelar' ? 'Confirmar cancelación' : 'Confirmar reprogramación' ?></button></div></form>
