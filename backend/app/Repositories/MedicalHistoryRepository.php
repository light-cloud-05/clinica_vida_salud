<?php
declare(strict_types=1);

namespace Clinica\Repositories;

use Clinica\Middleware\MedicalHistoryAccess;
use Clinica\Services\MedicalRecordConflict;

final class MedicalHistoryRepository implements MedicalHistoryStore
{
    public function __construct(private \PDO $pdo) {}

    private function scope(array $actor, string $patientAlias = 'p'): array
    {
        if (!MedicalHistoryAccess::view($actor)) {
            throw new \DomainException('Consulta de historial no autorizada.');
        }

        if ($actor['role'] === 'Paciente') {
            return [$patientAlias.'.id_usuario=:actor', ['actor' => $actor['id']]];
        }
        if ($actor['role'] === 'Medico') {
            return ['EXISTS (SELECT 1 FROM public.citas scope_c JOIN public.medicos scope_m
                ON scope_m.id_medico=scope_c.id_medico
                WHERE scope_c.id_paciente='.$patientAlias.'.id_paciente AND scope_m.id_usuario=:actor)', ['actor' => $actor['id']]];
        }

        return ['TRUE', []];
    }

    public function history(array $actor, int $patientId): ?array
    {
        if ($patientId < 1) {
            throw new \InvalidArgumentException('Identificador de paciente inválido.');
        }
        [$scope, $params] = $this->scope($actor);
        $params['patient'] = $patientId;
        $clinical = MedicalHistoryAccess::clinical($actor);
        $clinicalFields = $clinical ? ',p.tipo_sangre,p.alergias,p.antecedentes_relevantes' : '';
        $statement = $this->pdo->prepare(
            'SELECT p.id_paciente,p.codigo_paciente,p.documento_identidad,p.nombres,p.apellidos,
                    p.fecha_nacimiento,p.sexo,p.estado'.$clinicalFields.'
             FROM public.pacientes p
             WHERE p.id_paciente=:patient AND '.$scope
        );
        $statement->execute($params);
        $patient = $statement->fetch();
        if (!$patient) {
            return null;
        }

        $appointments = $this->pdo->prepare(
            'SELECT c.id_cita,c.codigo_cita,c.fecha,c.hora_inicio,c.hora_fin,c.estado,
                    m.id_medico,m.nombres||\' \'||m.apellidos AS medico_nombre,
                    e.nombre AS especialidad_nombre
             FROM public.citas c
             JOIN public.pacientes p ON p.id_paciente=c.id_paciente
             JOIN public.medicos m ON m.id_medico=c.id_medico
             JOIN public.especialidades e ON e.id_especialidad=c.id_especialidad
             WHERE c.id_paciente=:patient AND '.$scope.'
             ORDER BY c.fecha DESC,c.hora_inicio DESC,c.id_cita DESC'
        );
        $appointments->execute($params);
        $items = $appointments->fetchAll();
        $ids = array_column($items, 'id_cita');

        $notes = [];
        $treatments = [];
        if ($clinical && $ids !== []) {
            $notesStatement = $this->pdo->prepare(
                'SELECT n.id_nota,n.id_cita,n.fecha_registro,n.observaciones,
                        m.nombres||\' \'||m.apellidos AS medico_nombre
                 FROM public.notas_medicas n
                 JOIN public.medicos m ON m.id_medico=n.id_medico
                 JOIN public.citas c ON c.id_cita=n.id_cita
                 JOIN public.pacientes p ON p.id_paciente=c.id_paciente
                 WHERE c.id_paciente=:patient AND '.$scope.'
                 ORDER BY n.fecha_registro DESC,n.id_nota DESC'
            );
            $notesStatement->execute($params);
            $notes = $notesStatement->fetchAll();
            $treatmentStatement = $this->pdo->prepare(
                'SELECT t.id_tratamiento,t.id_cita,t.fecha_registro,t.descripcion,t.indicaciones,
                        m.nombres||\' \'||m.apellidos AS medico_nombre
                 FROM public.tratamientos_recetas t
                 JOIN public.medicos m ON m.id_medico=t.id_medico
                 JOIN public.citas c ON c.id_cita=t.id_cita
                 JOIN public.pacientes p ON p.id_paciente=c.id_paciente
                 WHERE c.id_paciente=:patient AND '.$scope.'
                 ORDER BY t.fecha_registro DESC,t.id_tratamiento DESC'
            );
            $treatmentStatement->execute($params);
            $treatments = $treatmentStatement->fetchAll();
        }

        $changes = $this->pdo->prepare(
            'SELECT h.id_cambio,h.id_cita,h.fecha_hora_cambio,h.tipo_cambio,
                    h.fecha_anterior,h.hora_inicio_anterior,h.hora_fin_anterior,
                    h.fecha_nueva,h.hora_inicio_nueva,h.hora_fin_nueva,
                    h.estado_anterior,h.estado_nuevo
             FROM public.historial_cambios_cita h
             JOIN public.citas c ON c.id_cita=h.id_cita
             JOIN public.pacientes p ON p.id_paciente=c.id_paciente
             WHERE c.id_paciente=:patient AND '.$scope.'
             ORDER BY h.fecha_hora_cambio DESC,h.id_cambio DESC'
        );
        $changes->execute($params);

        return [
            'patient' => $patient,
            'appointments' => $items,
            'notes' => $notes,
            'treatments' => $treatments,
            'changes' => $changes->fetchAll(),
            'clinical' => $clinical,
        ];
    }

