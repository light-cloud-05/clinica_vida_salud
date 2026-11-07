<?php
declare(strict_types=1);
namespace Clinica\Controllers;

final class DashboardController
{
    public function index(array $user): void
    {
        // Solo identidad de la sesión validada: no recibe id_usuario desde URL/formulario.
        $copy = [
            'Administrador'=>['Administración', 'Tu espacio para supervisar la gestión de la clínica.'],
            'Recepcionista'=>['Recepción', 'Tu espacio para acompañar la atención de nuestros pacientes.'],
            'Medico'=>['Área médica', 'Tu espacio de trabajo para la atención médica.'],
            'Paciente'=>['Mi espacio de salud', 'Bienvenido a tu espacio personal de Clínica Vida Salud.'],
        ];
        [$title, $description] = $copy[$user['role']];
        require dirname(__DIR__, 3) . '/frontend/views/dashboard.php';
    }
}
