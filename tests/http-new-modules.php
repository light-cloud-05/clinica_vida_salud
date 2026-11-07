<?php
declare(strict_types=1);

$base = 'http://127.0.0.1:8098';
$fixture = json_decode(file_get_contents(__DIR__.'/../var/integration/http-sessions.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = [];
$failures = [];

function request(string $path, ?array $cookie, string $method = 'GET', array $body = []): array
{
    global $base;
    $ch = curl_init($base.$path);
    $headers = ['Accept: text/html,application/json'];
    if ($cookie) {
        $headers[] = 'Cookie: CVS_TEST_SESSION='.$cookie['id'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $method === 'POST' ? http_build_query($body) : null,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerText = substr($raw, 0, $headerSize);
    $headers = [];
    foreach (preg_split('/\r\n|\n|\r/', $headerText) as $line) {
        if (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $normalized = strtolower(trim($key));
            if ($normalized === 'set-cookie' && isset($headers[$normalized])) {
                $headers[$normalized] .= "\n".trim($value);
            } else {
                $headers[$normalized] = trim($value);
            }
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => substr($raw, $headerSize)];
}

function responseCookie(array $response): array
{
    $header = $response['headers']['set-cookie'] ?? '';
    preg_match('/CVS_TEST_SESSION=([^;]+)/', $header, $match);
    return ['id' => $match[1] ?? ''];
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

function check(bool $ok, string $label, int $status, string $persistence): void
{
    global $checks, $failures;
    $row = ['flow' => $label, 'status' => $status, 'persistence' => $persistence, 'result' => $ok ? 'OK' : 'FALLO'];
    $checks[] = $row;
    if (!$ok) {
        $failures[] = $row;
    }
    echo ($ok ? 'OK: ' : 'FAIL: ').$label.' ['.$status.']'.PHP_EOL;
}

try {
    $admin = ['id' => $fixture['sessions']['Administrador']['id'], 'csrf' => $fixture['sessions']['Administrador']['csrf']];
    $doctor = ['id' => $fixture['sessions']['Medico']['id'], 'csrf' => $fixture['sessions']['Medico']['csrf']];
    $reception = ['id' => $fixture['sessions']['Recepcionista']['id'], 'csrf' => $fixture['sessions']['Recepcionista']['csrf']];

    $suffix = 'HTTP'.strtoupper(bin2hex(random_bytes(4)));
    $register = request('/registro', null);
    $public = responseCookie($register);
    $password = 'Http-Test-2026!';
    $registration = [
        'csrf' => token($register['body']), 'nombre_usuario' => 'paciente_'.$suffix,
        'correo_acceso' => 'paciente_'.$suffix.'@example.invalid', 'codigo_paciente' => 'HTTP-'.$suffix,
        'documento_identidad' => 'DOC-HTTP-'.$suffix, 'nombres' => 'Paciente HTTP',
        'apellidos' => 'Integracion', 'contrasena' => $password, 'contrasena_confirmacion' => $password,
    ];
    $response = request('/registro', $public, 'POST', $registration);
    check(in_array($response['status'], [200, 303], true), 'CU15 registro público TEST', $response['status'], 'Cuenta/paciente TEST');

    $login = request('/login', $public);
    $response = request('/login', $public, 'POST', ['csrf' => token($login['body']), 'identifier' => $registration['nombre_usuario'], 'password' => $password]);
    $patient = responseCookie($response);
    if ($patient['id'] === '') {
        $patient = $public;
    }
    check($response['status'] === 303 && $patient['id'] !== '', 'CU15 login HTTP de paciente TEST', $response['status'], 'Sesión HTTP');

    $mailDirectory = __DIR__.'/../backend/var/private-mail';
    $mailBefore = array_fill_keys(glob($mailDirectory.'/message-*.txt') ?: [], true);
    $recovery = request('/recuperar-contrasena', null);
    $recoveryCookie = responseCookie($recovery);
    $response = request('/recuperar-contrasena', $recoveryCookie, 'POST', ['csrf' => token($recovery['body']), 'correo' => $registration['correo_acceso']]);
    $mailFiles = array_values(array_filter(glob($mailDirectory.'/message-*.txt') ?: [], static fn(string $file): bool => !isset($mailBefore[$file])));
    usort($mailFiles, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $resetToken = '';
    foreach ($mailFiles as $mail) {
        $content = file_get_contents($mail);
        preg_match('/token=([a-f0-9]{64})/', $content, $match);
        if (isset($match[1])) {
            $resetToken = $match[1];
            break;
        }
    }
    if ($resetToken === '') {
        $mailFiles = glob($mailDirectory.'/message-*.txt') ?: [];
        usort($mailFiles, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach ($mailFiles as $mail) {
            preg_match('/token=([a-f0-9]{64})/', (string)file_get_contents($mail), $match);
            if (isset($match[1])) {
                $resetToken = $match[1];
                break;
            }
        }
    }
    check($response['status'] === 200 && strlen($resetToken) === 64, 'CU16 solicitud y transporte local', $response['status'], 'Mensaje local TEST');
    $reset = request('/restablecer-contrasena?token='.$resetToken, $recoveryCookie);
    $resetCookie = responseCookie($reset);
    if ($resetCookie['id'] === '') {
        $resetCookie = $recoveryCookie;
    }
    $response = request('/restablecer-contrasena', $resetCookie, 'POST', ['csrf' => token($reset['body']), 'token' => $resetToken, 'contrasena' => 'Http-New-2026!', 'confirmacion' => 'Http-New-2026!']);
    check($response['status'] === 200 || $response['status'] === 303, 'CU16 restablecimiento HTTP', $response['status'], 'Hash TEST actualizado');
    $login = request('/login', null);
    $response = request('/login', responseCookie($login), 'POST', ['csrf' => token($login['body']), 'identifier' => $registration['nombre_usuario'], 'password' => 'Http-New-2026!']);
    $patient = responseCookie($response);

    $profile = request('/mi-perfil', $patient);
    $response = request('/mi-perfil', $patient, 'POST', ['csrf' => token($profile['body']), 'correo_acceso' => $registration['correo_acceso'], 'telefono' => '71000001', 'correo_contacto' => 'contacto_'.$suffix.'@example.invalid', 'direccion' => 'Direccion HTTP', 'contacto_emergencia_nombre' => '', 'contacto_emergencia_parentesco' => '', 'contacto_emergencia_telefono' => '', 'tipo_sangre' => '', 'alergias' => '', 'antecedentes_relevantes' => '']);
    check($response['status'] === 200 && str_contains($response['body'], 'perfil se actualizó'), 'CU20 actualización de perfil', $response['status'], 'Perfil TEST');

    $userForm = request('/usuarios/nuevo', $admin);
    $userBody = ['csrf' => token($userForm['body']), 'nombre_usuario' => 'usuario_'.$suffix, 'correo_acceso' => 'usuario_'.$suffix.'@example.invalid', 'id_rol' => '4', 'estado' => 'inactivo', 'contrasena' => '', 'contrasena_confirmacion' => ''];
    $response = request('/usuarios/nuevo', $admin, 'POST', $userBody);
    check(in_array($response['status'], [200, 303], true), 'P7 alta administrativa TEST', $response['status'], 'Usuario TEST');
    $response = request('/usuarios/nuevo', $doctor, 'POST', $userBody);
    check($response['status'] === 403, 'P7 médico sin administración', $response['status'], 'Sin escritura');

    $patientForm = request('/pacientes/nuevo', $admin);
    $patientBody = ['csrf' => token($patientForm['body']), 'codigo_paciente' => 'P2-'.$suffix, 'documento_identidad' => 'DOC-P2-'.$suffix, 'nombres' => 'Paciente P2', 'apellidos' => 'HTTP', 'fecha_nacimiento' => '1990-01-01', 'sexo' => 'F', 'telefono' => '71000002', 'correo_contacto' => 'p2_'.$suffix.'@example.invalid', 'direccion' => 'HTTP', 'estado' => 'activo'];
    $response = request('/pacientes/nuevo', $admin, 'POST', $patientBody);
    check(in_array($response['status'], [200, 303], true), 'P2 alta de paciente TEST', $response['status'], 'Paciente TEST');

    $specialtyForm = request('/especialidades/nueva', $admin);
    $specialtyBody = ['csrf' => token($specialtyForm['body']), 'nombre' => 'Especialidad '.$suffix, 'descripcion' => 'Prueba HTTP', 'precio_consulta' => '125.00', 'estado' => 'activo'];
    $response = request('/especialidades/nueva', $admin, 'POST', $specialtyBody);
    check(in_array($response['status'], [200, 303], true), 'P3 especialidad TEST', $response['status'], 'Especialidad TEST');

    $schedule = request('/horarios', $admin);
    check($schedule['status'] === 200, 'P4 consulta de horarios', $schedule['status'], 'Solo lectura HTTP');
    $scheduleForm = request('/bloqueos/nuevo', $admin);
    preg_match_all('/<select[^>]+name="id_medico"[^>]*>.*?<option value="([^"]+)"/s', $scheduleForm['body'], $doctorMatches);
    $doctorId = $doctorMatches[1][count($doctorMatches[1]) - 1] ?? '';
    $response = request('/bloqueos/nuevo', $admin, 'POST', ['csrf' => token($scheduleForm['body']), 'id_medico' => $doctorId, 'fecha' => date('Y-m-d', strtotime('+30 days')), 'hora_inicio' => '23:00', 'hora_fin' => '23:30', 'motivo' => 'Bloqueo TEST HTTP']);
    check(in_array($response['status'], [200, 303], true), 'P4 POST horario TEST', $response['status'], 'Horario TEST persistido');

    $notifications = request('/mis-notificaciones', $patient);
    check($notifications['status'] === 200, 'CU22 notificaciones propias', $notifications['status'], 'Lectura acotada al paciente');
    $report = request('/reportes', $admin);
    check($report['status'] === 200, 'P6 reporte administrativo', $report['status'], 'Consulta TEST');
    $csv = request('/reportes/exportar?formato=csv', $admin);
    check($csv['status'] === 200 && str_contains((string)($csv['headers']['content-type'] ?? ''), 'text/csv'), 'P6 descarga CSV', $csv['status'], 'Exportación TEST');
    $pdf = request('/reportes/exportar?formato=pdf', $admin);
    check($pdf['status'] === 200 && str_contains((string)($pdf['headers']['content-type'] ?? ''), 'application/pdf'), 'P6 descarga PDF', $pdf['status'], 'Exportación TEST');
    $audit = request('/auditoria', $admin);
    check($audit['status'] === 200, 'P8 auditoría administrativa', $audit['status'], 'Lectura TEST');
    $voice = request('/consultas-voz', $admin);
    $response = request('/consultas-voz/consultar', $admin, 'POST', ['csrf' => token($voice['body']), 'consulta' => 'Mis próximas citas', 'fecha' => '']);
    check($response['status'] === 200 && str_contains($response['body'], 'PostgreSQL'), 'P10 consulta por texto', $response['status'], 'SELECT autorizado TEST');

    file_put_contents(__DIR__.'/../var/integration/http-new-modules-results.json', json_encode($checks, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    if ($failures) {
        exit(1);
    }
} catch (Throwable $exception) {
    file_put_contents(__DIR__.'/../var/integration/http-new-modules-failure.log', (string) $exception);
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
