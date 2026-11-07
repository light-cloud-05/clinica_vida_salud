$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$base='http://127.0.0.1:8093'
$tool=Join-Path $PSScriptRoot 'support/patient-http-fixtures.php'
$fixturePath=Join-Path $root 'var/patient-http-fixtures.json'
$process=$null;$checks=0
function Assert-Booking([bool]$ok,[string]$label){if(-not $ok){throw ('FALLO HTTP P5-B: '+$label)};$script:checks++;Write-Output ('OK HTTP: '+$label)}
try{
    & php -r 'require "backend/bootstrap.php"; exit(Clinica\Support\BookingFactory::enabled() ? 1 : 0);'
    if($LASTEXITCODE -ne 0){throw 'Se exige APPOINTMENT_WRITES_ENABLED deshabilitado antes de probar HTTP.'}
    & php $tool create
    if($LASTEXITCODE -ne 0){throw 'Fallaron las sesiones temporales.'}
    $f=Get-Content -LiteralPath $fixturePath -Raw | ConvertFrom-Json
    $process=Start-Process -FilePath (Get-Command php).Source -WorkingDirectory $root -ArgumentList @('-d','display_errors=0','-S','127.0.0.1:8093','-t','public','tools/dev-router.php') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $root 'var/reservas-http-output.log') -RedirectStandardError (Join-Path $root 'var/reservas-http-error.log')
    Start-Sleep -Seconds 2
    if($process.HasExited){throw 'Servidor temporal no disponible.'}
    $r=Invoke-WebRequest ($base+'/mis-citas/reservar') -SkipHttpErrorCheck -MaximumRedirection 0 -ErrorAction SilentlyContinue
    Assert-Booking ($r.StatusCode -eq 303 -and $r.Headers.Location -eq '/login') 'Reserva exige sesión'
    foreach($role in @('Administrador','Recepcionista','Paciente','Medico')){
        $session=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
        $session.Cookies.Add([Uri]$base,[Net.Cookie]::new('CVS_SESSION',$f.sessions.$role.id,'/'))
        $path=if($role -eq 'Paciente'){'/mis-citas'}else{'/citas'}
        $a=$f.availability.$role
        $query='?medico='+$a.doctor+'&especialidad='+$a.specialty+'&fecha='+$a.date+'&hora='+$a.time
        $r=Invoke-WebRequest ($base+$path+'/reservar'+$query) -WebSession $session -SkipHttpErrorCheck
        if($role -eq 'Medico'){
            Assert-Booking ($r.StatusCode -eq 403) 'Médico no puede reservar'
            $r=Invoke-WebRequest ($base+$path+'/reservar') -Method Post -WebSession $session -SkipHttpErrorCheck
            Assert-Booking ($r.StatusCode -eq 403) 'Médico POST denegado'
            continue
        }
        Assert-Booking ($r.StatusCode -eq 200 -and $r.Content.Contains('booking_token')) ($role+' formulario válido')
        Assert-Booking ($r.Content.Contains('disabled') -and $r.Content.Contains('deshabilitad')) ($role+' escritura bloqueada en interfaz')
        Assert-Booking (-not $r.Content.Contains('password_hash') -and -not $r.Content.Contains('motivo_consulta')) ($role+' datos mínimos')
        $token=[regex]::Match($r.Content,'name="booking_token" value="([a-f0-9]+)"').Groups[1].Value
        Assert-Booking ($token.Length -eq 64) ($role+' intención individual')
        $body=@{medico=[string]$a.doctor;especialidad=[string]$a.specialty;fecha=[string]$a.date;hora=[string]$a.time;booking_token=$token;csrf=[string]$f.sessions.$role.csrf}
        if($role -ne 'Paciente'){$body.paciente=[string]$f.appointments.$role.sample.id_paciente}
        $r=Invoke-WebRequest ($base+$path+'/reservar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
        Assert-Booking ($r.StatusCode -eq 423) ($role+' POST válido bloqueado antes de conectar escritor')
        $body.csrf='invalido'
        $r=Invoke-WebRequest ($base+$path+'/reservar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
        Assert-Booking ($r.StatusCode -eq 419) ($role+' CSRF inválido')
        $body.csrf=[string]$f.sessions.$role.csrf;$body.booking_token='invalido'
        $r=Invoke-WebRequest ($base+$path+'/reservar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
        Assert-Booking ($r.StatusCode -eq 409) ($role+' intención inválida')
        if($role -eq 'Paciente'){
            Assert-Booking (-not (Invoke-WebRequest ($base+$path+'/reservar'+$query) -WebSession $session).Content.Contains('name="paciente"')) 'Paciente sin selector de titular'
            $body.paciente=[string]$f.appointments.$role.foreign.id_paciente
            $r=Invoke-WebRequest ($base+$path+'/reservar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
            Assert-Booking ($r.StatusCode -eq 403) 'Paciente no falsifica titular'
            $r=Invoke-WebRequest ($base+$path+'/confirmacion?id='+$f.appointments.$role.foreign.id_cita) -WebSession $session -SkipHttpErrorCheck
            Assert-Booking ($r.StatusCode -eq 404) 'Confirmación ajena oculta'
        }
        $r=Invoke-WebRequest ($base+$path+'/confirmacion?id='+$f.appointments.$role.sample.id_cita) -WebSession $session -SkipHttpErrorCheck
        Assert-Booking ($r.StatusCode -eq 200 -and $r.Content.Contains($f.appointments.$role.sample.codigo_cita)) ($role+' confirmación dentro de ámbito')
    }
    Write-Output ($checks.ToString()+' comprobaciones HTTP P5-B pasaron; ninguna escritura habilitada.')
}finally{
    if($null -ne $process){Stop-Process -Id $process.Id -ErrorAction SilentlyContinue}
    if(Test-Path -LiteralPath $fixturePath){& php $tool cleanup;if($LASTEXITCODE -ne 0){throw 'Falló integridad de fixtures.'}}
}
