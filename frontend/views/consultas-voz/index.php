<?php use Clinica\Services\SessionService; use Clinica\Middleware\VoiceAccess; ?>
<div class="patient-page-heading"><div><span class="eyebrow text-secondary">P10 · SOLO CONSULTAS</span><h1>Pregúntale a Vida Salud</h1><p class="text-secondary">Consulta información autorizada con tu voz o por escrito. Revisa la transcripción antes de enviarla.</p></div></div>
<section class="patient-card mb-4" aria-labelledby="voice-form-title" data-voice-panel>
<h2 class="h5" id="voice-form-title">Tu consulta</h2>
<p id="voice-privacy" class="small text-secondary">El reconocimiento depende del navegador y puede usar servicios externos del proveedor. La clínica no guarda grabaciones ni un historial de transcripciones. El micrófono solo se activa al pulsar el botón y se detiene al terminar una frase o a los 12 segundos. Evita dictar información sensible.</p>
<p id="voice-status" role="status" aria-live="polite">Puedes escribir y consultar aunque el reconocimiento de voz no esté disponible.</p>
<div class="d-flex gap-2 flex-wrap mb-3">
<button type="button" id="voice-start" class="btn btn-clinic" aria-pressed="false" aria-describedby="voice-privacy voice-status" hidden>🎙️ Usar micrófono</button>
<button type="button" id="voice-stop" class="btn btn-outline-secondary" disabled hidden>Detener micrófono</button>
</div>
<form method="post" action="/consultas-voz/consultar" id="voice-form">
<input type="hidden" name="csrf" value="<?= $escape(SessionService::csrf()) ?>">
<label for="voice-query" class="form-label">Consulta escrita o transcripción reconocida</label>
<textarea class="form-control" name="consulta" id="voice-query" maxlength="240" rows="3" required aria-describedby="voice-examples"><?= $escape($input) ?></textarea>
<div class="row align-items-end g-3 mt-1"><div class="col-md-7">
<label for="voice-date" class="form-label">Fecha opcional para consultar horarios</label>
<input class="form-control" type="date" name="fecha" id="voice-date" value="<?= $escape($dateInput) ?>">
<div class="form-text">Sin fecha: próximos diez días hábiles. Agenda de hoy siempre usa la fecha real de America/La_Paz.</div></div>
<div class="col-md-5"><button class="btn btn-clinic" type="submit">Consultar en PostgreSQL</button></div></div>
<div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="voice-read" name="leer" value="1" <?= $read ? 'checked' : '' ?>><label class="form-check-label" for="voice-read">Habilitar lectura del resumen de la respuesta</label></div>
<p class="form-text">La lectura requiere pulsar «Leer respuesta». Al desmarcar esta opción se detiene. No se leen las filas con datos individuales.</p>
</form>
<noscript><p class="alert alert-info mt-3">JavaScript está desactivado: el formulario escrito sigue funcionando. El micrófono y la lectura en voz alta requieren JavaScript.</p></noscript>
<div id="voice-examples" class="mt-4"><h3 class="h6">Consultas admitidas según tus permisos</h3><ul>
<?php if(VoiceAccess::query($user,'proximas')): ?><li>¿Cuáles son mis próximas citas?</li><li>Muéstrame mi agenda de hoy.</li><?php endif; ?>
<?php if(VoiceAccess::query($user,'medicos')): ?><li>¿Qué médicos atienden pediatría? <span class="text-secondary">(Si la especialidad no existe, se informa.)</span></li><li>¿Qué médicos atienden medicina general?</li><li>¿Qué horarios hay disponibles? También: «Horarios disponibles de medicina general».</li><?php endif; ?>
<?php if(VoiceAccess::query($user,'pagos')): ?><li>¿Cuál es el estado de mi pago?</li><?php endif; ?>
</ul><p class="small text-secondary">Paciente: solo sus citas y pagos. Médico: su propia agenda y disponibilidad. Personal: ámbito administrativo autorizado. No se realizan reservas, cambios, cancelaciones ni pagos.</p></div>
</section>
<?php if($error!==null): ?><section class="alert alert-warning" role="alert"><h2 class="h5">No se pudo responder</h2><p class="mb-0"><?= $escape($error) ?></p></section><?php endif; ?>
<?php if($answer!==null): ?>
<section class="patient-card" aria-labelledby="voice-answer-title" data-voice-answer data-spoken="<?= $escape($answer['spoken']) ?>">
<h2 class="h4" id="voice-answer-title"><?= $escape($answer['title']) ?></h2>
<p id="voice-answer-summary" role="status"><?= $escape($answer['message']) ?></p>
<p class="small text-secondary"><?= $escape($answer['source'].' · '.$answer['at']) ?></p>
<div class="d-flex gap-2 mb-3"><button type="button" class="btn btn-outline-secondary" id="voice-speak" hidden disabled>Leer respuesta</button><button type="button" class="btn btn-outline-secondary" id="voice-silence" hidden disabled>Detener lectura</button></div>
<p id="voice-speech-status" class="small" role="status"></p>
<?php if($answer['rows']): ?><div class="table-responsive"><table class="table align-middle"><caption>Resultados autorizados de la consulta actual (máximo diez).</caption><thead><tr><?php foreach($answer['columns'] as $column): ?><th scope="col"><?= $escape($column) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($answer['rows'] as $row): ?><tr><?php foreach($row as $cell): ?><td><?= $escape($cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php $related=$answer['intent']==='pagos' ? ($user['role']==='Paciente' ? '/mis-pagos' : '/pagos') : \Clinica\Middleware\CitaAccess::base($user).($answer['intent']==='horarios' || $answer['intent']==='medicos' ? '/disponibilidad' : ($user['role']==='Paciente' ? '' : '/agenda')); ?>
<a class="btn btn-outline-secondary" href="<?= $escape($related) ?>"><?= $answer['intent']==='pagos' ? 'Abrir pagos de demostración' : 'Abrir consulta completa' ?></a>
</section>
<?php endif; ?>
<script src="/assets/js/consultas-voz.js" defer></script>
