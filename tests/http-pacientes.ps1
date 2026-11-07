$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$port = 8089
$base = 'http://127.0.0.1:' + $port
$fixturesPath = Join-Path $root 'var/patient-http-fixtures.json'
$fixtureTool = Join-Path $PSScriptRoot 'support/patient-http-fixtures.php'
$checks = 0
$process = $null
$oldWriteSetting = $env:PATIENT_WRITES_ENABLED
function Assert-PatientCheck([bool]$ok, [string]$label) {
    if (-not $ok) { throw ('FALLO HTTP: ' + $label) }
    $script:checks++
    Write-Output ('OK HTTP: ' + $label)
}
try {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, $port)
    $listener.Start()
    $listener.Stop()
    & php $fixtureTool create
    if ($LASTEXITCODE -ne 0) { throw 'No se pudieron preparar las sesiones de prueba.' }
    $fixtures = Get-Content -LiteralPath $fixturesPath -Raw | ConvertFrom-Json
    # El servidor temporal impone guardado desactivado, aunque otro proceso tenga otra configuración.
    $env:PATIENT_WRITES_ENABLED = 'false'
    $process = Start-Process -FilePath (Get-Command php).Source -WorkingDirectory $root -ArgumentList @('-d','display_errors=0','-S',('127.0.0.1:'+$port),'-t','public','tools/dev-router.php') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $root 'var/patient-http-output.log') -RedirectStandardError (Join-Path $root 'var/patient-http-error.log')
    $env:PATIENT_WRITES_ENABLED = $oldWriteSetting
    Start-Sleep -Seconds 2
    if ($process.HasExited) { throw 'No se pudo iniciar el servidor temporal.' }
    $response = Invoke-WebRequest ($base+'/pacientes') -SkipHttpErrorCheck -MaximumRedirection 0 -ErrorAction SilentlyContinue
    Assert-PatientCheck ($response.StatusCode -eq 303 -and $response.Headers.Location -eq '/login') 'Sin sesión redirige a login'
    foreach ($role in @('Administrador','Recepcionista','Medico','Paciente')) {
        $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
        $session.Cookies.Add([Uri]$base,[Net.Cookie]::new('CVS_SESSION',$fixtures.sessions.$role.id,'/'))
        $csrf = $fixtures.sessions.$role.csrf
        $allowed = $role -in @('Administrador','Recepcionista')
        foreach ($route in @('/pacientes',('/pacientes/detalle?id='+$fixtures.patient_id),'/pacientes/nuevo',('/pacientes/editar?id='+$fixtures.patient_id))) {
            $response = Invoke-WebRequest ($base+$route) -WebSession $session -SkipHttpErrorCheck
            Assert-PatientCheck ($response.StatusCode -eq $(if ($allowed) {200} else {403})) ($role+' GET '+($route -split '\?')[0])
        }
        $body = @{csrf=$csrf;codigo_paciente='SOLO-PRUEBA-HTTP';documento_identidad='SOLO-PRUEBA-HTTP';nombres='Simulación';apellidos='Sin escritura';estado='activo'}
        foreach ($route in @('/pacientes/nuevo',('/pacientes/editar?id='+$fixtures.patient_id))) {
            $response = Invoke-WebRequest ($base+$route) -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
            Assert-PatientCheck ($response.StatusCode -eq $(if ($allowed) {423} else {403})) ($role+' POST bloqueado sin escritura '+($route -split '\?')[0])
        }
        if (-not $allowed) { continue }
        $ids = [Collections.Generic.HashSet[string]]::new()
        foreach ($page in 1..3) {
            $response = Invoke-WebRequest ($base+'/pacientes?pagina='+$page) -WebSession $session -SkipHttpErrorCheck
            $matches = [regex]::Matches($response.Content,'href="/pacientes/detalle\?id=([0-9]+)"')
            foreach ($match in $matches) { [void]$ids.Add($match.Groups[1].Value) }
            Assert-PatientCheck ($response.StatusCode -eq 200 -and $matches.Count -eq 10 -and $response.Content.Contains('data-patient-total="30"')) ($role+' listado página '+$page)
        }
        Assert-PatientCheck ($ids.Count -eq 30) ($role+' recupera 30 pacientes distintos')
        $response = Invoke-WebRequest ($base+'/pacientes?q=__NO_EXISTE_HTTP_8F3X') -WebSession $session -SkipHttpErrorCheck
        Assert-PatientCheck ($response.StatusCode -eq 200 -and $response.Content.Contains('No hay pacientes para mostrar')) ($role+' búsqueda sin resultados')
        foreach ($query in @('id=0','id=abc','id[]=1','id=999999999999999999999999')) {
            $response = Invoke-WebRequest ($base+'/pacientes/detalle?'+$query) -WebSession $session -SkipHttpErrorCheck
            Assert-PatientCheck ($response.StatusCode -eq 400) ($role+' rechaza identificador inválido')
        }
        $response = Invoke-WebRequest ($base+'/pacientes/detalle?id=9223372036854775807') -WebSession $session -SkipHttpErrorCheck
        Assert-PatientCheck ($response.StatusCode -eq 404) ($role+' paciente inexistente')
        $response = Invoke-WebRequest ($base+'/pacientes/nuevo') -Method Post -Body @{csrf='incorrecto'} -WebSession $session -SkipHttpErrorCheck
        Assert-PatientCheck ($response.StatusCode -eq 419) ($role+' CSRF de alta rechazado')
        $response = Invoke-WebRequest ($base+'/pacientes/editar?id='+$fixtures.patient_id) -Method Post -Body @{csrf='incorrecto'} -WebSession $session -SkipHttpErrorCheck
        Assert-PatientCheck ($response.StatusCode -eq 419) ($role+' CSRF de edición rechazado')
        $response = Invoke-WebRequest ($base+'/pacientes/nuevo') -Method Post -Body @{csrf=$csrf;nombres=''} -WebSession $session -SkipHttpErrorCheck
        Assert-PatientCheck ($response.StatusCode -eq 422 -and $response.Content.Contains('No se guardaron cambios.')) ($role+' validación PHP obligatoria')
        $response = Invoke-WebRequest ($base+'/pacientes') -Method Delete -WebSession $session -SkipHttpErrorCheck
        Assert-PatientCheck ($response.StatusCode -eq 405) ($role+' eliminación física no implementada')
        $panel = if ($role -eq 'Administrador') {'/administrador'} else {'/recepcionista'}
        $response = Invoke-WebRequest ($base+$panel) -WebSession $session -SkipHttpErrorCheck
        Assert-PatientCheck ($response.StatusCode -eq 200 -and $response.Content.Contains('href="/pacientes"')) ($role+' enlace desde su panel')
    }
    Write-Output ($checks.ToString()+' comprobaciones HTTP de pacientes pasaron.')
} finally {
    $env:PATIENT_WRITES_ENABLED = $oldWriteSetting
    if ($null -ne $process) { Stop-Process -Id $process.Id -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $fixturesPath) {
        & php $fixtureTool cleanup
        if ($LASTEXITCODE -ne 0) { throw 'Falló la verificación de ausencia de cambios o la limpieza de fixtures.' }
    }
}
