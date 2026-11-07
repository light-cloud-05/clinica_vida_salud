<?php
use Clinica\Services\SessionService;
$panel=Clinica\Services\AuthService::roles()[$user['role']];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $escape($title) ?> · Clínica Vida Salud</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"><link rel="stylesheet" href="/assets/css/app.css"><script src="/assets/js/app.js" defer></script></head>
<body class="patient-app"><a class="visually-hidden-focusable" href="#main">Saltar al contenido</a>
<aside class="patient-sidebar"><a class="brand text-decoration-none" href="<?= $escape($panel) ?>">✚ Vida Salud<span>CLÍNICA · GESTIÓN</span></a><nav aria-label="Navegación de gestión"><a href="<?= $escape($panel) ?>">Inicio</a>
<?php $links=[];if(Clinica\Middleware\PatientAccess::allowed($user)){$links['pacientes']='Pacientes';}if(Clinica\Middleware\MedicalAccess::allowed($user)){$links['medicos']='Médicos';$links['especialidades']='Especialidades';}if(Clinica\Middleware\ScheduleAccess::view($user)){$links['horarios']='Horarios y agenda';} ?>
<?php if(Clinica\Middleware\CitaAccess::listing($user)){$links[ltrim(Clinica\Middleware\CitaAccess::base($user),'/')]=Clinica\Middleware\CitaAccess::personal($user) ? 'Mis citas' : 'Citas médicas';} ?>
<?php foreach($links as $key=>$label): ?><a <?= $section===$key ? 'class="active" aria-current="page"' : '' ?> href="/<?= $key ?>"><?= $escape($label) ?></a><?php endforeach; ?></nav><p class="sidebar-foot">Atención con calidez.<br>Gestión con confianza.</p></aside>
<div class="patient-workspace"><header class="patient-topbar"><div><span class="eyebrow">CLÍNICA VIDA SALUD</span><p class="mb-0"><?= $escape($sectionLabel) ?></p></div><div class="account-tools"><span><?= $escape($user['username']) ?><small><?= $escape($user['role']) ?></small></span><form action="/logout" method="post"><input type="hidden" name="csrf" value="<?= $escape(SessionService::csrf()) ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">Cerrar sesión</button></form></div></header>
<main id="main" class="patient-main"><nav class="patient-breadcrumb" aria-label="Ubicación"><a href="<?= $escape($panel) ?>">Inicio</a> / <a href="/<?= $section ?>"><?= $escape($links[$section] ?? $title) ?></a><?php if($title!==($links[$section] ?? '')): ?> / <?= $escape($title) ?><?php endif; ?></nav>
<?php if($success): ?><div class="alert alert-success" role="status"><?= $escape($success) ?></div><?php endif; ?>
