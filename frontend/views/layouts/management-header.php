<?php
declare(strict_types=1);

use Clinica\Services\SessionService;

if (!isset($user) || !is_array($user)) {
    throw new \RuntimeException('La plantilla de gestión requiere una identidad de usuario.');
}

$role = $user['role'] ?? null;
if (!is_string($role) || $role === '') {
    throw new \RuntimeException('La identidad de usuario no tiene un rol válido.');
}

$username = $user['username'] ?? null;
if (!is_string($username) || $username === '') {
    throw new \RuntimeException('La identidad de usuario no tiene un nombre válido.');
}

$roles = Clinica\Services\AuthService::roles();
if (!isset($roles[$role])) {
    throw new \RuntimeException('El rol de usuario no está configurado.');
}

$permissions = $user['permissions'] ?? [];
if (!is_array($permissions)) {
    throw new \RuntimeException('La identidad de usuario no tiene permisos válidos.');
}

$title = isset($title) && is_string($title) ? $title : 'Gestión';
$section = isset($section) && is_string($section) ? $section : '';
$sectionLabel = isset($sectionLabel) && is_string($sectionLabel) ? $sectionLabel : 'Gestión';
$success = isset($success) && is_string($success) ? $success : null;
$escape = isset($escape) && is_callable($escape)
    ? $escape
    : static fn(mixed $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
$panel = $roles[$role];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $escape($title) ?> · Clínica Vida Salud</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"><link rel="stylesheet" href="/assets/css/app.css"><script src="/assets/js/app.js" defer></script></head>
<body class="patient-app"><a class="visually-hidden-focusable" href="#main">Saltar al contenido</a>
<aside class="patient-sidebar"><a class="brand text-decoration-none" href="<?= $escape($panel) ?>">✚ Vida Salud<span>CLÍNICA · GESTIÓN</span></a><nav aria-label="Navegación de gestión"><a href="<?= $escape($panel) ?>">Inicio</a>
<?php $links=[];if(Clinica\Middleware\PatientAccess::allowed($user)){$links['pacientes']='Pacientes';}if(Clinica\Middleware\MedicalAccess::allowed($user)){$links['medicos']='Médicos';$links['especialidades']='Especialidades';}if(Clinica\Middleware\ScheduleAccess::view($user)){$links['horarios']='Horarios y agenda';} ?>
<?php if(Clinica\Middleware\CitaAccess::listing($user)){$links[ltrim(Clinica\Middleware\CitaAccess::base($user),'/')]=Clinica\Middleware\CitaAccess::personal($user) ? 'Mis citas' : 'Citas médicas';} if(Clinica\Middleware\PaymentAccess::view($user)){$links[$user['role']==='Paciente' ? 'mis-pagos' : 'pagos']='Pagos';} ?>
<?php if($role==='Paciente' && in_array('gestionar_citas_propias',$permissions,true)){$links['mis-notificaciones']='Notificaciones';} if($role==='Administrador' && in_array('consultar_auditoria',$permissions,true)){$links['notificaciones']='Estado de notificaciones';} ?>
<?php if(Clinica\Middleware\ReportAccess::canGenerate($user)){$links['reportes']='Reportes';} ?>
<?php if($role==='Administrador' && in_array('gestionar_usuarios',$permissions,true)){$links['usuarios']='Usuarios';} ?>
<?php if(Clinica\Middleware\AuditAccess::allowed($user)){$links['auditoria']='Auditoría';} ?>
<?php if(Clinica\Middleware\VoiceAccess::allowed($user)){$links['consultas-voz']='Consultas por voz';} ?>
<?php if($role==='Paciente' && in_array('gestionar_perfil_propio',$permissions,true)){$links['mi-perfil']='Mi perfil';} ?>
<?php foreach($links as $key=>$label): ?><a <?= $section===$key ? 'class="active" aria-current="page"' : '' ?> href="/<?= $key ?>"><?= $escape($label) ?></a><?php endforeach; ?></nav><p class="sidebar-foot">Atención con calidez.<br>Gestión con confianza.</p></aside>
<div class="patient-workspace"><header class="patient-topbar"><div><span class="eyebrow">CLÍNICA VIDA SALUD</span><p class="mb-0"><?= $escape($sectionLabel) ?></p></div><div class="account-tools"><span><?= $escape($username) ?><small><?= $escape($role) ?></small></span><form action="/logout" method="post"><input type="hidden" name="csrf" value="<?= $escape(SessionService::csrf()) ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">Cerrar sesión</button></form></div></header>
<main id="main" class="patient-main"><nav class="patient-breadcrumb" aria-label="Ubicación"><a href="<?= $escape($panel) ?>">Inicio</a> / <a href="/<?= $section ?>"><?= $escape($links[$section] ?? $title) ?></a><?php if($title!==($links[$section] ?? '')): ?> / <?= $escape($title) ?><?php endif; ?></nav>
<?php if(Clinica\Support\IntegrationGuard::active()): ?><div class="alert alert-warning" role="status"><strong>ENTORNO AISLADO DE PRUEBAS</strong> · clinica_vida_salud_test · PostgreSQL 55432. Las escrituras afectan solo a registros nuevos de esta copia. La base principal no se modifica.</div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success" role="status"><?= $escape($success) ?></div><?php endif; ?>
