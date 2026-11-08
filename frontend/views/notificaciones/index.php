<p class="text-secondary small">Enviado indica aceptación por el servidor SMTP. Si el correo no está configurado o falla, se registra como error; no se confirma una entrega simulada.</p>
<div class="patient-page-heading"><div><span class="eyebrow text-secondary">CU22 · CORREO</span><h1><?= $escape($title) ?></h1><p class="text-secondary">Solo se muestran datos administrativos de la cita.</p></div></div>
<section class="patient-card">
    <?php if (!$rows): ?><p class="mb-0 text-secondary">No hay notificaciones registradas.</p><?php else: ?>
    <div class="table-responsive"><table class="table align-middle mb-0"><caption class="visually-hidden">Listado de notificaciones</caption><thead><tr><th>Fecha</th><th>Asunto</th><th>Tipo</th><th>Estado</th><?php if ($user['role'] !== 'Paciente'): ?><th>Destinatario</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr><td><?= $escape($row['fecha_programada'] ?? $row['fecha_envio']) ?></td><td><?= $escape($row['asunto']) ?></td><td><?= $escape($row['tipo']) ?></td><td><span class="badge text-bg-<?= $row['estado_envio']==='enviado' ? 'success' : ($row['estado_envio']==='error' ? 'danger' : 'secondary') ?>"><?= $escape($row['estado_envio']) ?></span></td><?php if ($user['role'] !== 'Paciente'): ?><td><?= $escape($row['destinatario']) ?></td><?php endif; ?></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
