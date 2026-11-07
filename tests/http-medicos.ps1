$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$base = 'http://127.0.0.1:8090'
$fixturesPath = Join-Path $root 'var/patient-http-fixtures.json'
$fixtureTool = Join-Path $PSScriptRoot 'support/patient-http-fixtures.php'
$checks = 0
$process = $null
$oldMedical = $env:MEDICAL_WRITES_ENABLED
$oldPatient = $env:PATIENT_WRITES_ENABLED
function Assert-Medical([bool]$ok, [string]$label) {
    if (-not $ok) { throw ('FALLO HTTP P3: ' + $label) }
    $script:checks++
    Write-Output ('OK HTTP: ' + $label)
}
function ConvertTo-MedicalForm([hashtable]$values) {
    $pairs = [Collections.Generic.List[string]]::new()
    foreach ($key in $values.Keys) {
        foreach ($value in @($values[$key])) {
            $pairs.Add([Uri]::EscapeDataString($key)+'='+[Uri]::EscapeDataString([string]$value))
        }
    }
    return $pairs -join '&'
}
try {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,8090)
    $listener.Start()
    $listener.Stop()
    & php $fixtureTool create
    if ($LASTEXITCODE -ne 0) { throw 'No se pudieron preparar sesiones de prueba.' }
    $fixtures = Get-Content -LiteralPath $fixturesPath -Raw | ConvertFrom-Json
    $env:MEDICAL_WRITES_ENABLED = 'false'
    $env:PATIENT_WRITES_ENABLED = 'false'
    $process = Start-Process -FilePath (Get-Command php).Source -WorkingDirectory $root -ArgumentList @('-d','display_errors=0','-S','127.0.0.1:8090','-t','public','tools/dev-router.php') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $root 'var/medical-http-output.log') -RedirectStandardError (Join-Path $root 'var/medical-http-error.log')
    $env:MEDICAL_WRITES_ENABLED = $oldMedical
    $env:PATIENT_WRITES_ENABLED = $oldPatient
    Start-Sleep -Seconds 2
    if ($process.HasExited) { throw 'No se pudo iniciar el servidor temporal.' }
    foreach ($route in @('/medicos','/especialidades')) {
        $response = Invoke-WebRequest ($base+$route) -SkipHttpErrorCheck -MaximumRedirection 0 -ErrorAction SilentlyContinue
        Assert-Medical ($response.StatusCode -eq 303 -and $response.Headers.Location -eq '/login') ('Sin sesión '+$route)
    }
    foreach ($role in @('Administrador','Recepcionista','Medico','Paciente')) {
        $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
        $session.Cookies.Add([Uri]$base,[Net.Cookie]::new('CVS_SESSION',$fixtures.sessions.$role.id,'/'))
        $csrf = $fixtures.sessions.$role.csrf
        $allowed = $role -eq 'Administrador'
        $doctorId = $fixtures.doctor_id
        $specialtyId = $fixtures.specialty_id
        foreach ($route in @('/medicos',('/medicos/detalle?id='+$doctorId),'/medicos/nuevo',('/medicos/editar?id='+$doctorId),'/especialidades','/especialidades/nueva',('/especialidades/editar?id='+$specialtyId))) {
            $response = Invoke-WebRequest ($base+$route) -WebSession $session -SkipHttpErrorCheck
            Assert-Medical ($response.StatusCode -eq $(if($allowed){200}else{403})) ($role+' GET '+($route -split '\?')[0])
        }
        $doctorBody = @{csrf=$csrf;codigo_medico='P3-SOLO-HTTP';registro_profesional='P3-SOLO-HTTP';nombres='Prueba';apellidos='Sin guardar';estado='activo';'especialidades[]'=@($fixtures.doctor_specialties)}
        $specialtyBody = @{csrf=$csrf;nombre='P3-SOLO-HTTP-SIN-GUARDAR';precio_consulta='70.50';estado='activo'}
        foreach ($operation in @(@{route='/medicos/nuevo';body=$doctorBody},@{route=('/medicos/editar?id='+$doctorId);body=$doctorBody},@{route='/especialidades/nueva';body=$specialtyBody},@{route=('/especialidades/editar?id='+$specialtyId);body=$specialtyBody})) {
            $response = Invoke-WebRequest ($base+$operation.route) -Method Post -Body (ConvertTo-MedicalForm $operation.body) -ContentType 'application/x-www-form-urlencoded' -WebSession $session -SkipHttpErrorCheck
            Assert-Medical ($response.StatusCode -eq $(if($allowed){423}else{403})) ($role+' POST bloqueado '+($operation.route -split '\?')[0]+' ['+$response.StatusCode+']')
        }
        $panel = @{Administrador='/administrador';Recepcionista='/recepcionista';Medico='/medico';Paciente='/paciente'}[$role]
        $response = Invoke-WebRequest ($base+$panel) -WebSession $session -SkipHttpErrorCheck
        Assert-Medical ($response.StatusCode -eq 200 -and $response.Content.Contains('href="/medicos"') -eq $allowed) ($role+' navegación autorizada')
        if (-not $allowed) { continue }
        $ids = [Collections.Generic.HashSet[string]]::new()
        foreach ($page in 1..2) {
            $response = Invoke-WebRequest ($base+'/medicos?pagina='+$page) -WebSession $session -SkipHttpErrorCheck
            $matches = [regex]::Matches($response.Content,'href="/medicos/detalle\?id=([0-9]+)"')
            foreach ($match in $matches) { [void]$ids.Add($match.Groups[1].Value) }
            Assert-Medical ($response.StatusCode -eq 200 -and $matches.Count -eq $(if($page -eq 1){10}else{8}) -and $response.Content.Contains('data-doctor-total="18"')) ('Paginación de médicos '+$page)
        }
        Assert-Medical ($ids.Count -eq 18) '18 médicos distintos por HTTP'
        $response = Invoke-WebRequest ($base+'/especialidades') -WebSession $session -SkipHttpErrorCheck
        Assert-Medical ($response.Content.Contains('data-specialty-total="9"') -and [regex]::Matches($response.Content,'class="patient-card specialty-card"').Count -eq 9) '9 especialidades por HTTP'
        $response = Invoke-WebRequest ($base+'/medicos?especialidad='+$specialtyId) -WebSession $session -SkipHttpErrorCheck
        Assert-Medical ($response.StatusCode -eq 200 -and $response.Content.Contains(('data-doctor-total="'+$fixtures.filtered_count+'"'))) 'Filtro real por especialidad'
        $response = Invoke-WebRequest ($base+'/medicos?q=__NO_EXISTE_HTTP_P3_8X') -WebSession $session -SkipHttpErrorCheck
        Assert-Medical ($response.StatusCode -eq 200 -and $response.Content.Contains('No hay médicos para mostrar')) 'Búsqueda sin resultados'
        foreach ($route in @('/medicos/detalle?id=0','/medicos/editar?id[]=1','/especialidades/editar?id=abc','/medicos?especialidad[]=1','/medicos?pagina=-1')) {
            $response = Invoke-WebRequest ($base+$route) -WebSession $session -SkipHttpErrorCheck
            Assert-Medical ($response.StatusCode -eq 400) 'Identificador o filtro inválido'
        }
        foreach ($route in @('/medicos/detalle?id=9223372036854775807','/especialidades/editar?id=9223372036854775807','/medicos?especialidad=9223372036854775807')) {
            $response = Invoke-WebRequest ($base+$route) -WebSession $session -SkipHttpErrorCheck
            Assert-Medical ($response.StatusCode -eq 404) 'Registro inexistente'
        }
        foreach ($route in @('/medicos/nuevo',('/medicos/editar?id='+$doctorId),'/especialidades/nueva',('/especialidades/editar?id='+$specialtyId))) {
            $response = Invoke-WebRequest ($base+$route) -Method Post -Body @{csrf='incorrecto'} -WebSession $session -SkipHttpErrorCheck
            Assert-Medical ($response.StatusCode -eq 419) 'CSRF obligatorio en escrituras'
        }
        $response = Invoke-WebRequest ($base+'/medicos/nuevo') -Method Post -Body @{csrf=$csrf;nombres=''} -WebSession $session -SkipHttpErrorCheck
        Assert-Medical ($response.StatusCode -eq 422) 'Validación PHP de médico'
        $invalidPrice = @{csrf=$csrf;nombre='P3-SIN-GUARDAR';precio_consulta='0';estado='activo'}
        $response = Invoke-WebRequest ($base+'/especialidades/nueva') -Method Post -Body $invalidPrice -WebSession $session -SkipHttpErrorCheck
        Assert-Medical ($response.StatusCode -eq 422) 'Validación PHP de precio'
        foreach ($route in @('/medicos','/especialidades')) {
            $response = Invoke-WebRequest ($base+$route) -Method Delete -WebSession $session -SkipHttpErrorCheck
            Assert-Medical ($response.StatusCode -eq 405) 'Eliminación física no implementada'
        }
    }
    Write-Output ($checks.ToString()+' comprobaciones HTTP P3 pasaron.')
} finally {
    $env:MEDICAL_WRITES_ENABLED = $oldMedical
    $env:PATIENT_WRITES_ENABLED = $oldPatient
    if ($null -ne $process) { Stop-Process -Id $process.Id -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $fixturesPath) {
        & php $fixtureTool cleanup
        if ($LASTEXITCODE -ne 0) { throw 'Falló la verificación de ausencia de cambios o la limpieza de fixtures.' }
    }
}
