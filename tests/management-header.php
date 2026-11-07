<?php
declare(strict_types=1);

require dirname(__DIR__).'/backend/bootstrap.php';

\Clinica\Services\SessionService::start();
$user = [
    'id' => 1,
    'username' => 'usuario-prueba',
    'role' => 'Paciente',
    'permissions' => ['gestionar_citas_propias', 'gestionar_perfil_propio'],
];
$title = 'Página de prueba';
$section = 'mis-citas';
$sectionLabel = 'Citas propias';
$success = null;
$escape = static fn(mixed $value): string => htmlspecialchars((string)($value ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');

ob_start();
require dirname(__DIR__).'/frontend/views/layouts/management-header.php';
$html = ob_get_clean();

if (!str_contains($html, '<title>Página de prueba · Clínica Vida Salud</title>')
    || !str_contains($html, 'Citas propias')
    || !str_contains($html, 'Mis citas')
    || !str_contains($html, 'Cerrar sesión')) {
    throw new RuntimeException('La cabecera no renderizó el contrato esperado.');
}

echo "Render de management-header verificado; títulos, menú y sesión presentes.\n";
