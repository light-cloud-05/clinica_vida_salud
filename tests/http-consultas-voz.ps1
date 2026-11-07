$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$base='http://127.0.0.1:8097'
$tool=Join-Path $PSScriptRoot 'support/patient-http-fixtures.php'
$fixturePath=Join-Path $root 'var/patient-http-fixtures.json'
$process=$null;$checks=0;$created=$false
function Assert-Voice([bool]$ok,[string]$label){if(-not $ok){throw ('FALLO HTTP P10: '+$label)};$script:checks++;Write-Output ('OK HTTP: '+$label)}
if(Test-Path -LiteralPath $fixturePath){throw 'Ya existen fixtures privadas: no se sobrescribirán ni eliminarán.'}
try {
    & php -r 'require "backend/bootstrap.php"; foreach(["PAYMENT_WRITES_ENABLED","APPOINTMENT_WRITES_ENABLED","APPOINTMENT_CHANGES_ENABLED","PATIENT_WRITES_ENABLED","MEDICAL_WRITES_ENABLED","SCHEDULE_WRITES_ENABLED"] as $f){if(Clinica\Support\Environment::get($f)==="true"){exit(1);}}'
    if($LASTEXITCODE -ne 0){throw 'Se exige guardado deshabilitado.'}
    & php $tool create
    if($LASTEXITCODE -ne 0){throw 'Fallaron las sesiones temporales.'}
    $created=$true
    $f=Get-Content -LiteralPath $fixturePath -Raw | ConvertFrom-Json -DateKind String
    $process=Start-Process -FilePath (Get-Command php).Source -WorkingDirectory $root -ArgumentList @('-d','display_errors=0','-S','127.0.0.1:8097','-t','public','tools/dev-router.php') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $root 'var/voz-http-output.log') -RedirectStandardError (Join-Path $root 'var/voz-http-error.log')
    Start-Sleep -Seconds 2
    if($process.HasExited){throw 'Servidor temporal no disponible.'}
    $r=Invoke-WebRequest ($base+'/consultas-voz') -SkipHttpErrorCheck -MaximumRedirection 0 -ErrorAction SilentlyContinue
    Assert-Voice ($r.StatusCode -eq 303 -and $r.Headers.Location -eq '/login') 'GET exige sesión'
    $r=Invoke-WebRequest ($base+'/consultas-voz/consultar') -Method Post -Body @{consulta='Mis próximas citas'} -SkipHttpErrorCheck -MaximumRedirection 0 -ErrorAction SilentlyContinue
    Assert-Voice ($r.StatusCode -eq 303 -and $r.Headers.Location -eq '/login') 'POST exige sesión'
    $panels=@{Administrador='/administrador';Recepcionista='/recepcionista';Paciente='/paciente';Medico='/medico'}
    foreach($role in @('Administrador','Recepcionista','Paciente','Medico')){
        $session=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
        $session.Cookies.Add([Uri]$base,[Net.Cookie]::new('CVS_SESSION',$f.sessions.$role.id,'/'))
        $r=Invoke-WebRequest ($base+$panels[$role]) -WebSession $session -SkipHttpErrorCheck
        Assert-Voice ($r.StatusCode -eq 200 -and $r.Content.Contains('href="/consultas-voz"')) ($role+' acceso desde panel')
        $r=Invoke-WebRequest ($base+'/consultas-voz') -WebSession $session -SkipHttpErrorCheck
        Assert-Voice ($r.StatusCode -eq 200 -and $r.Content.Contains('name="consulta"') -and $r.Content.Contains('action="/consultas-voz/consultar"') -and $r.Content.Contains('name="csrf"')) ($role+' formulario escrito nativo con CSRF')
        Assert-Voice ($r.Content.Contains('<noscript>') -and $r.Content.Contains('servicios externos') -and $r.Content.Contains('no guarda grabaciones')) ($role+' alternativa sin JavaScript y privacidad')
        $r=Invoke-WebRequest ($base+'/consultas-voz/consultar') -WebSession $session -SkipHttpErrorCheck
        Assert-Voice ($r.StatusCode -eq 405) ($role+' endpoint exige POST')
        $body=@{csrf=[string]$f.sessions.$role.csrf;consulta='¿Cuáles son mis próximas citas?';fecha=''}
        $r=Invoke-WebRequest ($base+'/consultas-voz/consultar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
        Assert-Voice ($r.StatusCode -eq 200 -and $r.Content.Contains('Próximas citas autorizadas') -and $r.Content.Contains('PostgreSQL')) ($role+' consulta escrita real sin ejecutar JavaScript')
        $codes=@([regex]::Matches($r.Content,'<td>((?:CITA-|CVS-)[A-Z0-9-]+)</td>') | ForEach-Object {$_.Groups[1].Value})
        Assert-Voice ($codes.Count -le 10 -and @($codes | Where-Object {$_ -notin $f.appointments.$role.codes}).Count -eq 0) ($role+' solo citas dentro de ámbito real')
        Assert-Voice (-not $r.Content.Contains('motivo_consulta') -and -not $r.Content.Contains('password_hash') -and -not $r.Content.Contains('documento_identidad')) ($role+' proyección mínima')
        foreach($question in @('Muéstrame mi agenda de hoy.','¿Qué médicos atienden medicina general?','¿Qué horarios hay disponibles?','¿Qué médicos atienden pediatría?')){
            $body.consulta=$question
            $r=Invoke-WebRequest ($base+'/consultas-voz/consultar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
            if($r.StatusCode -ne 200){Write-Output ('Estado inesperado: '+$r.StatusCode)}
            Assert-Voice ($r.StatusCode -eq 200 -and $r.Content.Contains('data-voice-answer')) ($role+' '+$question)
            if($question.Contains('pediatría')){Assert-Voice ($r.Content.Contains('Médicos autorizados por especialidad')) ($role+' Pediatría del catálogo real')}
        }
        $body.consulta='Que médicos atienden especialidad inexistente'
        $r=Invoke-WebRequest ($base+'/consultas-voz/consultar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
        Assert-Voice ($r.StatusCode -eq 200 -and $r.Content.Contains('No se encontró esa especialidad') -and -not $r.Content.Contains('<td>')) ($role+' especialidad inexistente no inventada')
        $body.consulta='¿Cuál es el estado de mi pago?'
        $r=Invoke-WebRequest ($base+'/consultas-voz/consultar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
        Assert-Voice ($r.StatusCode -eq $(if($role -eq 'Medico'){403}else{200})) ($role+' permiso de pagos')
        if($role -ne 'Medico'){Assert-Voice ($r.Content.Contains('No hay pagos registrados en PostgreSQL') -and $r.Content.Contains('ensayos locales de P9')) ($role+' sin confundir pagos reales con demostraciones')}
        foreach($bad in @('Cancela mi cita','SELECT * FROM usuarios','Muéstrame la agenda del médico 999',('<script>alert(1)</script>'),('x'*241),'')){
            $body.consulta=$bad
            $r=Invoke-WebRequest ($base+'/consultas-voz/consultar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
            Assert-Voice ($r.StatusCode -eq 422 -and -not $r.Content.Contains('<script>alert(1)</script>')) ($role+' entrada no autorizada o inválida')
        }
        $body.consulta='¿Qué horarios hay disponibles?';$body.fecha='2026-02-30'
        $r=Invoke-WebRequest ($base+'/consultas-voz/consultar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
        Assert-Voice ($r.StatusCode -eq 422) ($role+' fecha inválida')
        $body.fecha=[string]$f.availability.$role.date
        $r=Invoke-WebRequest ($base+'/consultas-voz/consultar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
        Assert-Voice ($r.StatusCode -eq 200 -and $r.Content.Contains($body.fecha)) ($role+' fecha válida en ventana P5')
        if($f.availability.$role.time){Assert-Voice ($r.Content.Contains('<td>') -and $r.Content.Contains('franjas disponibles')) ($role+' franjas reales con agenda configurada')}
        $body.fecha='';$body.csrf='incorrecto'
        $r=Invoke-WebRequest ($base+'/consultas-voz/consultar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
        Assert-Voice ($r.StatusCode -eq 419) ($role+' CSRF inválido')
        $body.csrf=[string]$f.sessions.$role.csrf;$body.id_usuario='999'
        $r=Invoke-WebRequest ($base+'/consultas-voz/consultar') -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
        Assert-Voice ($r.StatusCode -eq 422) ($role+' no acepta cambio de identidad del navegador')
        $r=Invoke-WebRequest ($base+'/assets/js/consultas-voz.js') -WebSession $session -SkipHttpErrorCheck
        Assert-Voice ($r.StatusCode -eq 200 -and $r.Content.Contains("recognition.lang = 'es-BO'")) ($role+' recurso JavaScript publicado')
    }
    Write-Output ($checks.ToString()+' comprobaciones HTTP P10: cuatro cuentas existentes, texto nativo y PostgreSQL de solo lectura.')
} finally {
    if($null -ne $process){Stop-Process -Id $process.Id -ErrorAction SilentlyContinue}
    if($created -and (Test-Path -LiteralPath $fixturePath)){& php $tool cleanup;if($LASTEXITCODE -ne 0){throw 'Falló integridad de fixtures.'}}
}
