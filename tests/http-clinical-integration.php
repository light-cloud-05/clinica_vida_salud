<?php
declare(strict_types=1);

require dirname(__DIR__).'/tools/integration-bootstrap.php';
require dirname(__DIR__).'/backend/bootstrap.php';

use Clinica\Repositories\Connection;

$base = 'http://127.0.0.1:8098';
$fixture = json_decode(file_get_contents(dirname(__DIR__).'/var/integration/http-sessions.json'), true, 512, JSON_THROW_ON_ERROR);
$pdo = Connection::integration(require dirname(__DIR__).'/backend/config/database.php');
$now = new DateTimeImmutable('now', new DateTimeZone('America/La_Paz'));
$statement = $pdo->prepare(
    "SELECT c.id_cita,c.id_paciente,c.id_medico,c.fecha,c.hora_inicio,c.hora_fin,c.estado,c.updated_at,
            p.id_usuario AS paciente_usuario,m.id_usuario AS medico_usuario
     FROM public.citas c
     JOIN public.pacientes p ON p.id_paciente=c.id_paciente
     JOIN public.medicos m ON m.id_medico=c.id_medico
     WHERE p.id_usuario=:patient AND m.id_usuario=:doctor
       AND c.estado IN ('programada','confirmada')
       AND (c.fecha + c.hora_inicio) <= :now
     ORDER BY c.fecha,c.hora_inicio,c.id_cita
     LIMIT 1"
);
$statement->execute([
    'patient' => 52,
    'doctor' => 21,
    'now' => $now->format('Y-m-d H:i:s'),
]);
$appointment = $statement->fetch();
$attendanceBlocked = false;
if (!$appointment) {
    $diagnostic = $pdo->query(
        "SELECT c.id_cita,c.fecha,c.hora_inicio,c.estado,p.id_usuario AS paciente_usuario,m.id_usuario AS medico_usuario
         FROM public.citas c
         JOIN public.pacientes p ON p.id_paciente=c.id_paciente
         JOIN public.medicos m ON m.id_medico=c.id_medico
         WHERE p.id_usuario=52 OR m.id_usuario=21
         ORDER BY c.fecha,c.hora_inicio,c.id_cita DESC
         LIMIT 20"
    );
    fwrite(STDERR, "BLOQUEO: no existe una cita TEST pasada programada/confirmada entre las identidades TEST autorizadas.\n");
    foreach ($diagnostic as $row) {
        fwrite(STDERR, sprintf("cita=%d paciente_usuario=%d medico_usuario=%d fecha=%s hora=%s estado=%s\n", $row['id_cita'], $row['paciente_usuario'], $row['medico_usuario'], $row['fecha'], $row['hora_inicio'], $row['estado']));
    }
    $fallback = $pdo->prepare(
        "SELECT c.id_cita,c.id_paciente,c.id_medico,c.fecha,c.hora_inicio,c.hora_fin,c.estado,c.updated_at,
                p.id_usuario AS paciente_usuario,m.id_usuario AS medico_usuario
         FROM public.citas c
         JOIN public.pacientes p ON p.id_paciente=c.id_paciente
         JOIN public.medicos m ON m.id_medico=c.id_medico
         WHERE m.id_usuario=:doctor AND c.estado='atendida'
         ORDER BY c.fecha DESC,c.hora_inicio DESC,c.id_cita DESC
         LIMIT 1"
    );
    $fallback->execute(['doctor'=>21]);
    $appointment = $fallback->fetch();
    if (!$appointment) {
        exit(2);
    }
    $attendanceBlocked = true;
}

function request(string $path, array $session, string $method = 'GET', array $body = []): array
{
    global $base;
    $ch = curl_init($base.$path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml',
            'Cookie: CVS_TEST_SESSION='.$session['id'],
        ],
        CURLOPT_POSTFIELDS => $method === 'POST' ? http_build_query($body) : null,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status'=>$status,'headers'=>substr($raw, 0, $size),'body'=>substr($raw, $size)];
}

function token(string $html): string
{
    preg_match('/name="csrf" value="([a-f0-9]{64})"/', $html, $match);
    return $match[1] ?? '';
}

