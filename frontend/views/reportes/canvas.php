<?php
$chartPayload=['states'=>['labels'=>array_values($stateLabels),'values'=>array_values($stateTotals)],'doctors'=>['labels'=>array_column($workload,'medico_nombre'),'rows'=>$workload],'specialties'=>['labels'=>array_column($specialtyWorkload,'nombre'),'rows'=>$specialtyWorkload]];
?>
<div id="report-chart-data" hidden data-report="<?= $escape(json_encode($chartPayload,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)) ?>"></div>
<div class="report-canvas-grid" aria-label="Gráficos estadísticos"><?php foreach(['states'=>'Citas por estado','doctors'=>'Horas por médico','specialties'=>'Horas por especialidad'] as $kind=>$label): ?><article class="patient-card report-canvas-card"><h3><?= $escape($label) ?></h3><div class="report-canvas-frame"><canvas data-report-chart="<?= $escape($kind) ?>" role="img" aria-label="<?= $escape($label) ?>. Los valores completos aparecen en las tablas y barras de abajo."></canvas></div></article><?php endforeach; ?></div>
