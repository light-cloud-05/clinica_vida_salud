$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$base = 'http://127.0.0.1:8092'
$fixturesPath = Join-Path $root 'var/patient-http-fixtures.json'
$fixtureTool = Join-Path $PSScriptRoot 'support/patient-http-fixtures.php'
$process = $null
$checks = 0
function Assert-Cita([bool]$ok,[string]$label) {
    if (-not $ok) { throw ('FALLO HTTP P5-A: '+$label) }
    $script:checks++
    Write-Output ('OK HTTP: '+$label)
}
try {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,8092)
    $listener.Start()
    $listener.Stop()
    & php $fixtureTool create
    if ($LASTEXITCODE -ne 0) { throw 'No se pudieron preparar sesiones de prueba.' }
    $fixtures = Get-Content -LiteralPath $fixturesPath -Raw | ConvertFrom-Json
    $process = Start-Process -FilePath (Get-Command php).Source -WorkingDirectory $root -ArgumentList @('-d','display_errors=0','-S','127.0.0.1:8092','-t','public','tools/dev-router.php') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $root 'var/citas-http-output.log') -RedirectStandardError (Join-Path $root 'var/citas-http-error.log')
    Start-Sleep -Seconds 2
    if ($process.HasExited) { throw 'No se pudo iniciar el servidor temporal.' }
    foreach ($route in @('/citas','/mis-citas','/citas/disponibilidad','/mis-citas/disponibilidad')) {
        $response = Invoke-WebRequest ($base+$route) -SkipHttpErrorCheck -MaximumRedirection 0 -ErrorAction SilentlyContinue
        Assert-Cita ($response.StatusCode -eq 303 -and $response.Headers.Location -eq '/login') ('Sin sesión '+$route)
    }
    foreach ($role in @('Administrador','Recepcionista','Medico','Paciente')) {
        $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
        $session.Cookies.Add([Uri]$base,[Net.Cookie]::new('CVS_SESSION',$fixtures.sessions.$role.id,'/'))
        $path = if ($role -eq 'Paciente') {'/mis-citas'} else {'/citas'}
        $sample = $fixtures.appointments.$role.sample
        $total = [int]$fixtures.appointments.$role.total
        $pages = [Math]::Max(1,[Math]::Ceiling($total/10))
        $ids = [Collections.Generic.HashSet[string]]::new()
        foreach ($page in 1..$pages) {
            $response = Invoke-WebRequest ($base+$path+'?pagina='+$page) -WebSession $session -SkipHttpErrorCheck
            Assert-Cita ($response.StatusCode -eq 200 -and $response.Content.Contains(('data-appointment-total="'+$total+'"'))) ($role+' listado página '+$page)
            $matches = [regex]::Matches($response.Content,'href="'+$path+'/detalle\?id=([0-9]+)"')
            foreach ($match in $matches) { [void]$ids.Add($match.Groups[1].Value) }
            Assert-Cita (-not ($response.Content.Contains('documento_identidad') -or $response.Content.Contains('motivo_consulta') -or $response.Content.Contains('referencia_pago'))) ($role+' datos mínimos')
        }
        Assert-Cita ($ids.Count -eq $total -and @($fixtures.appointments.$role.ids | Where-Object { -not $ids.Contains([string]$_) }).Count -eq 0) ($role+' ámbito completo y sin duplicados')
        $response = Invoke-WebRequest ($base+$path+'/detalle?id='+$sample.id_cita) -WebSession $session -SkipHttpErrorCheck
        Assert-Cita ($response.StatusCode -eq 200 -and $response.Content.Contains($sample.codigo_cita)) ($role+' detalle propio autorizado')
        foreach ($field in @('fecha','medico','especialidad','paciente','estado')) {
            $value = switch ($field) { 'medico' {$sample.id_medico} 'especialidad' {$sample.id_especialidad} 'paciente' {$sample.id_paciente} default {$sample.$field} }
            $response = Invoke-WebRequest ($base+$path+'?'+$field+'='+$value) -WebSession $session -SkipHttpErrorCheck
            Assert-Cita ($response.StatusCode -eq 200 -and $response.Content.Contains($sample.codigo_cita)) ($role+' filtro '+$field)
        }
        $response = Invoke-WebRequest ($base+$path+'?q='+[Uri]::EscapeDataString($sample.codigo_cita)) -WebSession $session -SkipHttpErrorCheck
        Assert-Cita ($response.StatusCode -eq 200 -and $response.Content.Contains('data-appointment-total="1"')) ($role+' búsqueda por código')
        $response = Invoke-WebRequest ($base+$path+'?q=__NO_EXISTE_P5A_89X') -WebSession $session -SkipHttpErrorCheck
        Assert-Cita ($response.StatusCode -eq 200 -and $response.Content.Contains('No hay citas para mostrar')) ($role+' sin resultados')
        foreach ($query in @('pagina=0','fecha=2026-02-30','estado=pendiente','paciente[]=1','medico=-1')) {
            $response = Invoke-WebRequest ($base+$path+'?'+$query) -WebSession $session -SkipHttpErrorCheck
            Assert-Cita ($response.StatusCode -eq 400) ($role+' filtros inválidos')
        }
        if ($role -in @('Medico','Paciente')) {
            $foreign = $fixtures.appointments.$role.foreign
            $response = Invoke-WebRequest ($base+$path+'/detalle?id='+$foreign.id_cita) -WebSession $session -SkipHttpErrorCheck
            Assert-Cita ($response.StatusCode -eq 404) ($role+' detalle ajeno no visible')
        }
        if ($role -eq 'Paciente') {
            $response = Invoke-WebRequest ($base+'/mis-citas?paciente='+$fixtures.appointments.$role.foreign.id_paciente) -WebSession $session -SkipHttpErrorCheck
            Assert-Cita ($response.StatusCode -eq 403) 'Paciente no filtra otro titular'
            $response = Invoke-WebRequest ($base+'/mis-citas?rol=Administrador&id_usuario=1') -WebSession $session -SkipHttpErrorCheck
            Assert-Cita ($response.StatusCode -eq 200 -and $response.Content.Contains(('data-appointment-total="'+$total+'"'))) 'No se puede suplantar identidad desde URL'
            $response = Invoke-WebRequest ($base+'/citas/agenda') -WebSession $session -SkipHttpErrorCheck
            Assert-Cita ($response.StatusCode -eq 403) 'Paciente no entra a agenda del personal'
        } else {
            $response = Invoke-WebRequest ($base+'/citas/agenda?medico='+$sample.id_medico+'&fecha='+$sample.fecha) -WebSession $session -SkipHttpErrorCheck
            Assert-Cita ($response.StatusCode -eq 200 -and $response.Content.Contains($sample.codigo_cita)) ($role+' agenda por médico y fecha')
        }
        $availability = $fixtures.availability.$role
        $url = $base+$path+'/disponibilidad?medico='+$availability.doctor+'&especialidad='+$availability.specialty+'&fecha='+$availability.date
        $response = Invoke-WebRequest $url -WebSession $session -SkipHttpErrorCheck
        Assert-Cita ($response.StatusCode -eq 200 -and $response.Content.Contains('data-available="true"')) ($role+' franjas futuras libres')
        Assert-Cita (-not ($response.Content.Contains('paciente_nombre') -or $response.Content.Contains('id_paciente') -or $response.Content.Contains('motivo_consulta'))) ($role+' disponibilidad sin pacientes ajenos')
        $response = Invoke-WebRequest ($url+'&hora='+[Uri]::EscapeDataString($availability.time)) -WebSession $session -SkipHttpErrorCheck
        Assert-Cita ($response.StatusCode -eq 200 -and $response.Content.Contains('Franja consultada:')) ($role+' selección informativa sin reservar')
        $response = Invoke-WebRequest ($url+'&hora=23%3A59') -WebSession $session -SkipHttpErrorCheck
        Assert-Cita ($response.StatusCode -eq 200 -and $response.Content.Contains('La hora seleccionada no está disponible')) ($role+' hora no válida para el turno')
        $outside = $base+$path+'/disponibilidad?medico='+$availability.doctor+'&especialidad='+$availability.specialty+'&fecha=2030-11-04'
        $response = Invoke-WebRequest $outside -WebSession $session -SkipHttpErrorCheck
        Assert-Cita ($response.StatusCode -eq 200 -and $response.Content.Contains('fuera de la ventana') -and -not $response.Content.Contains('data-available="true"')) ($role+' fuera de ventana sin ofrecer horas')
        foreach ($method in @('Post','Put','Delete')) {
            $response = Invoke-WebRequest ($base+$path) -Method $method -WebSession $session -SkipHttpErrorCheck
            Assert-Cita ($response.StatusCode -eq 405) ($role+' sin escritura '+$method)
        }
        foreach ($suffix in @('/nueva','/cancelar','/reprogramar')) {
            $response = Invoke-WebRequest ($base+$path+$suffix) -WebSession $session -SkipHttpErrorCheck
            Assert-Cita ($response.StatusCode -eq 404) ($role+' fuera de alcance '+$suffix)
        }
        $panel = @{Administrador='/administrador';Recepcionista='/recepcionista';Medico='/medico';Paciente='/paciente'}[$role]
        $response = Invoke-WebRequest ($base+$panel) -WebSession $session -SkipHttpErrorCheck
        Assert-Cita ($response.Content.Contains(('href="'+$path+'"'))) ($role+' enlace de panel')
    }
    Write-Output ($checks.ToString()+' comprobaciones HTTP P5-A pasaron.')
} finally {
    if ($null -ne $process) { Stop-Process -Id $process.Id -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $fixturesPath) { & php $fixtureTool cleanup; if ($LASTEXITCODE -ne 0) { throw 'Falló la comparación de integridad o limpieza de fixtures.' } }
}
