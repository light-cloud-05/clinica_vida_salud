<?php
declare(strict_types=1);
require dirname(__DIR__).'/tools/demo-migration-plan.php';
$n=0;
function check(bool $ok,string $label): void { global $n; if (!$ok) { throw new RuntimeException($label); } ++$n; }
function blocked(callable $f,string $label): void { try { $f(); } catch (RuntimeException $e) { check(true,$label); return; } throw new RuntimeException('No bloqueó: '.$label); }
$now=new DateTimeImmutable('2026-11-07 12:00:00',new DateTimeZone('America/La_Paz'));
$empty=array_fill_keys(array_keys(DemoMigrationPlan::KEYS),[]); $empty['bloqueos_horario']=[]; $empty['reservas_activas']=[];
$source=$empty;
$source['especialidades']=[['id_especialidad'=>11,'nombre'=>'Pediatría','descripcion'=>'Ficticia','precio_consulta'=>'30.00','estado'=>'activo']];
$source['medicos']=[['id_medico'=>21,'codigo_medico'=>'ORIG-M','registro_profesional'=>'ORIG-R','nombres'=>'Ficticio','apellidos'=>'Médico','estado'=>'activo']];
$source['pacientes']=[['id_paciente'=>52,'codigo_paciente'=>'ORIG-P','documento_identidad'=>'ORIG-D','nombres'=>'Ficticio','apellidos'=>'Paciente','fecha_nacimiento'=>null,'sexo'=>null,'estado'=>'activo']];
$source['medico_especialidad']=[['id_medico'=>21,'id_especialidad'=>11]];
$source['horarios_atencion']=[['id_horario'=>91,'id_medico'=>21,'dia_semana'=>1,'hora_inicio'=>'08:00:00','hora_fin'=>'12:00:00','duracion_cita_minutos'=>30,'estado'=>'activo']];
$source['citas']=[['id_cita'=>61,'id_medico'=>21,'id_paciente'=>52,'id_especialidad'=>11,'codigo_cita'=>'ORIG-C','fecha'=>'2026-11-09','hora_inicio'=>'08:00:00','hora_fin'=>'08:30:00','estado'=>'programada','cantidad_reprogramaciones'=>0]];
$target=$empty; $target['especialidades']=[array_replace($source['especialidades'][0],['id_especialidad'=>2,'precio_consulta'=>'150.00'])];
$sourceBefore=$source; $targetBefore=$target;
$plan=DemoMigrationPlan::build($source,$target,$now);
check($source===$sourceBefore && $target===$targetBefore,'No altera snapshots');
check($plan['report']['insertar']['especialidades']===0 && $plan['maps']['especialidades'][11]===2,'Mapeo por nombre normalizado');
check(count($plan['report']['advertencias'])===1,'Tarifa existente conservada con aviso');
check(count($plan['operations'])===5,'Inserciones esperadas');
$real=[]; $after=$target;
foreach($plan['operations'] as $i=>$op){
    $row=$op['row']; foreach(['id_medico','id_paciente','id_especialidad'] as $fk){if(isset($row[$fk]) && $row[$fk]<0){$row[$fk]=$real[$row[$fk]];}}
    $key=DemoMigrationPlan::KEYS[$op['table']]; if($key!==null){$row[$key]=100+$i; $real[$op['virtual']]=$row[$key];}
    $after[$op['table']][]=$row;
}
check($after['medicos'][0]['id_usuario']===null && $after['pacientes'][0]['id_usuario']===null,'No importa cuentas');
check($after['citas'][0]['id_medico']===$after['medicos'][0]['id_medico'] && $after['citas'][0]['id_paciente']===$after['pacientes'][0]['id_paciente'] && $after['citas'][0]['id_especialidad']===2,'FK remapeadas');
check(DemoMigrationPlan::build($source,$after,$now)['operations']===[],'Repetición idempotente');
$bad=$source; $bad['citas'][0]['id_paciente']=999; blocked(fn()=>DemoMigrationPlan::build($bad,$target,$now),'FK huérfana');
$bad=$source; $bad['citas'][0]['fecha']='2026-11-06'; blocked(fn()=>DemoMigrationPlan::build($bad,$target,$now),'Fecha pasada');
$bad=$source; $bad['citas'][0]['fecha']='2026-12-07'; blocked(fn()=>DemoMigrationPlan::build($bad,$target,$now),'Fuera de 10 días hábiles');
$bad=$source; $bad['citas'][0]['hora_fin']='08:45:00'; blocked(fn()=>DemoMigrationPlan::build($bad,$target,$now),'Duración inválida');
$bad=$source; $bad['citas'][0]['hora_inicio']='08:10:00'; $bad['citas'][0]['hora_fin']='08:40:00'; blocked(fn()=>DemoMigrationPlan::build($bad,$target,$now),'Alineación inválida');
$bad=$source; $bad['horarios_atencion'][]=array_replace($bad['horarios_atencion'][0],['id_horario'=>92,'hora_inicio'=>'08:30:00']); blocked(fn()=>DemoMigrationPlan::build($bad,$target,$now),'Horarios superpuestos');
$bad=$source; $bad['citas'][]=array_replace($bad['citas'][0],['id_cita'=>62]); blocked(fn()=>DemoMigrationPlan::build($bad,$target,$now),'Citas superpuestas');
$bad=$source; $bad['medico_especialidad']=[]; blocked(fn()=>DemoMigrationPlan::build($bad,$target,$now),'Asociación ausente');
$bad=$after; $bad['medicos'][0]['nombres']='Modificado'; blocked(fn()=>DemoMigrationPlan::build($source,$bad,$now),'Registro previo divergente');
$bad=$after; $bad['medicos'][0]['codigo_medico']='Otro'; blocked(fn()=>DemoMigrationPlan::build($source,$bad,$now),'Registro profesional ocupado');
$bad=$target; $bad['especialidades'][0]['estado']='inactivo'; blocked(fn()=>DemoMigrationPlan::build($source,$bad,$now),'Especialidad existente incompatible');
$bad=$source; $bad['medicos'][0]['estado']='inactivo'; blocked(fn()=>DemoMigrationPlan::build($bad,$target,$now),'Médico inactivo');
check($after['pacientes'][0]['documento_identidad']==='UNI26-D-52','Documento público con espacio de nombres');
check(!array_key_exists('correo_contacto',$after['pacientes'][0]) && !array_key_exists('motivo_consulta',$after['citas'][0]),'No importa contacto ni texto clínico');
$bad=$after; $bad['citas']=[]; $bad['bloqueos_horario']=[['id_medico'=>$after['medicos'][0]['id_medico'],'fecha'=>'2026-11-09','hora_inicio'=>'08:00:00','hora_fin'=>'08:30:00']]; blocked(fn()=>DemoMigrationPlan::build($source,$bad,$now),'Bloqueo Render');
$bad=$after; $bad['citas']=[]; $bad['reservas_activas']=[['id_medico'=>$after['medicos'][0]['id_medico'],'id_paciente'=>999,'fecha'=>'2026-11-09','hora_inicio'=>'08:00:00','hora_fin'=>'08:30:00']]; blocked(fn()=>DemoMigrationPlan::build($source,$bad,$now),'Reserva Render');
$bad=$after; $bad['citas'][0]['codigo_cita']='EXISTENTE'; $bad['citas'][0]['estado']='atendida'; blocked(fn()=>DemoMigrationPlan::build($source,$bad,$now),'Exclusión médica también para citas atendidas');
$bad=$after; $bad['citas'][0]['codigo_cita']='EXISTENTE'; $bad['citas'][0]['id_medico']=999; blocked(fn()=>DemoMigrationPlan::build($source,$bad,$now),'Paciente no puede tener dos citas simultáneas');
echo $n." comprobaciones del plan pasaron: solo arrays, sin conexiones ni escrituras.\n";
