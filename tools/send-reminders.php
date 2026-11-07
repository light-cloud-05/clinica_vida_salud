<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/backend/bootstrap.php';

$lead = (int)\Clinica\Support\Environment::get('REMINDER_LEAD_MINUTES', '1440');
$limit = 100;
foreach ($argv as $argument) {
    if (preg_match('/^--minutes=([1-9][0-9]*)$/', $argument, $match)) { $lead = (int)$match[1]; }
    if (preg_match('/^--limit=([1-9][0-9]*)$/', $argument, $match)) { $limit = min(1000, (int)$match[1]); }
}
if ($lead < 1 || $lead > 10080) {
    fwrite(STDERR, "Anticipación inválida; use 1..10080 minutos.\n");
    exit(2);
}
try {
    $service = \Clinica\Support\NotificationFactory::writer();
    $created = $service->reminders(new DateTimeImmutable('now', new DateTimeZone('America/La_Paz')), $lead, $limit);
    $processed = $service->processPending($limit);
    echo json_encode(['recordatorios_creados'=>count($created),'enviados'=>$processed['enviado'],'errores'=>$processed['error'],'anticipacion_minutos'=>$lead,'transporte'=>\Clinica\Support\Environment::get('MAIL_TRANSPORT','local')], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "No se ejecutó el proceso de recordatorios: ".get_class($e).".\n");
    exit(1);
}
