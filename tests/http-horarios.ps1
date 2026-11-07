$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$base = 'http://127.0.0.1:8091'
$fixturesPath = Join-Path $root 'var/patient-http-fixtures.json'
$fixtureTool = Join-Path $PSScriptRoot 'support/patient-http-fixtures.php'
$checks = 0
$process = $null
$previousFlag = $env:SCHEDULE_WRITES_ENABLED
function Assert-Schedule([bool]$ok,[string]$label) {
    if (-not $ok) { throw ('FALLO HTTP P4: '+$label) }
    $script:checks++
    Write-Output ('OK HTTP: '+$label)
}
try {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,8091)
    $listener.Start()
    $listener.Stop()
    & php $fixtureTool create
    if ($LASTEXITCODE -ne 0) { throw 'No se pudieron preparar sesiones locales de prueba.' }
    $fixtures = Get-Content -LiteralPath $fixturesPath -Raw | ConvertFrom-Json
    $env:SCHEDULE_WRITES_ENABLED = 'false'
    $process = Start-Process -FilePath (Get-Command php).Source -WorkingDirectory $root -ArgumentList @('-d','display_errors=0','-S','127.0.0.1:8091','-t','public','tools/dev-router.php') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $root 'var/schedule-http-output.log') -RedirectStandardError (Join-Path $root 'var/schedule-http-error.log')
    $env:SCHEDULE_WRITES_ENABLED = $previousFlag
    Start-Sleep -Seconds 2
    if ($process.HasExited) { throw 'No se pudo iniciar el servidor temporal.' }
    $response = Invoke-WebRequest ($base+'/horarios') -SkipHttpErrorCheck -MaximumRedirection 0 -ErrorAction SilentlyContinue
    Assert-Schedule ($response.StatusCode -eq 303 -and $response.Headers.Location -eq '/login') 'Sin sesión redirige a login'
    foreach ($role in @('Administrador','Recepcionista','Medico','Paciente')) {
        $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
        $session.Cookies.Add([Uri]$base,[Net.Cookie]::new('CVS_SESSION',$fixtures.sessions.$role.id,'/'))
        $csrf = $fixtures.sessions.$role.csrf
        $allowed = $role -ne 'Paciente'
        $manager = $role -eq 'Administrador'
        $own = $fixtures.physician_doctor
        $doctorId = if ($role -eq 'Medico') {$own} else {$fixtures.doctor_id}
        foreach ($route in @('/horarios',('/horarios/detalle?medico='+$doctorId),('/horarios/agenda?medico='+$doctorId),'/bloqueos')) {
            $response = Invoke-WebRequest ($base+$route) -WebSession $session -SkipHttpErrorCheck
            Assert-Schedule ($response.StatusCode -eq $(if($allowed){200}else{403})) ($role+' GET '+($route -split '\?')[0]+' ['+$response.StatusCode+']')
        }
        foreach ($route in @('/horarios/nuevo',('/horarios/editar?id='+$fixtures.schedule.id_horario),'/bloqueos/nuevo')) {
            $response = Invoke-WebRequest ($base+$route) -WebSession $session -SkipHttpErrorCheck
            Assert-Schedule ($response.StatusCode -eq $(if($manager){200}else{403})) ($role+' gestión protegida '+($route -split '\?')[0])
        }
        $new = @{csrf=$csrf;id_medico=[string]$doctorId;dia_semana='1';hora_inicio='00:00';hora_fin='01:00';duracion_cita_minutos='30';estado='activo'}
        $edit = @{csrf=$csrf;id_medico=[string]$fixtures.schedule.id_medico;dia_semana=[string]$fixtures.schedule.dia_semana;hora_inicio=$fixtures.schedule.hora_inicio;hora_fin=$fixtures.schedule.hora_fin;duracion_cita_minutos=[string]$fixtures.schedule.duracion_cita_minutos;estado=$fixtures.schedule.estado}
        $block = @{csrf=$csrf;id_medico=[string]$doctorId;fecha='2030-11-04';hora_inicio='00:00';hora_fin='01:00';motivo='Simulación HTTP sin guardar'}
        foreach ($operation in @(@{route='/horarios/nuevo';body=$new},@{route=('/horarios/editar?id='+$fixtures.schedule.id_horario);body=$edit},@{route='/bloqueos/nuevo';body=$block})) {
            $response = Invoke-WebRequest ($base+$operation.route) -Method Post -Body $operation.body -WebSession $session -SkipHttpErrorCheck
            Assert-Schedule ($response.StatusCode -eq $(if($manager){423}else{403})) ($role+' POST sin escritura '+($operation.route -split '\?')[0]+' ['+$response.StatusCode+']')
        }
        $panel = @{Administrador='/administrador';Recepcionista='/recepcionista';Medico='/medico';Paciente='/paciente'}[$role]
        $response = Invoke-WebRequest ($base+$panel) -WebSession $session -SkipHttpErrorCheck
        Assert-Schedule ($response.Content.Contains('href="/horarios"') -eq $allowed) ($role+' enlace según permisos')
        if (-not $allowed) { continue }
        $response = Invoke-WebRequest ($base+'/horarios') -WebSession $session -SkipHttpErrorCheck
        $total = if ($role -eq 'Medico') {5} else {90}
        Assert-Schedule ($response.Content.Contains(('data-schedule-total="'+$total+'"'))) ($role+' cantidad de horarios correcta')
        $ids = [regex]::Matches($response.Content,'data-doctor-id="([0-9]+)"')
        Assert-Schedule ($ids.Count -eq $(if($role -eq 'Medico'){1}else{18})) ($role+' ámbito de médicos correcto')
        $response = Invoke-WebRequest ($base+'/bloqueos') -WebSession $session -SkipHttpErrorCheck
        Assert-Schedule ($response.Content.Contains('data-block-total="0"')) ($role+' cero bloqueos reales')
        $response = Invoke-WebRequest ($base+'/horarios?dia=1') -WebSession $session -SkipHttpErrorCheck
        $total = if ($role -eq 'Medico') {1} else {18}
        Assert-Schedule ($response.Content.Contains(('data-schedule-total="'+$total+'"'))) ($role+' filtro por día')
        $response = Invoke-WebRequest ($base+'/horarios?medico='+$doctorId) -WebSession $session -SkipHttpErrorCheck
        Assert-Schedule ($response.Content.Contains('data-schedule-total="5"')) ($role+' filtro por médico')
        $response = Invoke-WebRequest ($base+'/horarios/agenda?semana=2030-11-04&medico='+$doctorId) -WebSession $session -SkipHttpErrorCheck
        Assert-Schedule ($response.StatusCode -eq 200 -and $response.Content.Contains('Fuera de la ventana de referencia')) ($role+' consulta administrativa fuera de ventana')
        Assert-Schedule (-not ($response.Content.Contains('id_paciente') -or $response.Content.Contains('motivo_consulta'))) ($role+' agenda sin datos privados de pacientes')
        foreach ($route in @('/horarios?dia=6','/horarios?medico[]=1','/horarios/agenda?semana=2026-02-30','/horarios/detalle')) {
            $response = Invoke-WebRequest ($base+$route) -WebSession $session -SkipHttpErrorCheck
            Assert-Schedule ($response.StatusCode -eq 400) ($role+' validación de filtros')
        }
        if ($role -eq 'Medico') {
            foreach ($route in @('/horarios?medico=','/horarios/detalle?medico=','/horarios/agenda?medico=','/bloqueos?medico=')) {
                $response = Invoke-WebRequest ($base+$route+$fixtures.other_doctor) -WebSession $session -SkipHttpErrorCheck
                Assert-Schedule ($response.StatusCode -eq 403) 'Médico no accede a otro cambiando URL'
            }
        }
        if (-not $manager) { continue }
        foreach ($route in @('/horarios/nuevo',('/horarios/editar?id='+$fixtures.schedule.id_horario),'/bloqueos/nuevo')) {
            $response = Invoke-WebRequest ($base+$route) -Method Post -Body @{csrf='incorrecto'} -WebSession $session -SkipHttpErrorCheck
            Assert-Schedule ($response.StatusCode -eq 419) 'CSRF obligatorio en P4'
        }
        $invalid = $new.Clone()
        $invalid.dia_semana = '7'
        $response = Invoke-WebRequest ($base+'/horarios/nuevo') -Method Post -Body $invalid -WebSession $session -SkipHttpErrorCheck
        Assert-Schedule ($response.StatusCode -eq 422) 'PHP rechaza día inválido'
        $overlap = $edit.Clone()
        $response = Invoke-WebRequest ($base+'/horarios/nuevo') -Method Post -Body $overlap -WebSession $session -SkipHttpErrorCheck
        Assert-Schedule ($response.StatusCode -eq 422) 'PHP detecta horario superpuesto con datos reales'
        $invalidBlock = $block.Clone()
        $invalidBlock.hora_fin = '00:00'
        $response = Invoke-WebRequest ($base+'/bloqueos/nuevo') -Method Post -Body $invalidBlock -WebSession $session -SkipHttpErrorCheck
        Assert-Schedule ($response.StatusCode -eq 422) 'PHP rechaza bloqueo inválido'
        $response = Invoke-WebRequest ($base+'/bloqueos/editar?id=9223372036854775807') -WebSession $session -SkipHttpErrorCheck
        Assert-Schedule ($response.StatusCode -eq 404) 'Bloqueo inexistente'
        $response = Invoke-WebRequest ($base+'/horarios') -Method Delete -WebSession $session -SkipHttpErrorCheck
        Assert-Schedule ($response.StatusCode -eq 405) 'No hay eliminación física de horarios'
    }
    Write-Output ($checks.ToString()+' comprobaciones HTTP P4 pasaron.')
} finally {
    $env:SCHEDULE_WRITES_ENABLED = $previousFlag
    if ($null -ne $process) { Stop-Process -Id $process.Id -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $fixturesPath) { & php $fixtureTool cleanup; if ($LASTEXITCODE -ne 0) { throw 'Falló la verificación de integridad o limpieza de fixtures.' } }
}
