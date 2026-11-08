<?php
$icon = static function (string $name): string {
    $paths = [
        'cross'=>'<path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6z"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18m-13 5 3 3 5-5"/>',
        'doctor'=>'<path d="M6 3v6a5 5 0 0 0 10 0V3M4 3h4m6 0h4m-7 11v3a4 4 0 0 0 8 0v-3"/><circle cx="19" cy="11" r="3"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'arrow'=>'<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths[$name].'</svg>';
};
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Clínica Vida Salud: consulta la disponibilidad, elige tu médico y organiza tus citas desde tu cuenta de paciente. Demostración académica.">
    <title>Clínica Vida Salud · Tu atención, mejor organizada</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="/assets/css/home.css">
</head>
<body class="welcome-page">
<a class="welcome-skip" href="#contenido">Saltar al contenido</a>
<header class="welcome-header">
    <nav class="welcome-container welcome-nav" aria-label="Navegación principal">
        <a class="welcome-brand" href="/" aria-label="Clínica Vida Salud, inicio"><span class="welcome-mark"><?= $icon('cross') ?></span><span>Vida Salud<small>CLÍNICA MÉDICA</small></span></a>
        <div class="welcome-links"><a href="#atencion">Nuestra atención</a><a href="#como-reservar">Cómo reservar</a><a href="#horarios">Horarios</a></div>
        <a class="welcome-login" href="/login">Iniciar sesión <?= $icon('arrow') ?></a>
    </nav>
</header>
<main id="contenido">
    <section class="welcome-hero">
        <div class="welcome-container welcome-hero-grid">
            <div class="welcome-intro">
                <span class="welcome-kicker"><span></span> BIENVENIDO A CLÍNICA VIDA SALUD</span>
                <h1>Tu atención,<br><em>mejor organizada.</em></h1>
                <p>Encuentra un horario para ti. Consulta la disponibilidad, elige tu médico y gestiona tus citas desde un mismo lugar.</p>
                <div class="welcome-actions"><a class="welcome-button welcome-primary" href="/mis-citas/reservar"><?= $icon('calendar') ?> Reservar cita <?= $icon('arrow') ?></a><a class="welcome-button welcome-secondary" href="/registro">Registrarme como paciente</a></div>
                <p class="welcome-helper">Para reservar, inicia sesión con tu cuenta de paciente.</p>
                <div class="welcome-hero-foot"><span><?= $icon('clock') ?> Recepción · Lun–Vie, 08:00–18:00</span><span>Hora de Bolivia</span></div>
            </div>
            <div class="welcome-art" aria-hidden="true">
                <svg class="welcome-building" viewBox="0 0 520 470" fill="none">
                    <defs><linearGradient id="clinic-background" x1="70" y1="50" x2="450" y2="450" gradientUnits="userSpaceOnUse"><stop stop-color="#d9f7f4"/><stop offset="1" stop-color="#b8dfec"/></linearGradient><linearGradient id="clinic-glass" x1="100" y1="180" x2="360" y2="400" gradientUnits="userSpaceOnUse"><stop stop-color="#83becf"/><stop offset="1" stop-color="#d2edf0"/></linearGradient></defs>
                    <rect x="25" y="25" width="470" height="420" rx="110" fill="url(#clinic-background)"/>
                    <circle cx="425" cy="89" r="30" fill="#fff" opacity=".55"/>
                    <path d="M50 373h420" stroke="#8ec5ce" stroke-width="2"/>
                    <rect x="103" y="169" width="314" height="208" rx="12" fill="#eef9fc"/>
                    <rect x="180" y="99" width="160" height="278" rx="14" fill="white"/>
                    <rect x="207" y="123" width="106" height="75" rx="14" fill="#e6f5f8"/>
                    <path d="M252 137h16v15h15v16h-15v15h-16v-15h-15v-16h15z" fill="#168c9e"/>
                    <path d="M124 201h36v45h-36zm0 66h36v45h-36zm236-66h36v45h-36zm0 66h36v45h-36z" fill="url(#clinic-glass)"/>
                    <rect x="209" y="226" width="102" height="151" rx="5" fill="url(#clinic-glass)"/>
                    <path d="M260 227v149m-50-100h100m-100 48h100" stroke="white" stroke-width="5"/>
                    <rect x="190" y="372" width="140" height="12" rx="6" fill="#6bafbc"/>
                    <path d="M72 377v-76m375 76v-76" stroke="#538e9a" stroke-width="5"/>
                    <ellipse cx="72" cy="282" rx="26" ry="46" fill="#5cafa2"/><ellipse cx="447" cy="282" rx="26" ry="46" fill="#5cafa2"/>
                    <path d="M70 414h380" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-dasharray="12 15"/>
                </svg>
                <div class="welcome-art-note"><span><?= $icon('calendar') ?></span><div>Un espacio para tu atención<small>Agenda y disponibilidad en línea</small></div></div>
                <span class="welcome-art-cross"><?= $icon('cross') ?></span>
            </div>
        </div>
    </section>
    <section id="atencion" class="welcome-section welcome-container" aria-labelledby="atencion-titulo">
        <div class="welcome-section-heading"><div><span class="welcome-kicker">ATENCIÓN CONECTADA</span><h2 id="atencion-titulo">Todo empieza con una cita.</h2></div><p>Información clara para elegir y organizar tu próxima consulta.</p></div>
        <div class="welcome-service-grid">
            <article class="welcome-service"><span class="welcome-icon"><?= $icon('cross') ?></span><h3>Especialidades</h3><p>Consulta las especialidades del catálogo al buscar disponibilidad y selecciona la que necesitas.</p><a href="/mis-citas/disponibilidad">Consultar disponibilidad <?= $icon('arrow') ?></a></article>
            <article class="welcome-service"><span class="welcome-icon"><?= $icon('doctor') ?></span><h3>Médicos</h3><p>Elige un profesional asociado a la especialidad y revisa sus horarios disponibles para reservar.</p><a href="/mis-citas/disponibilidad">Buscar un horario <?= $icon('arrow') ?></a></article>
            <article class="welcome-service"><span class="welcome-icon"><?= $icon('calendar') ?></span><h3>Tus citas, a mano</h3><p>Revisa tus reservas, confirma tu asistencia y consulta las opciones de reprogramación o cancelación.</p><a href="/mis-citas">Ir a mis citas <?= $icon('arrow') ?></a></article>
        </div>
    </section>
    <section id="como-reservar" class="welcome-process" aria-labelledby="reservar-titulo"><div class="welcome-container">
        <div class="welcome-section-heading"><div><span class="welcome-kicker">PASO A PASO</span><h2 id="reservar-titulo">De la disponibilidad a tu agenda.</h2></div><a class="welcome-text-link" href="/registro">Crear mi cuenta <?= $icon('arrow') ?></a></div>
        <ol class="welcome-steps"><li><span>01</span><h3>Ingresa a tu cuenta</h3><p>Inicia sesión o regístrate como paciente para acceder a tu espacio personal.</p></li><li><span>02</span><h3>Elige tu consulta</h3><p>Selecciona especialidad, médico y una franja disponible dentro de los próximos diez días hábiles.</p></li><li><span>03</span><h3>Revisa tu reserva</h3><p>Completa el proceso y consulta la confirmación y los detalles desde Mis citas.</p></li></ol>
    </div></section>
    <section id="horarios" class="welcome-container welcome-section" aria-labelledby="horario-titulo"><div class="welcome-hours">
        <div><span class="welcome-kicker">ORGANIZA TU VISITA</span><h2 id="horario-titulo">Recepción y caja</h2><p>Los horarios de consulta dependen de la agenda de cada médico. Revisa la disponibilidad antes de reservar.</p></div>
        <div class="welcome-hours-detail"><?= $icon('clock') ?><div><strong>Lunes a viernes</strong><span>08:00 — 18:00</span><small>Hora de Bolivia · America/La_Paz</small></div></div>
    </div></section>
</main>
<footer class="welcome-footer"><div class="welcome-container"><a class="welcome-brand" href="/"><span class="welcome-mark"><?= $icon('cross') ?></span><span>Vida Salud<small>CLÍNICA MÉDICA</small></span></a><p>Demostración académica con datos ficticios.</p><a href="/login">Acceder al sistema <?= $icon('arrow') ?></a></div></footer>
</body></html>
