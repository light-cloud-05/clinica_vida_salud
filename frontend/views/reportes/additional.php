<?php
$patients=[];
foreach($report['appointments'] as $appointment) {
    $key=(string)($appointment['id_paciente']??$appointment['paciente_nombre']);
    if(!isset($patients[$key])) {$patients[$key]=['nombre'=>$appointment['paciente_nombre'],'total'=>0,'atendidas'=>0,'inasistencias'=>0];}
    ++$patients[$key]['total'];
    if($appointment['estado']==='atendida') {++$patients[$key]['atendidas'];}
    if($appointment['estado']==='inasistencia') {++$patients[$key]['inasistencias'];}
}
?>
<section id="report-pacientes" class="patient-card my-4"><div class="schedule-card-heading"><strong>Pacientes en las citas filtradas</strong><span><?= count($patients) ?> registros</span></div><div class="table-responsive"><table class="table patient-table"><thead><tr><th>Paciente</th><th>Citas</th><th>Atendidas</th><th>Inasistencias</th></tr></thead><tbody><?php foreach($patients as $name=>$row): ?><tr><td><?= $escape($row['nombre']) ?></td><td><?= $row['total'] ?></td><td><?= $row['atendidas'] ?></td><td><?= $row['inasistencias'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php if(!$patients): ?><p class="report-zero">Sin pacientes en los resultados autorizados.</p><?php endif; ?></section>
<section id="report-incidencias" class="patient-card my-4"><div class="schedule-card-heading"><strong>Cancelaciones e inasistencias de pacientes</strong></div><div class="table-responsive"><table class="table patient-table"><thead><tr><th>Cita</th><th>Fecha</th><th>Estado</th></tr></thead><tbody><?php $incidents=0; foreach($report['appointments'] as $appointment): if(!in_array($appointment['estado'],['cancelada','inasistencia'],true)){continue;} ++$incidents; ?><tr><td><?= $escape($appointment['codigo_cita']) ?></td><td><?= $escape($appointment['fecha']) ?></td><td><?= $escape($appointment['estado']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php if(!$incidents): ?><p class="report-zero">Sin incidencias para estos filtros.</p><?php endif; ?></section>
