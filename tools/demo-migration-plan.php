<?php
declare(strict_types=1);

/** Pure planner. No PDO, filesystem, credentials or SQL writes. */
final class DemoMigrationPlan
{
    public const KEYS = ['especialidades'=>'id_especialidad','medicos'=>'id_medico','pacientes'=>'id_paciente','medico_especialidad'=>null,'horarios_atencion'=>'id_horario','citas'=>'id_cita'];
    public const FIELDS = [
        'especialidades'=>['nombre','descripcion','precio_consulta','estado'],
        'medicos'=>['codigo_medico','registro_profesional','nombres','apellidos','estado'],
        'pacientes'=>['codigo_paciente','documento_identidad','nombres','apellidos','fecha_nacimiento','sexo','estado'],
        'medico_especialidad'=>['id_medico','id_especialidad'],
        'horarios_atencion'=>['id_medico','dia_semana','hora_inicio','hora_fin','duracion_cita_minutos','estado'],
        'citas'=>['id_paciente','id_medico','id_especialidad','codigo_cita','fecha','hora_inicio','hora_fin','estado','cantidad_reprogramaciones'],
    ];
    public static function overlap(array $a, array $b): bool
    {
        return $a['hora_inicio'] < $b['hora_fin'] && $a['hora_fin'] > $b['hora_inicio'];
    }
    private static function same(array $existing, array $row): bool
    {
        foreach ($row as $k=>$v) { if (($existing[$k] ?? null) !== $v && (string)($existing[$k] ?? '') !== (string)($v ?? '')) { return false; } }
        return true;
    }
    public static function build(array $source, array $target, DateTimeImmutable $now): array
    {
        $targetBefore = $target; $maps = []; $operations = []; $warnings = []; $virtual = -1;
        $now = $now->setTimezone(new DateTimeZone('America/La_Paz'));
        $days=[]; for ($d=$now->setTime(0,0); count($days)<10; $d=$d->modify('+1 day')) { if ((int)$d->format('N')<=5) { $days[]=$d->format('Y-m-d'); } }
        $add = static function (string $table, array $row) use (&$target,&$operations,&$virtual): int {
            $key = self::KEYS[$table]; $id = $virtual--;
            if ($key !== null) { $row[$key] = $id; }
            $target[$table][] = $row;
            $operations[] = ['table'=>$table,'virtual'=>$id,'row'=>array_diff_key($row, $key === null ? [] : [$key=>true])];
            return $id;
        };
        foreach (self::KEYS as $table=>$key) {
            foreach ($source[$table] as $s) {
                $row = array_intersect_key($s,array_flip(self::FIELDS[$table]));
                $sourceId = $key === null ? null : (int)$s[$key];
                foreach (['id_medico'=>'medicos','id_paciente'=>'pacientes','id_especialidad'=>'especialidades'] as $fk=>$parent) {
                    if (isset($row[$fk])) {
                        if (!isset($maps[$parent][(int)$row[$fk]])) { throw new RuntimeException('Referencia de origen sin padre: '.$table); }
                        $row[$fk]=$maps[$parent][(int)$row[$fk]];
                    }
                }
                $codeColumn = null;
                if ($table === 'especialidades') {
                    foreach ($target[$table] as $existing) {
                        if (mb_strtolower(trim($existing['nombre'])) === mb_strtolower(trim($row['nombre']))) {
                            if ($existing['estado'] !== $row['estado']) { throw new RuntimeException('Especialidad existente con estado distinto.'); }
                            if ($existing['precio_consulta'] !== $row['precio_consulta']) { $warnings[]='Conservar tarifa Render de '.$existing['nombre'].': '.$existing['precio_consulta'].' (local '.$row['precio_consulta'].').'; }
                            $maps[$table][$sourceId]=(int)$existing[$key]; continue 2;
                        }
                    }
                } elseif ($table === 'medicos') {
                    $codeColumn='codigo_medico'; $row[$codeColumn]='UNI26-M-'.$sourceId;
                    $row['registro_profesional']='UNI26-R-'.$sourceId; $row['id_usuario']=null;
                } elseif ($table === 'pacientes') {
                    $codeColumn='codigo_paciente'; $row[$codeColumn]='UNI26-P-'.$sourceId;
                    $row['documento_identidad']='UNI26-D-'.$sourceId; $row['id_usuario']=null;
                } elseif ($table === 'citas') {
                    $codeColumn='codigo_cita'; $row[$codeColumn]='UNI26-C-'.$sourceId;
                }
                foreach ($target[$table] as $existing) {
                    $sameKey = $codeColumn !== null ? $existing[$codeColumn] === $row[$codeColumn] : self::same($existing,$row);
                    if ($sameKey) {
                        if (!self::same($existing,$row)) { throw new RuntimeException('Registro UNI26 existente divergente: '.$table); }
                        if ($key !== null) { $maps[$table][$sourceId]=(int)$existing[$key]; }
                        continue 2;
                    }
                    if ($table==='medicos' && $existing['registro_profesional']===$row['registro_profesional']) { throw new RuntimeException('Registro profesional ocupado.'); }
                    if ($table==='pacientes' && $existing['documento_identidad']===$row['documento_identidad']) { throw new RuntimeException('Documento ficticio ocupado.'); }
                }
                if ($table==='horarios_atencion' && $row['estado']==='activo') {
                    foreach ($target[$table] as $existing) {
                        if ($existing['estado']==='activo' && (int)$existing['id_medico']===$row['id_medico'] && (int)$existing['dia_semana']===(int)$row['dia_semana'] && self::overlap($existing,$row)) { throw new RuntimeException('Solapamiento de horario.'); }
                    }
                }
                if ($table==='citas') { self::validateAppointment($row,$target,$now,$days); }
                $id=$add($table,$row);
                if ($key!==null) { $maps[$table][$sourceId]=$id; }
            }
        }
        $report=['insertar'=>[],'reutilizar'=>[],'antes'=>[],'despues'=>[],'advertencias'=>array_values(array_unique($warnings))];
        foreach (self::KEYS as $table=>$key) {
            $report['antes'][$table]=count($targetBefore[$table]);
            $report['despues'][$table]=count($target[$table]);
            $report['insertar'][$table]=count($target[$table])-count($targetBefore[$table]);
            $report['reutilizar'][$table]=count($source[$table])-$report['insertar'][$table];
        }
        return ['operations'=>$operations,'maps'=>$maps,'report'=>$report];
    }
    private static function validateAppointment(array $r, array $target, DateTimeImmutable $now, array $days): void
    {
        if ($r['hora_fin'] <= $r['hora_inicio']) { throw new RuntimeException('Intervalo de cita inválido.'); }
        $association=false;
        foreach ($target['medico_especialidad'] as $a) { if ((int)$a['id_medico']===$r['id_medico'] && (int)$a['id_especialidad']===$r['id_especialidad']) { $association=true; } }
        if (!$association) { throw new RuntimeException('Cita sin asociación médico-especialidad.'); }
        if (!in_array($r['estado'],['programada','confirmada','atendida','cancelada','inasistencia'],true) || !in_array((int)$r['cantidad_reprogramaciones'],[0,1],true)) { throw new RuntimeException('Estado o reprogramación inválidos.'); }
        foreach ($target['citas'] as $c) {
            $sameDoctor=(int)$c['id_medico']===$r['id_medico'];
            $samePatient=(int)$c['id_paciente']===$r['id_paciente'];
            $doctorConflict=$sameDoctor && $c['estado']!=='cancelada' && $r['estado']!=='cancelada';
            $patientConflict=$samePatient && in_array($c['estado'],['programada','confirmada'],true) && in_array($r['estado'],['programada','confirmada'],true);
            if ($c['fecha']===$r['fecha'] && ($doctorConflict || $patientConflict) && self::overlap($c,$r)) { throw new RuntimeException('Citas superpuestas.'); }
        }
        if (!in_array($r['estado'],['programada','confirmada'],true)) { return; }
        if (!in_array($r['fecha'],$days,true) || new DateTimeImmutable($r['fecha'].' '.$r['hora_inicio'],$now->getTimezone()) <= $now) { throw new RuntimeException('Cita fuera de ventana futura; no se cambiarán fechas automáticamente.'); }
        $doctor=false;
        foreach ($target['medicos'] as $m) { if ((int)$m['id_medico']===$r['id_medico'] && $m['estado']==='activo') { $doctor=true; } }
        if (!$doctor) { throw new RuntimeException('Médico de cita no activo.'); }
        $seconds = static fn(string $time): int => (int)substr($time,0,2)*3600+(int)substr($time,3,2)*60+(int)substr($time,6,2);
        $schedule=false;
        foreach ($target['horarios_atencion'] as $h) {
            $duration=(int)$h['duracion_cita_minutos']*60;
            if ((int)$h['id_medico']===$r['id_medico'] && $h['estado']==='activo' && (int)$h['dia_semana']===(int)(new DateTimeImmutable($r['fecha']))->format('N') && $duration>0
                && $r['hora_inicio'] >= $h['hora_inicio'] && $r['hora_fin'] <= $h['hora_fin'] && $seconds($r['hora_fin'])-$seconds($r['hora_inicio'])===$duration && ($seconds($r['hora_inicio'])-$seconds($h['hora_inicio']))%$duration===0) { $schedule=true; }
        }
        if (!$schedule) { throw new RuntimeException('Cita fuera del horario o duración configurada.'); }
        foreach ($target['bloqueos_horario'] ?? [] as $b) { if ((int)$b['id_medico']===$r['id_medico'] && $b['fecha']===$r['fecha'] && self::overlap($b,$r)) { throw new RuntimeException('Cita en bloqueo existente.'); } }

        foreach ($target['reservas_activas'] ?? [] as $b) { if ($b['fecha']===$r['fecha'] && ((int)$b['id_medico']===$r['id_medico'] || (int)$b['id_paciente']===$r['id_paciente']) && self::overlap($b,$r)) { throw new RuntimeException('Reserva temporal en conflicto.'); } }
    }
}
