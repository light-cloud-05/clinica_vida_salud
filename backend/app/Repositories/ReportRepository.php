<?php
declare(strict_types=1);
namespace Clinica\Repositories;

use Clinica\Services\DisponibilidadService;
use Clinica\Services\ScheduleValidator;

final class ReportRepository implements ReportStore
{
    public function __construct(private \PDO $pdo) {}

    public function choices(): array
    {
        return [
            'doctors' => $this->pdo->query("SELECT id_medico,nombres||' '||apellidos AS nombre,estado FROM public.medicos ORDER BY apellidos,nombres,id_medico")->fetchAll(),
            'specialties' => $this->pdo->query('SELECT id_especialidad,nombre FROM public.especialidades ORDER BY nombre,id_especialidad')->fetchAll(),
        ];
    }

    private function filtered(array $filters): array
    {
        $where = ['TRUE'];
        $params = [];
        if (isset($filters['_scope'])) {
            $patient = $filters['_scope']['role'] === 'Paciente';
            $where[] = $patient
                ? 'c.id_paciente IN (SELECT id_paciente FROM public.pacientes WHERE id_usuario=:scope_user)'
                : 'c.id_medico IN (SELECT id_medico FROM public.medicos WHERE id_usuario=:scope_user)';
            $params['scope_user'] = $filters['_scope']['user_id'];
        }
        if ($filters['fecha_inicio'] !== null) {
            $where[] = 'c.fecha>=:fecha_inicio';
            $params['fecha_inicio'] = $filters['fecha_inicio'];
        }
        if ($filters['fecha_fin'] !== null) {
            $where[] = 'c.fecha<=:fecha_fin';
            $params['fecha_fin'] = $filters['fecha_fin'];
        }
        if ($filters['medico'] !== null) {
            $where[] = 'c.id_medico=:medico';
            $params['medico'] = $filters['medico'];
        }
        if ($filters['especialidad'] !== null) {
            $where[] = 'c.id_especialidad=:especialidad';
            $params['especialidad'] = $filters['especialidad'];
        }
        if ($filters['estado'] !== null) {
            $where[] = 'c.estado=:estado';
            $params['estado'] = $filters['estado'];
        }
        return ['WITH filtered AS (SELECT c.id_cita,c.codigo_cita,c.fecha,c.hora_inicio,c.hora_fin,c.id_paciente,c.id_medico,c.id_especialidad,c.estado FROM public.citas c WHERE '.implode(' AND ', $where).')', $params];
    }

