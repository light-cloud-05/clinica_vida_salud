<?php
declare(strict_types=1);
namespace Clinica\Services;

use Clinica\Repositories\NotificationStore;

final class NotificationService
{
    public function __construct(private NotificationStore $store, private MailTransport $transport, private int $maxRetries = 2) {}

    public function appointment(int $appointmentId, string $type): ?int
    {
        $appointment = $this->store->appointment($appointmentId);
        if (!$appointment) {
            throw new \RuntimeException('La cita no existe para generar la notificación.');
        }
        $recipient = trim((string)($appointment['correo_contacto'] ?? ''));
        [$subject, $body] = $this->content($appointment, $type);
        $id = $this->store->enqueue($appointmentId, $type, $recipient, $subject);
        if ($id !== null) {
            $this->deliver($id);
        }
        return $id;
    }

    public function reminders(\DateTimeImmutable $now, int $leadMinutes, int $limit = 100): array
    {
        $created = [];
        foreach ($this->store->upcoming($now, $leadMinutes, $limit) as $appointment) {
            $recipient = trim((string)($appointment['correo_contacto'] ?? ''));
            if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            [$subject] = $this->content($appointment, 'recordatorio_cita');
            $when = new \DateTimeImmutable($appointment['fecha'].' '.$appointment['hora_inicio'], new \DateTimeZone('America/La_Paz'));
            $id = $this->store->enqueue((int)$appointment['id_cita'], 'recordatorio_cita', $recipient, $subject, $when->modify("-{$leadMinutes} minutes")->format('c'));
            if ($id !== null) {
                $created[] = $id;
                $this->deliver($id);
            }
        }
        return $created;
    }

    public function processPending(int $limit = 100): array
    {
        $result = ['enviado' => 0, 'error' => 0];
        foreach ($this->store->pending($limit) as $notification) {
            if ($this->deliver((int)$notification['id_notificacion'], $notification)) {
                $result['enviado']++;
            } else {
                $result['error']++;
            }
        }
        return $result;
    }

    private function deliver(int $id, ?array $notification = null): bool
    {
        $notification ??= $this->pendingById($id);
        if (!$notification) {
            throw new \RuntimeException('La notificación pendiente no existe.');
        }
        $appointment = $this->store->appointment((int)$notification['id_cita']);
        if (!$appointment) {
            $this->store->markError($id);
            return false;
        }
        if (!filter_var((string)$notification['destinatario'], FILTER_VALIDATE_EMAIL)) {
            $this->store->markError($id);
            error_log('Notificación '.$id.' sin destinatario válido; no se intentó enviar.');
            return false;
        }
        [, $body] = $this->content($appointment, (string)$notification['tipo']);
        $lastError = null;
        for ($attempt = 1; $attempt <= max(1, $this->maxRetries); $attempt++) {
            try {
                $this->transport->send((string)$notification['destinatario'], (string)$notification['asunto'], $body);
                $this->store->markSent($id);
                return true;
            } catch (\RuntimeException $e) {
                $lastError = $e;
            }
        }
        $this->store->markError($id);
        error_log('Fallo de envío de notificación '.$id.' tras '.$this->maxRetries.' intento(s): '.get_class($lastError));
        return false;
    }

    private function pendingById(int $id): ?array
    {
        foreach ($this->store->pending(1000) as $notification) {
            if ((int)$notification['id_notificacion'] === $id) {
                return $notification;
            }
        }
        return null;
    }

    private function content(array $appointment, string $type): array
    {
        $labels = [
            'registro_cita' => ['Cita registrada', 'registro'],
            'reprogramacion_cita' => ['Cita reprogramada', 'reprogramación'],
            'cancelacion_cita' => ['Cita cancelada', 'cancelación'],
            'recordatorio_cita' => ['Recordatorio de cita', 'recordatorio'],
        ];
        if (!isset($labels[$type])) {
            throw new \InvalidArgumentException('Tipo de notificación inválido.');
        }
        [$title, $event] = $labels[$type];
        $date = (string)$appointment['fecha'];
        $time = substr((string)$appointment['hora_inicio'], 0, 5).'–'.substr((string)$appointment['hora_fin'], 0, 5);
        $body = "Clínica Vida Salud\n\n";
        $body .= "Se informa el {$event} de su cita.\n";
        $body .= "Código: {$appointment['codigo_cita']}\nFecha: {$date}\nHora: {$time}\n";
        $body .= "Médico: {$appointment['medico_nombre']}\nEspecialidad: {$appointment['especialidad_nombre']}\n";
        $body .= "Estado: {$appointment['estado']}\n";
        return [$title.' · '.$appointment['codigo_cita'], $body];
    }
}