    public function appointmentForDoctor(array $actor, int $appointmentId): ?array
    {
        if (!MedicalHistoryAccess::write($actor) || $appointmentId < 1) {
            throw new \DomainException('Registro clínico no autorizado.');
        }
        $statement = $this->pdo->prepare(
            'SELECT c.id_cita,c.id_paciente,c.id_medico,c.estado,m.nombres||\' \'||m.apellidos AS medico_nombre
             FROM public.citas c
             JOIN public.medicos m ON m.id_medico=c.id_medico
             WHERE c.id_cita=:id AND m.id_usuario=:actor'
        );
        $statement->execute(['id' => $appointmentId, 'actor' => $actor['id']]);
        return $statement->fetch() ?: null;
    }

    public function addNote(array $actor, int $appointmentId, string $observations): int
    {
        $appointment = $this->appointmentForDoctor($actor, $appointmentId);
        if (!$appointment) {
            throw new MedicalRecordConflict('La cita no pertenece al médico autenticado.');
        }
        if ($appointment['estado'] !== 'atendida') {
            throw new MedicalRecordConflict('Solo se pueden registrar notas en citas atendidas.');
        }
        $this->pdo->beginTransaction();
        try {
            $config = $this->pdo->prepare("SELECT set_config('app.usuario_id',:actor,true)");
            $config->execute(['actor' => (string) $actor['id']]);
            $statement = $this->pdo->prepare(
                'INSERT INTO public.notas_medicas (id_cita,id_medico,observaciones)
                 VALUES (:appointment,(SELECT id_medico FROM public.medicos WHERE id_usuario=:actor),:observations)
                 RETURNING id_nota'
            );
            $statement->execute([
                'appointment' => $appointmentId,
                'actor' => $actor['id'],
                'observations' => $observations,
            ]);
            $id = (int) $statement->fetchColumn();
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function addTreatment(array $actor, int $appointmentId, string $description, ?string $instructions): int
    {
        $appointment = $this->appointmentForDoctor($actor, $appointmentId);
        if (!$appointment) {
            throw new MedicalRecordConflict('La cita no pertenece al médico autenticado.');
        }
        if ($appointment['estado'] !== 'atendida') {
            throw new MedicalRecordConflict('Solo se pueden registrar tratamientos en citas atendidas.');
        }
        $this->pdo->beginTransaction();
        try {
            $config = $this->pdo->prepare("SELECT set_config('app.usuario_id',:actor,true)");
            $config->execute(['actor' => (string) $actor['id']]);
            $statement = $this->pdo->prepare(
                'INSERT INTO public.tratamientos_recetas
                    (id_cita,id_medico,descripcion,indicaciones)
                 VALUES (:appointment,(SELECT id_medico FROM public.medicos WHERE id_usuario=:actor),
                         :description,:instructions)
                 RETURNING id_tratamiento'
            );
            $statement->execute([
                'appointment' => $appointmentId,
                'actor' => $actor['id'],
                'description' => $description,
                'instructions' => $instructions,
            ]);
            $id = (int) $statement->fetchColumn();
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
