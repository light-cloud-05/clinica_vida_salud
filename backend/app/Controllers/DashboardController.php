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
        $overview = null;
        $upcoming = [];
        if (\Clinica\Middleware\ReportAccess::canGenerate($user)) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('America/La_Paz'));
            $window = \Clinica\Services\DisponibilidadService::window($now);
            $overview = \Clinica\Support\ReportFactory::service()->dashboard($user, ['fecha_inicio'=>$now->format('Y-m-d'),'fecha_fin'=>end($window)])['report'];
            $upcoming = array_slice(array_values(array_filter($overview['appointments'], static fn(array $row): bool =>
                in_array($row['estado'], ['programada','confirmada'], true)
                && new \DateTimeImmutable($row['fecha'].' '.$row['hora_inicio'], $now->getTimezone()) > $now
            )), 0, 6);
        }
        require dirname(__DIR__, 3) . '/frontend/views/dashboard.php';
    }
}
