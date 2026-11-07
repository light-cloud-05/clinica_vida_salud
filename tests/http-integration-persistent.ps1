$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$dir=Join-Path $root 'var/integration'
$base='http://127.0.0.1:8098'
$process=$null;$n=0;$previousClock=$env:INTEGRATION_CLOCK
function Check([bool]$condition,[string]$label){if(-not $condition){throw ('FALLO HTTP PERSISTENTE: '+$label)};$script:n++;Write-Output ('OK: '+$label)}
function Field($html,$name){return [regex]::Match($html,'name="'+$name+'" value="([a-f0-9]+)"').Groups[1].Value}
function Invoke-Http {
    param(
        [Parameter(Mandatory=$true)][string]$Uri,
        [Microsoft.PowerShell.Commands.WebRequestSession]$WebSession,
        [ValidateSet('Get','Post')][string]$Method='Get',
        [hashtable]$Body,
        [switch]$FollowRedirect
    )
    $request=@{Uri=$Uri;Method=$Method;WebSession=$WebSession;UseBasicParsing=$true;MaximumRedirection=0}
    if($null -ne $Body){$request.Body=$Body}
    try {
        $response=Microsoft.PowerShell.Utility\Invoke-WebRequest @request
        $result=[pscustomobject]@{
            StatusCode=[int]$response.StatusCode
            InitialStatusCode=[int]$response.StatusCode
            FinalStatusCode=[int]$response.StatusCode
            Content=[string]$response.Content
            Headers=$response.Headers
            Uri=[Uri]$response.BaseResponse.ResponseUri
            Redirected=$false
        }
    } catch {
        $webResponse=$_.Exception.Response
        if($null -eq $webResponse){throw}
        $stream=$webResponse.GetResponseStream()
        try {
            $reader=New-Object System.IO.StreamReader($stream,[System.Text.Encoding]::UTF8,$true)
            $content=$reader.ReadToEnd()
            $reader.Dispose()
        } finally {$stream.Dispose()}
        $result=[pscustomobject]@{
            StatusCode=[int]$webResponse.StatusCode
            InitialStatusCode=[int]$webResponse.StatusCode
            FinalStatusCode=[int]$webResponse.StatusCode
            Content=$content
            Headers=$webResponse.Headers
            Uri=[Uri]$webResponse.ResponseUri
            Redirected=$false
        }
    }
    if($FollowRedirect -and $result.StatusCode -ge 300 -and $result.StatusCode -lt 400){
        $location=$result.Headers['Location']
        if([string]::IsNullOrWhiteSpace($location)){throw "Respuesta $($result.StatusCode) sin Location."}
        $next=New-Object -TypeName System.Uri -ArgumentList @($result.Uri,[string]$location)
        $follow=Invoke-Http -Uri $next.AbsoluteUri -WebSession $WebSession -FollowRedirect
        $follow.InitialStatusCode=$result.InitialStatusCode
        $follow.Redirected=$true
        return $follow
    }
    return $result
}
try {
    & php (Join-Path $PSScriptRoot 'integration-http-fixtures.php')
    if($LASTEXITCODE -ne 0){throw 'Aislamiento o fixtures no válidos.'}
    $f=Get-Content (Join-Path $dir 'http-sessions.json') -Raw | ConvertFrom-Json
    $process=Start-Process -FilePath (Get-Command php).Source -WorkingDirectory $root -ArgumentList @('-S','127.0.0.1:8098','-t','public','tools/integration-router.php') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $dir 'http-output.log') -RedirectStandardError (Join-Path $dir 'http-error.log')
    Start-Sleep -Seconds 2
    if($process.HasExited){throw 'Puerto ocupado o servidor no disponible.'}
    $sessions=@{}
    foreach($role in @('Paciente','Medico','Recepcionista','Administrador')){
        $session=[Microsoft.PowerShell.Commands.WebRequestSession]::new();$session.Cookies.Add([Uri]$base,[Net.Cookie]::new('CVS_TEST_SESSION',$f.sessions.$role.id,'/'));$sessions[$role]=$session
        $path=if($role -eq 'Paciente'){'/mis-pagos'}else{'/pagos'}
        $r=Invoke-Http ($base+$path) -WebSession $session
        Check (([string]$r.Headers['X-Clinic-Environment']) -eq 'isolated-integration') ($role+' servidor separado verificado')
        Check ($r.StatusCode -eq $(if($role -eq 'Medico'){403}else{200})) ($role+' ámbito pagos persistentes')
    }
    $patient=$sessions.Paciente;$made=@()
    foreach($i in 0..3){
        $s=$f.slots[$i];$query='?medico='+$s.medico+'&especialidad='+$s.especialidad+'&fecha='+$s.fecha+'&hora='+$s.hora
        $r=Invoke-Http ($base+'/mis-citas/reservar'+$query) -WebSession $patient
        Check ($r.StatusCode -eq 200 -and $r.Content.Contains('ENTORNO AISLADO')) 'Formulario de reserva en copia'
        $body=@{csrf=[string]$f.sessions.Paciente.csrf;booking_token=(Field $r.Content 'booking_token');medico=[string]$s.medico;especialidad=[string]$s.especialidad;fecha=[string]$s.fecha;hora=[string]$s.hora}
        $r=Invoke-Http ($base+'/mis-citas/reservar') -Method Post -Body $body -WebSession $patient -FollowRedirect
        $uri=$r.Uri
        $id=[regex]::Match($uri.Query,'id=(\d+)').Groups[1].Value
        Check ($r.InitialStatusCode -eq 303 -and $r.FinalStatusCode -eq 200 -and [int]$id -gt 60 -and $r.Content.Contains('CVS-')) 'Reserva HTTP con COMMIT y confirmación (303 a 200)'
        $made+=$id
        if($i -lt 2){
            $method=if($i -eq 0){'qr_simulado'}else{'paypal_sandbox'}
            $form=Invoke-Http ($base+'/mis-pagos/nuevo') -WebSession $patient
            $r=Invoke-Http ($base+'/mis-pagos/crear') -Method Post -Body @{csrf=[string]$f.sessions.Paciente.csrf;intent=(Field $form.Content 'intent');cita=$id;metodo=$method;importe='0.01'} -WebSession $patient
            Check ($r.StatusCode -eq 200 -and $r.Content.Contains('PostgreSQL de pruebas')) ($method+' pago persistente creado desde navegador')
            $list=Invoke-Http ($base+'/mis-pagos') -WebSession $patient
            $payment=Field $list.Content 'id'
            if($method -eq 'qr_simulado'){Check ($list.Content.Contains('<svg')) 'QR visual con pago SQL pendiente'}
            $r=Invoke-Http ($base+'/mis-pagos/accion') -Method Post -Body @{csrf=[string]$f.sessions.Paciente.csrf;id=$payment;accion='aprobar'} -WebSession $patient -FollowRedirect
            $confirmed=($r.InitialStatusCode -eq 303 -and $r.FinalStatusCode -eq 200 -and $r.Content.Contains('confirmado'))
            Check -condition $confirmed -label ($method+' confirmacion HTTP persistente (303 a 200)')
            $r=Invoke-Http ($base+'/mis-pagos/comprobante?id='+$payment) -WebSession $patient
            $isPdf=($r.StatusCode -eq 200 -and ([string]$r.Headers['Content-Type']) -match 'application/pdf')
            Check -condition $isPdf -label ($method+' comprobante PDF de PostgreSQL')
        }
    }
    $id=$made[2];$newHour='';$targetDate=''
    foreach($day in $f.days){
        $form=Invoke-Http ($base+'/mis-citas/reprogramar?id='+$id+'&fecha='+$day) -WebSession $patient
        $newHour=[regex]::Match($form.Content,'type="radio" name="hora" value="([^"]+)" required\s*>').Groups[1].Value
        if($newHour){$targetDate=[string]$day;break}
    }
    if(-not $newHour){throw 'No hay franja alternativa autorizada para el mismo médico.'}
    $body=@{csrf=[string]$f.sessions.Paciente.csrf;id=$id;version=(Field $form.Content 'version');fecha=$targetDate;hora=$newHour}
    $r=Invoke-Http ($base+'/mis-citas/reprogramar') -Method Post -Body $body -WebSession $patient -FollowRedirect
    Check ($r.InitialStatusCode -eq 303 -and $r.FinalStatusCode -eq 200 -and $r.Content.Contains('reprogramó correctamente')) 'Reprogramación HTTP con historial real (303 a 200)'
    $r=Invoke-Http ($base+'/mis-citas/reprogramar?id='+$id+'&fecha='+$targetDate) -WebSession $patient
    Check ($r.StatusCode -eq 409) 'Segunda reprogramación HTTP denegada'
    $form=Invoke-Http ($base+'/mis-citas/cancelar?id='+$id) -WebSession $patient
    $r=Invoke-Http ($base+'/mis-citas/cancelar') -Method Post -Body @{csrf=[string]$f.sessions.Paciente.csrf;id=$id;version=(Field $form.Content 'version');motivo='Prueba HTTP aislada'} -WebSession $patient -FollowRedirect
    Check ($r.InitialStatusCode -eq 303 -and $r.FinalStatusCode -eq 200 -and $r.Content.Contains('canceló correctamente')) 'Cancelación HTTP persiste auditoría (303 a 200)'
    $id=$made[3];$form=Invoke-Http ($base+'/mis-pagos/nuevo') -WebSession $patient
    $r=Invoke-Http ($base+'/mis-pagos/crear') -Method Post -Body @{csrf=[string]$f.sessions.Paciente.csrf;intent=(Field $form.Content 'intent');cita=$id;metodo='qr_simulado'} -WebSession $patient
    Check ($r.StatusCode -eq 200) 'Intento de rechazo persistente creado'
    $list=Invoke-Http ($base+'/mis-pagos') -WebSession $patient;$paymentToken=Field $list.Content 'id'
    $r=Invoke-Http ($base+'/mis-pagos/accion') -Method Post -Body @{csrf=[string]$f.sessions.Paciente.csrf;id=$paymentToken;accion='rechazar'} -WebSession $patient -FollowRedirect
    Check ($r.StatusCode -eq 200 -and $r.Content.Contains('rechazado')) 'Rechazo HTTP queda persistido'
    $r=Invoke-Http ($base+'/mis-pagos/accion') -Method Post -Body @{csrf=[string]$f.sessions.Paciente.csrf;id=$paymentToken;accion='aprobar'} -WebSession $patient
    Check ($r.StatusCode -eq 409) 'Rechazado no puede confirmarse después'
    $r=Invoke-Http ($base+'/citas/reservar') -Method Post -Body @{csrf=[string]$f.sessions.Medico.csrf} -WebSession $sessions.Medico
    Check ($r.StatusCode -eq 403) 'Médico sin permisos de reserva HTTP'
    $r=Invoke-Http ($base+'/mis-pagos/accion') -Method Post -Body @{csrf='incorrecto';id=$paymentToken;accion='aprobar'} -WebSession $patient
    Check ($r.StatusCode -eq 403) 'CSRF sigue bloqueando escrituras'
    $s=$f.cash
    if(-not $s){throw 'No hay oportunidad de caja válida para la prueba.'}
    $query='?medico='+$s.medico+'&especialidad='+$s.especialidad+'&fecha='+$s.fecha+'&hora='+$s.hora
    $form=Invoke-Http ($base+'/mis-citas/reservar'+$query) -WebSession $patient
    $r=Invoke-Http ($base+'/mis-citas/reservar') -Method Post -Body @{csrf=[string]$f.sessions.Paciente.csrf;booking_token=(Field $form.Content 'booking_token');medico=[string]$s.medico;especialidad=[string]$s.especialidad;fecha=[string]$s.fecha;hora=[string]$s.hora} -WebSession $patient -FollowRedirect
    $cashId=[regex]::Match($r.Uri.Query,'id=(\d+)').Groups[1].Value
    Check ($r.InitialStatusCode -eq 303 -and $r.FinalStatusCode -eq 200 -and [int]$cashId -gt 60) 'Cita nueva HTTP para efectivo (303 a 200)'
    $form=Invoke-Http ($base+'/mis-pagos/nuevo') -WebSession $patient
    $r=Invoke-Http ($base+'/mis-pagos/crear') -Method Post -Body @{csrf=[string]$f.sessions.Paciente.csrf;intent=(Field $form.Content 'intent');cita=$cashId;metodo='efectivo'} -WebSession $patient
    Check ($r.StatusCode -eq 200) 'Paciente selecciona efectivo persistente y queda pendiente'
    $list=Invoke-Http ($base+'/pagos') -WebSession $sessions.Recepcionista;$cashToken=Field $list.Content 'id'
    foreach($role in @('Paciente','Administrador','Recepcionista')){
        $path=if($role -eq 'Paciente'){'/mis-pagos/accion'}else{'/pagos/accion'}
        $r=Invoke-Http ($base+$path) -Method Post -Body @{csrf=[string]$f.sessions.$role.csrf;id=$cashToken;accion='aprobar'} -WebSession $sessions[$role]
        Check ($r.StatusCode -eq $(if($role -eq 'Recepcionista'){409}else{403})) ($role+' efectivo: permiso y caja real cerrada')
    }
    Stop-Process -Id $process.Id;$process=$null
    $env:INTEGRATION_CLOCK=[string]$f.cashClock
    $process=Start-Process -FilePath (Get-Command php).Source -WorkingDirectory $root -ArgumentList @('-S','127.0.0.1:8098','-t','public','tools/integration-router.php') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $dir 'http-cash-output.log') -RedirectStandardError (Join-Path $dir 'http-cash-error.log')
    Start-Sleep -Seconds 2
    $r=Invoke-Http ($base+'/pagos/accion') -Method Post -Body @{csrf=[string]$f.sessions.Recepcionista.csrf;id=$cashToken;accion='aprobar'} -WebSession $sessions.Recepcionista -FollowRedirect
    $cashConfirmed=($r.InitialStatusCode -eq 303 -and $r.FinalStatusCode -eq 200 -and $r.Content.Contains('confirmado') -and $r.Content.Contains([string]$f.cashClock))
    Check -condition $cashConfirmed -label 'Efectivo HTTP confirmado solo con recepcion y reloj de negocio (303 a 200)'
    $message=($n.ToString()+' comprobaciones HTTP persistentes pasaron SOLO en copia aislada.');Write-Output $message;$message|Set-Content (Join-Path $dir 'http-results.txt')
}finally{if($process){Stop-Process -Id $process.Id -ErrorAction SilentlyContinue};$env:INTEGRATION_CLOCK=$previousClock}
