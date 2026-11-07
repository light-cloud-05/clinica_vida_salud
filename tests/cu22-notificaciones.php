<?php
declare(strict_types=1);
require dirname(__DIR__).'/backend/bootstrap.php';

use Clinica\Repositories\NotificationStore;
use Clinica\Services\{MailTransport,NotificationService};

final class Cu22Store implements NotificationStore
{
    public array $appointments = [];
    public array $notifications = [];
    public array $types = [];
    private int $next = 1;
    public function appointment(int $id): ?array { return $this->appointments[$id] ?? null; }
    public function enqueue(int $appointmentId, string $type, string $recipient, string $subject, ?string $scheduledAt = null): ?int
    {
        foreach ($this->notifications as $row) {
            if ($row['id_cita'] === $appointmentId && $row['tipo'] === $type) { return null; }
        }
        $id = $this->next++;
        $this->notifications[] = ['id_notificacion'=>$id,'id_cita'=>$appointmentId,'tipo'=>$type,'destinatario'=>$recipient,'asunto'=>$subject,'estado_envio'=>'pendiente'];
        $this->types[] = $type;
        return $id;
    }
    public function pending(int $limit): array { return array_slice(array_filter($this->notifications, static fn(array $n): bool => $n['estado_envio']==='pendiente'), 0, $limit); }
    public function upcoming(DateTimeImmutable $now, int $leadMinutes, int $limit): array { return array_slice($this->appointments, 0, $limit); }
    public function markSent(int $id): void { foreach ($this->notifications as &$row) { if ($row['id_notificacion']===$id) { $row['estado_envio']='enviado'; } } }
    public function markError(int $id): void { foreach ($this->notifications as &$row) { if ($row['id_notificacion']===$id) { $row['estado_envio']='error'; } } }
    public function listing(array $actor, int $limit, int $offset): array { return []; }
}

final class Cu22Transport implements MailTransport
{
    public int $calls = 0;
    public bool $fails = false;
    public function send(string $recipient, string $subject, string $body): void
    {
        ++$this->calls;
        if ($this->fails) { throw new RuntimeException('Fallo SMTP simulado.'); }
    }
}

$checkCount = 0;
$check = static function (bool $condition, string $label) use (&$checkCount): void {
    if (!$condition) { throw new RuntimeException('FALLO: '.$label); }
    ++$checkCount;
};
$store = new Cu22Store();
$store->appointments[7] = ['id_cita'=>7,'codigo_cita'=>'CVS-SIMULADA','fecha'=>'2026-11-09','hora_inicio'=>'10:00:00','hora_fin'=>'10:30:00','estado'=>'programada','correo_contacto'=>'paciente@example.test','medico_nombre'=>'Médico Simulado','especialidad_nombre'=>'Medicina general'];
$transport = new Cu22Transport();
$service = new NotificationService($store, $transport, 2);
$check($service->appointment(7, 'registro_cita') === 1, 'Registro genera notificación');
$check($service->appointment(7, 'registro_cita') === null, 'Reintento no duplica registro');
$check($service->appointment(7, 'reprogramacion_cita') === 2, 'Reprogramación genera notificación');
$check($service->appointment(7, 'cancelacion_cita') === 3, 'Cancelación genera notificación');
$check($service->reminders(new DateTimeImmutable('now'), 1440) === [4], 'Recordatorio vigente genera notificación');
$check($transport->calls === 4 && count($store->notifications) === 4, 'Transporte local procesa cuatro mensajes');
$transport->fails = true;
$store->appointments[8] = array_replace($store->appointments[7], ['id_cita'=>8]);
$check($service->appointment(8, 'registro_cita') === 5 && $store->notifications[4]['estado_envio'] === 'error', 'Correo fallido queda en error');
$check(in_array('recordatorio_cita', $store->types, true), 'Tipo de recordatorio registrado');
echo $checkCount." comprobaciones CU22 simuladas pasaron; no se consultó ni modificó PostgreSQL.\n";
