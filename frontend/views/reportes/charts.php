<?php
/** Presentation only: every value comes from the existing filtered report. */
$stateLabels=['programada'=>'Programadas','confirmada'=>'Confirmadas','atendida'=>'Atendidas','cancelada'=>'Canceladas','inasistencia'=>'Inasistencias de pacientes'];
$stateTotals=array_fill_keys(array_keys($stateLabels),0);
foreach ($report['appointments'] as $appointment) {
    if (array_key_exists($appointment['estado'],$stateTotals)) { ++$stateTotals[$appointment['estado']]; }
}
$stateMax=max(1,...array_values($stateTotals));
$hoursLabels=['horas_programadas_periodo'=>'Programadas','horas_ocupadas'=>'Ocupadas','horas_atendidas'=>'Atendidas','horas_disponibles'=>'Libres futuras'];
$hourColors=['horas_programadas_periodo'=>'scheduled','horas_ocupadas'=>'occupied','horas_atendidas'=>'attended','horas_disponibles'=>'available'];
$number=static fn(float $value): string=>number_format($value,2,',','.');
$bar=static function (float $value,float $max,string $color): void {
    $width=number_format(max(0,min(1000,$max>0?$value*1000/$max:0)),2,'.','');
    ?><svg class="report-bar" viewBox="0 0 1000 12" preserveAspectRatio="none" aria-hidden="true"><rect class="report-bar-track" width="1000" height="12" rx="6"/><rect class="report-bar-<?= htmlspecialchars($color,ENT_QUOTES,'UTF-8') ?>" width="<?= $width ?>" height="12" rx="6"/></svg><?php
};
?>
<?php require __DIR__.'/canvas.php'; ?>
<section class="report-visuals mb-4" aria-labelledby="report-visual-title">
    <div class="report-visual-heading"><div><span class="eyebrow">UNA MIRADA A LA AGENDA</span><h2 id="report-visual-title">Estadísticas de citas</h2></div><span class="report-data-label">Datos consultados · sin estimaciones de citas</span></div>
    <div id="report-estados" class="report-state-panel"><div><h3>Distribución por estado</h3><p>Resultados de los filtros aplicados. Los porcentajes se calculan sobre todas las citas filtradas.</p><strong class="report-total"><?= $escape(count($report['appointments'])) ?><small>citas en el resultado</small></strong><?php if (!$report['appointments']): ?><p class="report-zero" role="status">Sin citas para estos filtros. No hay porcentajes que calcular.</p><?php endif; ?></div>
    <ul class="report-state-bars"><?php foreach ($stateLabels as $state=>$label): ?><li><div><span><?= $escape($label) ?></span><strong><?= $escape($stateTotals[$state]) ?><small><?= $escape($percent($stateTotals[$state],count($report['appointments']))) ?></small></strong></div><?php $bar((float)$stateTotals[$state],(float)$stateMax,$state); ?></li><?php endforeach; ?></ul></div>
    <?php foreach (['medico'=>['title'=>'Carga por médico','rows'=>$workload,'name'=>'medico_nombre'],'especialidad'=>['title'=>'Carga por especialidad','rows'=>$specialtyWorkload,'name'=>'nombre']] as $kind=>$chart):
        $maxHours=1.0; foreach ($chart['rows'] as $chartRow) { foreach ($hoursLabels as $field=>$label) { $maxHours=max($maxHours,(float)($chartRow[$field]??0)); } }
    ?>
    <details class="report-chart-section" open><summary><?= $escape($chart['title']) ?><span><?= count($chart['rows']) ?> registros · horas</span></summary>
        <p class="report-chart-note"><?php if ($kind==='especialidad'): ?>La capacidad programada y libre se estima repartiendo el horario por igual entre las especialidades de cada médico. Las horas ocupadas y atendidas corresponden a sus citas reales filtradas.<?php else: ?>Las horas atendidas son parte de las ocupadas, no se suman entre sí. Ocupadas excluye citas canceladas y respeta el filtro de estado y especialidad.<?php endif; ?></p>
        <?php if (!$chart['rows']): ?><p class="report-zero">Sin registros para mostrar en este periodo.</p><?php else: ?><div class="report-hours-grid"><?php foreach ($chart['rows'] as $chartRow): ?><article class="report-hours-card"><h4><?= $escape($chartRow[$chart['name']]) ?></h4><ul><?php foreach ($hoursLabels as $field=>$label): $value=(float)($chartRow[$field]??0); ?><li><div><span><?= $escape($label) ?></span><strong><?= $escape($number($value)) ?> h</strong></div><?php $bar($value,$maxHours,$hourColors[$field]); ?></li><?php endforeach; ?></ul></article><?php endforeach; ?></div><?php endif; ?>
    </details><?php endforeach; ?>
    <p class="report-chart-footnote">Periodo de carga: <?= $escape(($planningRange['from']??'sin datos').' — '.($planningRange['to']??'sin datos')) ?>. Sin fechas, la carga muestra el día actual; el detalle de citas puede abarcar más fechas. Las franjas libres se calculan con toda la agenda y los bloqueos, independientemente del filtro de estado. La reserva sigue su ventana de diez días hábiles.</p>
</section>
