$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$base='http://127.0.0.1:8096'
$tool=Join-Path $PSScriptRoot 'support/patient-http-fixtures.php'
$fixturePath=Join-Path $root 'var/patient-http-fixtures.json'
$process=$null;$checks=0
$storeName='http-p9-'+[Guid]::NewGuid().ToString('N')
$previousStore=$env:PAYMENT_DEMO_STORE_NAME
function Assert-Payment([bool]$ok,[string]$label){if(-not $ok){throw ('FALLO HTTP P9: '+$label)};$script:checks++;Write-Output ('OK HTTP: '+$label)}
try {
    & php -r 'require "backend/bootstrap.php"; foreach(["PAYMENT_WRITES_ENABLED","APPOINTMENT_WRITES_ENABLED","APPOINTMENT_CHANGES_ENABLED","PAYMENT_SANDBOX_ENABLED"] as $f){if(Clinica\Support\Environment::get($f)==="true"){exit(1);}}'
    if($LASTEXITCODE -ne 0){throw 'Escrituras o Sandbox habilitados: no se prueba.'}
    if(-not (Test-Path -LiteralPath $fixturePath)){
        & php $tool create
        if($LASTEXITCODE -ne 0){throw 'Fallaron sesiones temporales.'}
    }
    $f=Get-Content -LiteralPath $fixturePath -Raw | ConvertFrom-Json -DateKind String
    $env:PAYMENT_DEMO_STORE_NAME=$storeName
    $process=Start-Process -FilePath (Get-Command php).Source -WorkingDirectory $root -ArgumentList @('-d','display_errors=0','-S','127.0.0.1:8096','-t','public','tools/dev-router.php') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $root 'var/pagos-http-output.log') -RedirectStandardError (Join-Path $root 'var/pagos-http-error.log')
    Start-Sleep -Seconds 2
    if($process.HasExited){throw 'Servidor temporal no disponible.'}
    $r=Invoke-WebRequest ($base+'/mis-pagos') -SkipHttpErrorCheck -MaximumRedirection 0 -ErrorAction SilentlyContinue
    Assert-Payment ($r.StatusCode -eq 303 -and $r.Headers.Location -eq '/login') 'Exige sesión'
    $sessions=@{}
    foreach($role in @('Administrador','Recepcionista','Paciente','Medico')){
        $session=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
        $session.Cookies.Add([Uri]$base,[Net.Cookie]::new('CVS_SESSION',$f.sessions.$role.id,'/'))
        $sessions[$role]=$session
        $path=if($role -eq 'Paciente'){'/mis-pagos'}else{'/pagos'}
        $r=Invoke-WebRequest ($base+$path) -WebSession $session -SkipHttpErrorCheck
        Assert-Payment ($r.StatusCode -eq $(if($role -eq 'Medico'){403}else{200})) ($role+' ámbito GET')
        $r=Invoke-WebRequest ($base+$path+'/crear') -Method Post -Body @{csrf='incorrecto'} -WebSession $session -SkipHttpErrorCheck
        Assert-Payment ($r.StatusCode -eq 403) ($role+' POST sin autorización o CSRF válido')
        if($role -in @('Paciente','Recepcionista')){
            $r=Invoke-WebRequest ($base+$path+'/nuevo') -WebSession $session -SkipHttpErrorCheck
            Assert-Payment ($r.StatusCode -eq 200 -and $r.Content.Contains('name="cita"') -and -not $r.Content.Contains('name="importe"') -and -not $r.Content.Contains('name="medico"')) ($role+' formulario integrado P5-B sin campos manipulables')
            Assert-Payment ($r.Content.Contains('value="efectivo"')) ($role+' efectivo disponible')
        }
    }
    $patient=$sessions.Paciente;$reception=$sessions.Recepcionista
    $c=$f.payment_appointments[0]
    if($null -eq $c){throw 'No hay cita de demostración del paciente.'}
    # Se usa el momento original de registro para efectivo, conservando el máximo de 48 h.
    $clock=([DateTimeOffset]::Parse($c.fecha_registro)).ToOffset([TimeSpan]::FromHours(-4)).AddMinutes(1).ToString('yyyy-MM-ddTHH:mm')
    $r=Invoke-WebRequest ($base+'/mis-pagos/nuevo') -WebSession $patient
    $intent=[regex]::Match($r.Content,'name="intent" value="([a-f0-9]+)"').Groups[1].Value
    $body=@{csrf=[string]$f.sessions.Paciente.csrf;intent=$intent;cita=[string]$c.id_cita;metodo='efectivo';reloj=$clock;importe='0.01';paciente='999';medico='999';especialidad='999'}
    $r=Invoke-WebRequest ($base+'/mis-pagos/crear') -Method Post -Body $body -WebSession $patient -SkipHttpErrorCheck
    Assert-Payment ($r.StatusCode -eq 200 -and $r.Content.Contains([string]$c.precio_consulta)) 'Importe y cita de PostgreSQL, ignora importe y titular falsificados'
    $store=Join-Path $root ('var/p9/'+$storeName+'.json')
    $rows=Get-Content -LiteralPath $store -Raw | ConvertFrom-Json -AsHashtable -DateKind String
    $row=@($rows.Values)[0];$id=$row.id
    Assert-Payment ($row.patient -eq $c.id_paciente -and $row.appointment -eq $c.id_cita -and $row.estado -eq 'pendiente') 'Vínculo paciente real y efectivo pendiente'
    $r=Invoke-WebRequest ($base+'/mis-pagos/crear') -Method Post -Body $body -WebSession $patient -SkipHttpErrorCheck
    Assert-Payment ($r.StatusCode -eq 200 -and (Get-Content $store -Raw | ConvertFrom-Json -AsHashtable -DateKind String).Count -eq 1) 'Reenvío idempotente'
    $r=Invoke-WebRequest ($base+'/mis-pagos/accion') -Method Post -Body @{csrf=[string]$f.sessions.Paciente.csrf;id=$id;accion='aprobar'} -WebSession $patient -SkipHttpErrorCheck
    Assert-Payment ($r.StatusCode -eq 403) 'Paciente no confirma efectivo'
    $r=Invoke-WebRequest ($base+'/mis-pagos') -WebSession $patient
    Assert-Payment (-not $r.Content.Contains('value="aprobar"')) 'Interfaz oculta confirmación de efectivo al paciente'
    $body.intent=('9'*64);$body.cita=[string]$f.appointments.Paciente.foreign.id_cita
    $r=Invoke-WebRequest ($base+'/mis-pagos/crear') -Method Post -Body $body -WebSession $patient -SkipHttpErrorCheck
    Assert-Payment ($r.StatusCode -eq 403) 'Cita ajena denegada'
    $r=Invoke-WebRequest ($base+'/pagos/accion') -Method Post -Body @{csrf=[string]$f.sessions.Administrador.csrf;id=$id;accion='aprobar'} -WebSession $sessions.Administrador -SkipHttpErrorCheck
    Assert-Payment ($r.StatusCode -eq 403) 'Administrador no confirma efectivo'
    $r=Invoke-WebRequest ($base+'/mis-pagos/sandbox/retorno?pago='+$id+'&token=AJENO') -WebSession $patient -SkipHttpErrorCheck
    Assert-Payment ($r.StatusCode -eq 403) 'Retorno Sandbox ajeno no confirma'
    # Avanzar hasta el vencimiento demuestra estado vencido sin tocar citas reales.
    $expiry=([DateTimeOffset]::Parse($row.fecha_vencimiento)).ToOffset([TimeSpan]::FromHours(-4)).AddMinutes(1).ToString('yyyy-MM-ddTHH:mm')
    $r=Invoke-WebRequest ($base+'/pagos/accion') -Method Post -Body @{csrf=[string]$f.sessions.Recepcionista.csrf;id=$id;accion='reloj';reloj=$expiry} -WebSession $reception -SkipHttpErrorCheck
    if($r.StatusCode -ne 200 -or -not $r.Content.Contains('vencido')){Write-Output ('Respuesta reloj: '+$r.StatusCode+' '+[regex]::Replace($r.Content,'<[^>]+>',' ').Trim())}
    Assert-Payment ($r.StatusCode -eq 200 -and $r.Content.Contains('vencido')) 'Recepción avanza reloj y pago vence'
    $r=Invoke-WebRequest ($base+'/mis-pagos/accion') -Method Post -Body @{csrf=[string]$f.sessions.Paciente.csrf;id=$id;accion='aprobar'} -WebSession $patient -SkipHttpErrorCheck
    Assert-Payment ($r.StatusCode -eq 403) 'Paciente sigue sin confirmar efectivo vencido'
    # Nuevo ensayo efectivo sobre la misma cita, una vez vencido el anterior.
    $form=Invoke-WebRequest ($base+'/mis-pagos/nuevo') -WebSession $patient
    $intent=[regex]::Match($form.Content,'name="intent" value="([a-f0-9]+)"').Groups[1].Value
    $body=@{csrf=[string]$f.sessions.Paciente.csrf;intent=$intent;cita=[string]$c.id_cita;metodo='efectivo';reloj=$clock}
    $r=Invoke-WebRequest ($base+'/mis-pagos/crear') -Method Post -Body $body -WebSession $patient -SkipHttpErrorCheck
    Assert-Payment ($r.StatusCode -eq 200) 'Puede iniciar otro ensayo después del vencimiento local'
    $rows=Get-Content $store -Raw | ConvertFrom-Json -AsHashtable -DateKind String
    $cash=@($rows.Values | Where-Object estado -eq 'pendiente')[0]
    $open=[DateTimeOffset]::Parse($clock)
    if($open.Hour -ge 18){$open=$open.AddDays(1);$open=[DateTimeOffset]::new($open.Year,$open.Month,$open.Day,8,0,0,$open.Offset)}
    if($open.Hour -lt 8){$open=[DateTimeOffset]::new($open.Year,$open.Month,$open.Day,8,0,0,$open.Offset)}
    while($open.DayOfWeek -in @([DayOfWeek]::Saturday,[DayOfWeek]::Sunday)){$open=$open.AddDays(1);$open=[DateTimeOffset]::new($open.Year,$open.Month,$open.Day,8,0,0,$open.Offset)}
    $r=Invoke-WebRequest ($base+'/pagos/accion') -Method Post -Body @{csrf=[string]$f.sessions.Recepcionista.csrf;id=$cash.id;accion='reloj';reloj=$open.ToString('yyyy-MM-ddTHH:mm')} -WebSession $reception -SkipHttpErrorCheck
    Assert-Payment ($r.StatusCode -eq 200) 'Reloj en oportunidad válida de caja'
    $r=Invoke-WebRequest ($base+'/pagos/accion') -Method Post -Body @{csrf=[string]$f.sessions.Recepcionista.csrf;id=$cash.id;accion='aprobar'} -WebSession $reception -SkipHttpErrorCheck
    Assert-Payment ($r.StatusCode -eq 200 -and $r.Content.Contains('confirmado')) 'Recepción confirma efectivo ficticio'
    $online=$f.payment_appointments[1]
    if($null -eq $online){throw 'Falta segunda cita propia para ensayos online.'}
    foreach($method in @('qr_simulado','paypal_sandbox')){
        $form=Invoke-WebRequest ($base+'/mis-pagos/nuevo') -WebSession $patient
        $intent=[regex]::Match($form.Content,'name="intent" value="([a-f0-9]+)"').Groups[1].Value
        $body=@{csrf=[string]$f.sessions.Paciente.csrf;intent=$intent;cita=[string]$online.id_cita;metodo=$method;reloj=$clock;importe='0.01'}
        $r=Invoke-WebRequest ($base+'/mis-pagos/crear') -Method Post -Body $body -WebSession $patient -SkipHttpErrorCheck
        Assert-Payment ($r.StatusCode -eq 200) ($method+' creación HTTP integrada')
        $rows=Get-Content $store -Raw | ConvertFrom-Json -AsHashtable -DateKind String
        $payment=@($rows.Values | Where-Object { $_.metodo -eq $method })[0]
        $r=Invoke-WebRequest ($base+'/mis-pagos') -WebSession $patient
        if($method -eq 'qr_simulado'){
            Assert-Payment ($r.Content.Contains('<svg') -and $payment.estado -eq 'pendiente') 'QR visual no confirma por visualizarlo'
        }else{
            $r=Invoke-WebRequest ($base+'/mis-pagos/accion') -Method Post -Body @{csrf=[string]$f.sessions.Paciente.csrf;id=$payment.id;accion='sandbox_iniciar'} -WebSession $patient -SkipHttpErrorCheck
            Assert-Payment ($r.StatusCode -eq 409) 'Sandbox externo bloqueado por defecto'
        }
        $action=if($method -eq 'qr_simulado'){'rechazar'}else{'aprobar'}
        $r=Invoke-WebRequest ($base+'/mis-pagos/accion') -Method Post -Body @{csrf=[string]$f.sessions.Paciente.csrf;id=$payment.id;accion=$action} -WebSession $patient -SkipHttpErrorCheck
        Assert-Payment ($r.StatusCode -eq 200) ($method+' transición HTTP local')
        $rows=Get-Content $store -Raw | ConvertFrom-Json -AsHashtable -DateKind String
        Assert-Payment ($rows[$payment.id].estado -eq $(if($action -eq 'aprobar'){'confirmado'}else{'rechazado'})) ($method+' estado almacenado coherente')
        if($method -eq 'paypal_sandbox'){
            Assert-Payment ($rows[$payment.id].events[0].proveedor -eq 'paypal_simulacion_local') 'PayPal local identificado como simulación'
            $r=Invoke-WebRequest ($base+'/mis-pagos/comprobante?id='+$payment.id) -WebSession $patient -SkipHttpErrorCheck
            Assert-Payment ($r.StatusCode -eq 200 -and $r.Headers.'Content-Type' -match 'application/pdf') 'Comprobante PDF académico de pago confirmado'
        }else{
            $r=Invoke-WebRequest ($base+'/mis-pagos/comprobante?id='+$payment.id) -WebSession $patient -SkipHttpErrorCheck
            Assert-Payment ($r.StatusCode -eq 409) 'Pago rechazado no emite comprobante'
        }
    }
    $r=Invoke-WebRequest ($base+'/var/p9/'+$storeName+'.json') -SkipHttpErrorCheck
    Assert-Payment ($r.StatusCode -eq 404) 'Almacén fuera de DocumentRoot'
    Write-Output ($checks.ToString()+' comprobaciones HTTP P9 con cuatro cuentas existentes; solo ensayo en archivo aislado.')
} finally {
    if($null -ne $process){Stop-Process -Id $process.Id -ErrorAction SilentlyContinue}
    $env:PAYMENT_DEMO_STORE_NAME=$previousStore
    foreach($suffix in @('.json','.json.lock')){
        $target=Join-Path $root ('var/p9/'+$storeName+$suffix)
        if(Test-Path -LiteralPath $target){Remove-Item -LiteralPath $target}
    }
    if(Test-Path -LiteralPath $fixturePath){& php $tool cleanup;if($LASTEXITCODE -ne 0){throw 'Falló integridad de fixtures.'}}
}
