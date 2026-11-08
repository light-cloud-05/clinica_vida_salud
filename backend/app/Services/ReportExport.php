<?php
declare(strict_types=1);
namespace Clinica\Services;

final class ReportExport
{
    public static function csv(array $report): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('No se pudo preparar la exportación CSV.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Código','Fecha','Hora inicio','Hora fin','Paciente','Médico','Especialidad','Estado']);
        foreach ($report['appointments'] as $row) {
            fputcsv($stream, [$row['codigo_cita'],$row['fecha'],$row['hora_inicio'],$row['hora_fin'],$row['paciente_nombre'],$row['medico_nombre'],$row['especialidad_nombre'],$row['estado']]);
        }
        fputcsv($stream, []);
        fputcsv($stream, ['CARGA HORARIA']);
        fputcsv($stream, ['Médico','Horas semanales programadas','Horas programadas periodo','Horas ocupadas filtradas','Horas atendidas filtradas','Horas libres futuras agenda completa','Citas no canceladas filtradas','Citas atendidas filtradas']);
        foreach ($report['workload'] ?? [] as $row) {
            fputcsv($stream, [$row['medico_nombre'],$row['horas_semanales_programadas'],$row['horas_programadas_periodo'],$row['horas_ocupadas'],$row['horas_atendidas'],$row['horas_disponibles'],$row['citas_programadas'],$row['citas_atendidas']]);
        }
        fputcsv($stream, []);
        fputcsv($stream, ['CARGA POR ESPECIALIDAD: capacidad repartida por igual, estimada; ocupación y atención de citas reales']);
        fputcsv($stream, ['Especialidad','Horas semanales estimadas','Horas periodo estimadas','Horas ocupadas filtradas','Horas atendidas filtradas','Horas libres estimadas']);
        foreach ($report['specialty_workload'] ?? [] as $row) {
            fputcsv($stream, [$row['nombre'],$row['horas_semanales_programadas'],$row['horas_programadas_periodo'],$row['horas_ocupadas'],$row['horas_atendidas'],$row['horas_disponibles']]);
        }
        fputcsv($stream, ['Las horas atendidas están incluidas en las ocupadas. Libres futuras usa toda la agenda, sin restringir por estado o especialidad.']);
        fputcsv($stream, []);
        fputcsv($stream, ['BLOQUEOS / INDISPONIBILIDAD']);
        fputcsv($stream, ['Fecha','Médico','Hora inicio','Hora fin','Motivo']);
        foreach ($report['blocks'] ?? [] as $row) {
            fputcsv($stream, [$row['fecha'],$row['id_medico'],$row['hora_inicio'],$row['hora_fin'],$row['motivo'] ?? '']);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false) {
            throw new \RuntimeException('No se pudo leer la exportación CSV.');
        }
        return $csv;
    }

    public static function pdf(array $report, array $filters): string
    {
        $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $html = '<html><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:9px;color:#243b3a}h1{color:#176b66}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccd8d6;padding:4px;text-align:left}th{background:#e8f2f0}</style><h1>Clínica Vida Salud · Reporte administrativo</h1>';
        $html .= '<p>Filtros: '.$escape(json_encode($filters, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</p><table><thead><tr><th>Código</th><th>Fecha</th><th>Hora</th><th>Paciente</th><th>Médico</th><th>Especialidad</th><th>Estado</th></tr></thead><tbody>';
        foreach ($report['appointments'] as $row) {
            $html .= '<tr><td>'.$escape($row['codigo_cita']).'</td><td>'.$escape($row['fecha']).'</td><td>'.$escape($row['hora_inicio'].'–'.$row['hora_fin']).'</td><td>'.$escape($row['paciente_nombre']).'</td><td>'.$escape($row['medico_nombre']).'</td><td>'.$escape($row['especialidad_nombre']).'</td><td>'.$escape($row['estado']).'</td></tr>';
        }
        $html .= '</tbody></table>';
        if (!empty($report['workload'])) {
            $html .= '<h2>Carga horaria</h2><table><thead><tr><th>Médico</th><th>Horas semanales</th><th>Horas periodo</th><th>Ocupadas</th><th>Atendidas</th><th>Disponibles</th></tr></thead><tbody>';
            foreach ($report['workload'] as $row) {
                $html .= '<tr><td>'.$escape($row['medico_nombre']).'</td><td>'.$escape($row['horas_semanales_programadas']).'</td><td>'.$escape($row['horas_programadas_periodo']).'</td><td>'.$escape($row['horas_ocupadas']).'</td><td>'.$escape($row['horas_atendidas']).'</td><td>'.$escape($row['horas_disponibles']).'</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        if (!empty($report['specialty_workload'])) {
            $html .= '<h2>Carga por especialidad</h2><p>Capacidad programada y libre: estimación por reparto igual entre asociaciones. Ocupadas y atendidas: citas reales filtradas.</p><table><thead><tr><th>Especialidad</th><th>Horas periodo estimadas</th><th>Ocupadas</th><th>Atendidas</th><th>Libres estimadas</th></tr></thead><tbody>';
            foreach ($report['specialty_workload'] as $row) {
                $html .= '<tr><td>'.$escape($row['nombre']).'</td><td>'.$escape($row['horas_programadas_periodo']).'</td><td>'.$escape($row['horas_ocupadas']).'</td><td>'.$escape($row['horas_atendidas']).'</td><td>'.$escape($row['horas_disponibles']).'</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '<p>Las horas atendidas están incluidas en las ocupadas. Libres futuras usa toda la agenda y sus bloqueos. Las horas corresponden al periodo de carga: '.$escape(($report['planning_range']['from'] ?? 'sin datos').' — '.($report['planning_range']['to'] ?? 'sin datos')).'.</p>';
        $html .= '<p>'.$escape($report['absence_note'] ?? 'No existe control de asistencia laboral de médicos.').'</p></html>';
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $pdf = new \Dompdf\Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        return $pdf->output();
    }
}
