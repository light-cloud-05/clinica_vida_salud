<?php use Clinica\Services\PatientValidator; use Clinica\Services\SessionService; ?>
<div class="patient-page-heading"><div><span class="eyebrow text-secondary">DATOS ADMINISTRATIVOS</span><h1><?= $escape($title) ?></h1><p class="text-secondary mb-0">Los campos marcados con * son obligatorios.</p></div><a class="btn btn-outline-secondary" href="<?= $editing ? '/pacientes/detalle?id='.$escape($id) : '/pacientes' ?>">Cancelar</a></div>
<?php if(!$writesEnabled): ?><div class="alert alert-info" role="status">El guardado está temporalmente deshabilitado. Puede consultar y revisar el formulario sin cambiar los datos existentes.</div><?php endif; ?>
<?php if($errors): ?><div class="alert alert-danger" role="alert" tabindex="-1"><strong>No se guardaron cambios.</strong><ul class="mb-0"><?php foreach($errors as $message): ?><li><?= $escape($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" action="<?= $editing ? '/pacientes/editar?id='.$escape($id) : '/pacientes/nuevo' ?>" class="patient-card patient-form" data-patient-form>
<input type="hidden" name="csrf" value="<?= $escape(SessionService::csrf()) ?>"><?php if($editing): ?><input type="hidden" name="version" value="<?= $escape($version) ?>"><?php endif; ?>
<?php $groups=[
    'Identificación'=>['codigo_paciente','documento_identidad','nombres','apellidos','fecha_nacimiento','sexo','estado'],
    'Contacto'=>['telefono','correo_contacto','direccion'],
    'Contacto de emergencia'=>['contacto_emergencia_nombre','contacto_emergencia_parentesco','contacto_emergencia_telefono'],
]; ?>
<?php foreach($groups as $heading=>$fields): ?><fieldset><legend><?= $escape($heading) ?></legend><div class="row g-4">
<?php foreach($fields as $field): [$label,$max,$required]=PatientValidator::FIELDS[$field]; $error=$errors[$field] ?? null; ?><div class="<?= $field==='direccion' ? 'col-12' : 'col-md-6' ?>"><label class="form-label" for="<?= $field ?>"><?= $escape($label) ?><?= $required ? ' *' : '' ?></label>
<?php if($field==='estado'): ?><select id="estado" name="estado" class="form-select <?= $error ? 'is-invalid' : '' ?>" required <?= $error ? 'aria-invalid="true" aria-describedby="error-estado"' : '' ?>><?php foreach(['activo'=>'Activo','inactivo'=>'Inactivo'] as $value=>$labelState): ?><option value="<?= $value ?>" <?= ($values[$field] ?? '')===$value ? 'selected' : '' ?>><?= $labelState ?></option><?php endforeach; ?></select>
<?php elseif($field==='direccion'): ?><textarea id="direccion" name="direccion" rows="3" class="form-control <?= $error ? 'is-invalid' : '' ?>" <?= $error ? 'aria-invalid="true" aria-describedby="error-direccion"' : '' ?>><?= $escape($values[$field] ?? '') ?></textarea>
<?php else: $type=$field==='fecha_nacimiento' ? 'date' : ($field==='correo_contacto' ? 'email' : (in_array($field,['telefono','contacto_emergencia_telefono'],true) ? 'tel' : 'text')); ?><input id="<?= $field ?>" name="<?= $field ?>" type="<?= $type ?>" class="form-control <?= $error ? 'is-invalid' : '' ?>" value="<?= $escape($values[$field] ?? '') ?>" <?= $max!==null ? 'maxlength="'.$max.'"' : '' ?> <?= $required ? 'required' : '' ?> <?= $error ? 'aria-invalid="true" aria-describedby="error-'.$field.'"' : '' ?>><?php endif; ?>
<?php if($error): ?><div id="error-<?= $field ?>" class="invalid-feedback"><?= $escape($error) ?></div><?php endif; ?>
<?php if($field==='codigo_paciente' && !$editing): ?><div class="form-text">Ingrese el código asignado al paciente. Debe ser único.</div><?php endif; ?>
</div><?php endforeach; ?></div></fieldset><?php endforeach; ?>
<div class="patient-form-actions"><a class="btn btn-outline-secondary" href="<?= $editing ? '/pacientes/detalle?id='.$escape($id) : '/pacientes' ?>">Cancelar</a><button type="submit" class="btn btn-clinic" <?= !$writesEnabled ? 'disabled' : '' ?>><?= $editing ? 'Guardar cambios' : 'Registrar paciente' ?></button></div></form>