    public function dashboard(array $filters): array
    {
        [$cte, $params] = $this->filtered($filters);
        $statement = $this->pdo->prepare($cte." SELECT f.id_cita,f.id_paciente,f.id_medico,f.id_especialidad,f.codigo_cita,f.fecha,f.hora_inicio,f.hora_fin,f.estado,
            p.nombres||' '||p.apellidos AS paciente_nombre,
            m.nombres||' '||m.apellidos AS medico_nombre,e.nombre AS especialidad_nombre
            FROM filtered f JOIN public.pacientes p ON p.id_paciente=f.id_paciente
            JOIN public.medicos m ON m.id_medico=f.id_medico
            JOIN public.especialidades e ON e.id_especialidad=f.id_especialidad
            ORDER BY f.fecha,f.hora_inicio,f.id_cita");
        $statement->execute($params);
        $appointments = $statement->fetchAll();

        $statement = $this->pdo->prepare($cte." SELECT m.id_medico,m.nombres||' '||m.apellidos AS medico_nombre,
            COALESCE((SELECT string_agg(e2.nombre, ', ' ORDER BY e2.nombre)
                FROM public.medico_especialidad me2 JOIN public.especialidades e2 ON e2.id_especialidad=me2.id_especialidad
                WHERE me2.id_medico=m.id_medico),'') AS especialidades,
            count(f.id_cita) AS total,
            count(f.id_cita) FILTER (WHERE f.estado='programada') AS programadas,
            count(f.id_cita) FILTER (WHERE f.estado='confirmada') AS confirmadas,
            count(f.id_cita) FILTER (WHERE f.estado='atendida') AS atendidas,
            count(f.id_cita) FILTER (WHERE f.estado='cancelada') AS canceladas,
            count(f.id_cita) FILTER (WHERE f.estado='inasistencia') AS inasistencias
            FROM public.medicos m LEFT JOIN filtered f ON f.id_medico=m.id_medico
            GROUP BY m.id_medico,m.nombres,m.apellidos ORDER BY m.apellidos,m.nombres,m.id_medico");
        $statement->execute($params);
        $doctors = $statement->fetchAll();

        $statement = $this->pdo->prepare($cte." SELECT e.id_especialidad,e.nombre,
            count(DISTINCT me.id_medico) AS medicos,count(DISTINCT f.id_cita) AS citas
            FROM public.especialidades e
            LEFT JOIN public.medico_especialidad me ON me.id_especialidad=e.id_especialidad
            LEFT JOIN filtered f ON f.id_especialidad=e.id_especialidad
            GROUP BY e.id_especialidad,e.nombre ORDER BY e.nombre,e.id_especialidad");
        $statement->execute($params);
        $specialties = $statement->fetchAll();

        $statement = $this->pdo->prepare($cte." SELECT count(*) AS total,
            count(*) FILTER (WHERE estado='atendida') AS atendidas,
            count(*) FILTER (WHERE estado='inasistencia') AS inasistencias,
            count(*) FILTER (WHERE estado='cancelada') AS canceladas
            FROM filtered");
        $statement->execute($params);
        $attendance = $statement->fetch() ?: ['total'=>0,'atendidas'=>0,'inasistencias'=>0,'canceladas'=>0];

        $summary = [
            'pacientes' => (int)$this->pdo->query('SELECT count(*) FROM public.pacientes')->fetchColumn(),
            'medicos' => (int)$this->pdo->query('SELECT count(*) FROM public.medicos')->fetchColumn(),
            'especialidades' => (int)$this->pdo->query('SELECT count(*) FROM public.especialidades')->fetchColumn(),
            'citas' => count($appointments),
        ];
        if (isset($filters['_scope'])) {
            $statement = $this->pdo->prepare($cte.' SELECT count(DISTINCT id_paciente) AS pacientes,count(DISTINCT id_medico) AS medicos,count(DISTINCT id_especialidad) AS especialidades FROM filtered');
            $statement->execute($params);
            $totals = $statement->fetch();
            foreach (['pacientes','medicos','especialidades'] as $field) { $summary[$field] = (int)($totals[$field] ?? 0); }
            $doctors = array_values(array_filter($doctors, static fn(array $row): bool => (int)$row['total'] > 0));
            $specialties = array_values(array_filter($specialties, static fn(array $row): bool => (int)$row['citas'] > 0));
            foreach ($specialties as &$specialty) {
                $ids = [];
                foreach ($appointments as $appointment) {
                    if ((int)$appointment['id_especialidad'] === (int)$specialty['id_especialidad']) { $ids[$appointment['id_medico']] = true; }
                }
                $specialty['medicos'] = count($ids);
            }
            unset($specialty);
        }
        $planning = $this->planning($filters, $appointments);
        return compact('appointments','doctors','specialties','attendance','summary') + ['scope_label'=>isset($filters['_scope'])?'Resultados personales':'Catálogo global'] + $planning;
    }

    private function planning(array $filters, array $appointments): array
    {
        $choices = $this->choices();
        $selectedDoctor = $filters['medico'];
        $doctorRows = $choices['doctors'];
        if (isset($filters['_scope'])) {
            if ($filters['_scope']['role'] === 'Paciente') {
                return ['daily'=>[], 'weekly'=>[], 'workload'=>[], 'specialty_workload'=>[], 'blocks'=>[], 'conflicts'=>[], 'planning_range'=>[]];
            }
            $statement = $this->pdo->prepare('SELECT id_medico FROM public.medicos WHERE id_usuario=:scope_user');
            $statement->execute(['scope_user'=>$filters['_scope']['user_id']]);
            $own = array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
            $doctorRows = array_values(array_filter($doctorRows, static fn(array $row): bool => in_array((int)$row['id_medico'], $own, true)));
        }
        if ($selectedDoctor !== null) {
            $doctorRows = array_values(array_filter($doctorRows, static fn(array $row): bool => (int)$row['id_medico'] === $selectedDoctor));
        }
        $doctorIds = array_map(static fn(array $row): int => (int)$row['id_medico'], $doctorRows);
        $specialtyByDoctor = $this->specialtiesByDoctor($doctorIds);
        if ($filters['especialidad'] !== null) {
            $doctorRows = array_values(array_filter($doctorRows, fn(array $doctor): bool => in_array($filters['especialidad'], $specialtyByDoctor[(int)$doctor['id_medico']] ?? [], true)));
            $doctorIds = array_map(static fn(array $row): int => (int)$row['id_medico'], $doctorRows);
        }
        if (!$doctorIds) {
            return ['daily'=>[], 'weekly'=>[], 'workload'=>[], 'specialty_workload'=>[], 'blocks'=>[], 'conflicts'=>[], 'planning_range'=>[]];
        }

        [$from, $to] = $this->planningRange($filters);
        $hours = $this->readRows(
            'SELECT id_horario,id_medico,dia_semana,hora_inicio,hora_fin,duracion_cita_minutos,estado
             FROM public.horarios_atencion WHERE id_medico = ANY(CAST(:doctors AS integer[])) ORDER BY id_medico,dia_semana,hora_inicio,id_horario',
            ['doctors'=>'{'.implode(',', $doctorIds).'}']
        );
        $blocks = $this->readRows(
            'SELECT id_bloqueo,id_medico,fecha,hora_inicio,hora_fin,motivo
             FROM public.bloqueos_horario WHERE id_medico = ANY(CAST(:doctors AS integer[])) AND fecha BETWEEN :from AND :to
             ORDER BY fecha,hora_inicio,id_medico',
            ['doctors'=>'{'.implode(',', $doctorIds).'}','from'=>$from,'to'=>$to]
        );
        $planningAppointments = $this->readRows(
            'SELECT id_cita,id_medico,id_especialidad,fecha,hora_inicio,hora_fin,estado
             FROM public.citas WHERE id_medico = ANY(CAST(:doctors AS integer[])) AND fecha BETWEEN :from AND :to
             ORDER BY fecha,hora_inicio,id_cita',
            ['doctors'=>'{'.implode(',', $doctorIds).'}','from'=>$from,'to'=>$to]
        );

        $doctorsById = [];
        foreach ($doctorRows as $doctor) {
            $doctorsById[(int)$doctor['id_medico']] = $doctor;
        }
        $daily = [];
        $weekly = [];
        $workload = [];
        $conflicts = [];
        $availableByDoctor = [];
        foreach ($this->dates($from, $to) as $date) {
            $dateKey = $date->format('Y-m-d');
            foreach ($doctorRows as $doctor) {
                $doctorId = (int)$doctor['id_medico'];
                $dayData = (new DisponibilidadService())->day(
                    $doctorsById[$doctorId],
                    $dateKey,
                    $hours,
                    $blocks,
                    $planningAppointments,
                    new \DateTimeImmutable('now', new \DateTimeZone('America/La_Paz'))
                );
                $dayAppointments = array_values(array_filter(
                    $planningAppointments,
                    fn(array $row): bool => (int)$row['id_medico'] === $doctorId
                        && $row['fecha'] === $dateKey
                        && ($filters['especialidad'] === null || (int)$row['id_especialidad'] === $filters['especialidad'])
                        && ($filters['estado'] === null || $row['estado'] === $filters['estado'])
                ));
                $activeAppointments = array_values(array_filter($dayAppointments, static fn(array $row): bool => $row['estado'] !== 'cancelada'));
                $available = array_values(array_filter($dayData['franjas'], static fn(array $slot): bool => $slot['estado'] === 'disponible'));
                $conflictSlots = array_values(array_filter($dayData['franjas'], static fn(array $slot): bool => str_contains($slot['estado'], 'conflicto')));
                if ($activeAppointments || $dayData['horarios'] || $dayData['bloqueos']) {
                    $daily[] = [
                        'fecha'=>$dateKey, 'id_medico'=>$doctorId, 'medico_nombre'=>$doctor['nombre'],
                        'citas'=>count($dayAppointments), 'activas'=>count($activeAppointments),
                        'programadas'=>$this->countState($dayAppointments, 'programada'),
                        'confirmadas'=>$this->countState($dayAppointments, 'confirmada'),
                        'atendidas'=>$this->countState($dayAppointments, 'atendida'),
                        'canceladas'=>$this->countState($dayAppointments, 'cancelada'),
                        'inasistencias'=>$this->countState($dayAppointments, 'inasistencia'),
                        'horas_programadas'=>$this->hoursForSchedules($dayData['horarios']),
                        'horas_ocupadas'=>$this->hoursForAppointments($activeAppointments),
                        'horas_atendidas'=>$this->hoursForAppointments(array_values(array_filter($activeAppointments, static fn(array $row): bool => $row['estado'] === 'atendida'))),
                        'espacios_disponibles'=>count($available),
                        'espacios_programados'=>count($dayData['franjas']),
                    ];
                }
                $availableByDoctor[$doctorId] = ($availableByDoctor[$doctorId] ?? 0) + $this->hoursForAppointments($available);
                foreach ($conflictSlots as $slot) {
                    $conflicts[] = ['tipo'=>'conflicto_horario','fecha'=>$dateKey,'id_medico'=>$doctorId,'medico_nombre'=>$doctor['nombre'],'detalle'=>$slot['hora_inicio'].'–'.$slot['hora_fin']];
                }
            }
        }
        foreach ($daily as $row) {
            $week = ScheduleValidator::date($row['fecha'])->modify('-'.((int)ScheduleValidator::date($row['fecha'])->format('N') - 1).' days')->format('Y-m-d');
            $key = $row['id_medico'].'|'.$week;
            if (!isset($weekly[$key])) {
                $weekly[$key] = ['semana_inicio'=>$week,'id_medico'=>$row['id_medico'],'medico_nombre'=>$row['medico_nombre'],
                    'citas'=>0,'activas'=>0,'programadas'=>0,'confirmadas'=>0,'atendidas'=>0,'canceladas'=>0,'inasistencias'=>0,
                    'horas_programadas'=>0.0,'horas_ocupadas'=>0.0,'horas_atendidas'=>0.0,'espacios_disponibles'=>0,'espacios_programados'=>0];
            }
            foreach (['citas','activas','programadas','confirmadas','atendidas','canceladas','inasistencias','espacios_disponibles','espacios_programados'] as $field) {
                $weekly[$key][$field] += (int)$row[$field];
            }
            foreach (['horas_programadas','horas_ocupadas','horas_atendidas'] as $field) {
                $weekly[$key][$field] += (float)$row[$field];
            }
        }
        foreach ($doctorRows as $doctor) {
            $id = (int)$doctor['id_medico'];
            $doctorHours = array_values(array_filter($hours, static fn(array $row): bool => (int)$row['id_medico'] === $id && $row['estado'] === 'activo'));
            $periodHours = 0.0;
            foreach ($this->dates($from, $to) as $date) {
                $periodHours += $this->hoursForSchedules(array_values(array_filter($doctorHours, static fn(array $row): bool => (int)$row['dia_semana'] === (int)$date->format('N'))));
            }
            $doctorAppointments = array_values(array_filter($planningAppointments, static fn(array $row): bool => (int)$row['id_medico'] === $id && $row['estado'] !== 'cancelada'
                && ($filters['especialidad'] === null || (int)$row['id_especialidad'] === $filters['especialidad'])
                && ($filters['estado'] === null || $row['estado'] === $filters['estado'])));
            $workload[] = [
                'id_medico'=>$id, 'medico_nombre'=>$doctor['nombre'],
                'horas_semanales_programadas'=>$this->hoursForSchedules($doctorHours),
                'horas_programadas_periodo'=>$periodHours,
                'horas_ocupadas'=>$this->hoursForAppointments($doctorAppointments),
                'horas_atendidas'=>$this->hoursForAppointments(array_values(array_filter($doctorAppointments, static fn(array $row): bool => $row['estado'] === 'atendida'))),
                'horas_disponibles'=>round($availableByDoctor[$id] ?? 0.0, 2),
                'citas_programadas'=>count($doctorAppointments),
                'citas_atendidas'=>$this->countState($doctorAppointments, 'atendida'),
            ];
        }
        $specialty_workload = [];
        foreach ($choices['specialties'] as $specialty) {
            $specialtyId = (int)$specialty['id_especialidad'];
            if ($filters['especialidad'] !== null && $specialtyId !== $filters['especialidad']) {
                continue;
            }
            $associated = array_values(array_filter($doctorRows, fn(array $doctor): bool => in_array($specialtyId, $specialtyByDoctor[(int)$doctor['id_medico']] ?? [], true)));
            if (!$associated) {
                continue;
            }
            $hoursAllocated = 0.0;
            $periodAllocated = 0.0;
            $availableAllocated = 0.0;
            foreach ($associated as $doctor) {
                $doctorWorkload = $workload[array_search((int)$doctor['id_medico'], array_column($workload, 'id_medico'), true)];
                $countSpecialties = max(1, count($specialtyByDoctor[(int)$doctor['id_medico']] ?? []));
                $hoursAllocated += (float)$doctorWorkload['horas_semanales_programadas'] / $countSpecialties;
                $periodAllocated += (float)$doctorWorkload['horas_programadas_periodo'] / $countSpecialties;
                $availableAllocated += (float)$doctorWorkload['horas_disponibles'] / $countSpecialties;
            }
            $specialtyAppointments = array_values(array_filter($planningAppointments, static fn(array $row): bool => (int)$row['id_especialidad'] === $specialtyId
                && ($filters['estado'] === null || $row['estado'] === $filters['estado'])));
            $occupied = array_values(array_filter($specialtyAppointments, static fn(array $row): bool => $row['estado'] !== 'cancelada'));
            $specialty_workload[] = ['nombre'=>$specialty['nombre'],'id_especialidad'=>$specialtyId,
                'horas_semanales_programadas'=>round($hoursAllocated, 2),
                'horas_programadas_periodo'=>round($periodAllocated, 2),
                'horas_ocupadas'=>$this->hoursForAppointments($occupied),
                'horas_atendidas'=>$this->hoursForAppointments(array_values(array_filter($occupied, static fn(array $row): bool => $row['estado'] === 'atendida'))),
                'horas_disponibles'=>round($availableAllocated, 2),
                'citas_programadas'=>count(array_filter($specialtyAppointments, static fn(array $row): bool => $row['estado'] !== 'cancelada')),
                'citas_atendidas'=>$this->countState($specialtyAppointments, 'atendida')];
        }
        $overlapAppointments = array_values(array_filter($planningAppointments, static fn(array $row): bool => $row['estado'] !== 'cancelada'));
        foreach ($doctorIds as $doctorId) {
            $rows = array_values(array_filter($overlapAppointments, static fn(array $row): bool => (int)$row['id_medico'] === $doctorId));
            for ($i=0; $i<count($rows); $i++) {
                for ($j=$i+1; $j<count($rows); $j++) {
                    if ($rows[$i]['fecha'] === $rows[$j]['fecha'] && DisponibilidadService::overlaps($rows[$i], $rows[$j])) {
                        $conflicts[] = ['tipo'=>'citas_solapadas','fecha'=>$rows[$i]['fecha'],'id_medico'=>$doctorId,
                            'medico_nombre'=>$doctorsById[$doctorId]['nombre'],'detalle'=>$rows[$i]['hora_inicio'].'–'.$rows[$i]['hora_fin'].' / '.$rows[$j]['hora_inicio'].'–'.$rows[$j]['hora_fin']];
                    }
                }
            }
        }
        return ['daily'=>$daily,'weekly'=>array_values($weekly),'workload'=>$workload,'specialty_workload'=>$specialty_workload,
            'blocks'=>$blocks,'conflicts'=>$conflicts,'planning_range'=>['from'=>$from,'to'=>$to],
            'absence_note'=>'No existe control de asistencia laboral de médicos; los registros siguientes son bloqueos/indisponibilidad y no ausencias confirmadas.'];
    }

    private function readRows(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':'.$key, $value, $key === 'doctors' ? \PDO::PARAM_STR : \PDO::PARAM_STR);
        }
        $statement->execute();
        return $statement->fetchAll();
    }

    private function planningRange(array $filters): array
    {
        $from = $filters['fecha_inicio'] ?? null;
        $to = $filters['fecha_fin'] ?? null;
        $today = new \DateTimeImmutable('now', new \DateTimeZone('America/La_Paz'));
        $fromDate = ScheduleValidator::date($from ?? $to ?? '') ?? $today->setTime(0, 0);
        $toDate = ScheduleValidator::date($to ?? '') ?? $fromDate;
        return [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')];
    }

    private function dates(string $from, string $to): array
    {
        $dates = [];
        $current = ScheduleValidator::date($from);
        $end = ScheduleValidator::date($to);
        while ($current !== null && $end !== null && $current <= $end) {
            $dates[] = $current;
            $current = $current->modify('+1 day');
        }
        return $dates;
    }

    private function specialtiesByDoctor(array $doctorIds): array
    {
        if (!$doctorIds) {
            return [];
        }
        $statement = $this->pdo->prepare('SELECT id_medico,id_especialidad FROM public.medico_especialidad WHERE id_medico = ANY(CAST(:doctors AS integer[]))');
        $statement->bindValue(':doctors', '{'.implode(',', $doctorIds).'}', \PDO::PARAM_STR);
        $statement->execute();
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(int)$row['id_medico']][] = (int)$row['id_especialidad'];
        }
        return $result;
    }

    private function hoursForSchedules(array $rows): float
    {
        // Union per doctor/day: inherited overlapping schedules must not inflate capacity.
        $groups = [];
        foreach ($rows as $row) {
            $start = ScheduleValidator::time((string)$row['hora_inicio']);
            $end = ScheduleValidator::time((string)$row['hora_fin']);
            if ($start !== null && $end !== null && $end > $start) {
                $key = ($row['id_medico'] ?? 0).'|'.($row['fecha'] ?? $row['dia_semana'] ?? 'day');
                $groups[$key][] = [$start, $end];
            }
        }
        $seconds = 0;
        foreach ($groups as $intervals) {
            usort($intervals, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            [$start, $end] = array_shift($intervals);
            foreach ($intervals as [$nextStart, $nextEnd]) {
                if ($nextStart <= $end) {
                    $end = max($end, $nextEnd);
                } else {
                    $seconds += ($end - $start) / 1000000;
                    [$start, $end] = [$nextStart, $nextEnd];
                }
            }
            $seconds += ($end - $start) / 1000000;
        }
        return round($seconds / 3600, 2);
    }

    private function hoursForAppointments(array $rows): float
    {
        return $this->hoursForSchedules($rows);
    }

    private function countState(array $rows, string $state): int
    {
        return count(array_filter($rows, static fn(array $row): bool => $row['estado'] === $state));
    }
}