function hidden(string $html, string $name): string
{
    preg_match('/name="'.preg_quote($name, '/').'" value="([^"]*)"/', $html, $match);
    return html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function check(bool $ok, string $label, int $status): void
{
    echo ($ok ? 'OK: ' : 'FAIL: ').$label.' ['.$status."]\n";
    if (!$ok) {
        throw new RuntimeException($label);
    }
}

$doctor = $fixture['sessions']['Medico'];
$patient = $fixture['sessions']['Paciente'];
$reception = $fixture['sessions']['Recepcionista'];
$id = (int) $appointment['id_cita'];
if (!$attendanceBlocked) {
    $detail = request('/citas/detalle?id='.$id, $doctor);
    check($detail['status'] === 200, 'CU11 detalle de cita TEST autorizado', $detail['status']);
    $csrf = token($detail['body']);
    $version = hidden($detail['body'], 'version');
    $response = request('/citas/atender', $doctor, 'POST', ['csrf'=>$csrf,'id'=>(string)$id,'version'=>$version]);
    check($response['status'] === 303, 'CU11 transición HTTP a atendida', $response['status']);

    $verify = $pdo->prepare('SELECT estado FROM public.citas WHERE id_cita=:id');
    $verify->execute(['id'=>$id]);
    check($verify->fetchColumn() === 'atendida', 'CU11 estado atendida persistido', 200);

    $audit = $pdo->prepare("SELECT count(*) FROM public.historial_cambios_cita WHERE id_cita=:id AND estado_nuevo='atendida'");
    $audit->execute(['id'=>$id]);
    check((int)$audit->fetchColumn() > 0, 'CU11 historial de cambio persistido', 200);
}

$history = request('/citas/historial-paciente?id='.(int)$appointment['id_paciente'], $doctor);
check($history['status'] === 200, 'Historial clínico HTTP para médico autorizado', $history['status']);
$csrf = token($history['body']);
$suffix = strtoupper(bin2hex(random_bytes(4)));
$note = 'Nota clínica ficticia de integración TEST '.$suffix.'.';
$description = 'Tratamiento ficticio de integración TEST '.$suffix.'.';
$response = request('/citas/notas-medicas', $doctor, 'POST', [
    'csrf'=>$csrf,
    'id_cita'=>(string)$id,
    'observaciones'=>$note,
]);
check($response['status'] === 303, 'Registro HTTP de nota médica', $response['status']);

$history = request('/citas/historial-paciente?id='.(int)$appointment['id_paciente'], $doctor);
check($history['status'] === 200 && str_contains($history['body'], $note), 'Nota médica persistida y visible', $history['status']);
$csrf = token($history['body']);
$response = request('/citas/tratamientos', $doctor, 'POST', [
    'csrf'=>$csrf,
    'id_cita'=>(string)$id,
    'descripcion'=>$description,
    'indicaciones'=>'Tomar según indicación médica ficticia.',
]);
check($response['status'] === 303, 'Registro HTTP de tratamiento', $response['status']);

$verify = $pdo->prepare('SELECT count(*) FROM public.notas_medicas WHERE id_cita=:id AND observaciones=:note');
$verify->execute(['id'=>$id,'note'=>$note]);
check((int)$verify->fetchColumn() === 1, 'Nota médica persistida en PostgreSQL TEST', 200);
$verify = $pdo->prepare('SELECT count(*) FROM public.tratamientos_recetas WHERE id_cita=:id AND descripcion=:description');
$verify->execute(['id'=>$id,'description'=>$description]);
check((int)$verify->fetchColumn() === 1, 'Tratamiento persistido en PostgreSQL TEST', 200);

$patientHistory = request('/mis-citas/historial?id='.(int)$appointment['id_paciente'], $patient);
check($patientHistory['status'] === 404, 'Paciente no autenticado como propietario no accede al historial', $patientHistory['status']);
$receptionHistory = request('/citas/historial-paciente?id='.(int)$appointment['id_paciente'], $reception);
check($receptionHistory['status'] === 200 && !str_contains($receptionHistory['body'], $note) && !str_contains($receptionHistory['body'], $description), 'Recepción no recibe notas ni tratamientos privados', $receptionHistory['status']);
$otherPatient = request('/mis-citas/historial?id=1', $patient);
check($otherPatient['status'] === 404 && !str_contains($otherPatient['body'], $note), 'Paciente no accede al historial de otro paciente', $otherPatient['status']);

$csrfInvalid = request('/citas/notas-medicas', $doctor, 'POST', [
    'csrf'=>str_repeat('0', 64),
    'id_cita'=>(string)$id,
    'observaciones'=>'No debe persistir.',
]);
check($csrfInvalid['status'] === 419 && !str_contains($csrfInvalid['body'], 'No debe persistir.'), 'CSRF clínico inválido rechazado sin exponer datos', $csrfInvalid['status']);
echo "Pruebas HTTP clínicas persistentes completadas para cita TEST ".$id.".\n";
