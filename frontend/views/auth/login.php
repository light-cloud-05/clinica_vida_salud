<?php
use Clinica\Services\SessionService;
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión · Clínica Vida Salud</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="/assets/css/app.css"><script src="/assets/js/app.js" defer></script>
</head>
<body class="login-page">
<main class="login-shell">
    <section class="login-story" aria-labelledby="brand-title">
        <a href="/" class="login-brand">✚ <span>Clínica Vida Salud</span></a>
        <div class="story-content"><span class="eyebrow">CERCA DE TI, EN CADA PASO</span><h1 id="brand-title">Tu bienestar<br>comienza aquí.</h1><p>Un espacio para conectar con tu salud y acompañar cada atención.</p><div class="care-mark" aria-hidden="true">✚</div></div>
        <p class="story-foot">Atención con calidez. Gestión con confianza.</p>
    </section>
    <section class="login-form-area" aria-labelledby="login-title">
        <div class="login-form-inner"><span class="eyebrow text-secondary">BIENVENIDO A TU ESPACIO</span><h2 id="login-title">Iniciar sesión</h2><p class="text-secondary mb-4">Ingresa con tu cuenta de Clínica Vida Salud.</p>
        <?php if (isset($_GET['salida']) && $_GET['salida'] === '1'): ?><div class="alert alert-success" role="status">Has cerrado sesión correctamente.</div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $escape($error) ?></div><?php endif; ?>
        <form method="post" action="/login">
            <input type="hidden" name="csrf" value="<?= $escape(SessionService::csrf()) ?>">
            <div class="mb-4"><label for="identifier" class="form-label">Usuario o correo electrónico</label><input id="identifier" name="identifier" type="text" class="form-control form-control-lg" autocomplete="username" maxlength="160" required value="<?= $escape($identifier) ?>" autofocus></div>
            <div class="mb-4"><label for="password" class="form-label">Contraseña</label><div class="password-field"><input id="password" name="password" type="password" class="form-control form-control-lg" autocomplete="current-password" required aria-describedby="password-help"><button type="button" class="password-toggle" data-toggle-password="password" aria-controls="password" aria-pressed="false">Mostrar</button></div><small id="password-help" class="text-secondary">Usa tu contraseña personal. No la compartas.</small></div>
            <button type="submit" class="btn btn-clinic btn-lg w-100">Entrar a mi cuenta <span aria-hidden="true">→</span></button>
        </form>
        <p class="login-support">¿Necesitas ayuda para acceder?<br>Comunícate con la administración de la clínica.</p><a href="/" class="back-link">← Volver al inicio</a>
        </div>
    </section>
</main>
</body></html>
