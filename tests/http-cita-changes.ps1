$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$base='http://127.0.0.1:8094'
$fixturePath=Join-Path $root 'var/patient-http-fixtures.json'
$tool=Join-Path $PSScriptRoot 'support/patient-http-fixtures.php'
$process=$null;$checks=0
function Assert-Change([bool]$ok,[string]$label){if(-not $ok){throw ('FALLO HTTP P5-C: '+$label)};$script:checks++;Write-Output ('OK HTTP: '+$label)}
try{
    & php -r 'require "backend/bootstrap.php"; exit(Clinica\Support\CitaChangeFactory::enabled() || Clinica\Support\BookingFactory::enabled() ? 1 : 0);'
    if($LASTEXITCODE -ne 0){throw 'Las dos banderas de escritura de citas deben estar deshabilitadas.'}
    & php $tool create
    if($LASTEXITCODE -ne 0){throw 'No se pudieron crear las sesiones locales de prueba.'}
    $f=Get-Content -LiteralPath $fixturePath -Raw | ConvertFrom-Json
    $process=Start-Process -FilePath (Get-Command php).Source -WorkingDirectory $root -ArgumentList @('-d','display_errors=0','-S','127.0.0.1:8094','-t','public','tools/dev-router.php') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $root 'var/cita-changes-http-output.log') -RedirectStandardError (Join-Path $root 'var/cita-changes-http-error.log')
    Start-Sleep -Seconds 2
    if($process.HasExited){throw 'Servidor temporal no disponible.'}
    foreach($route in @('/citas/reprogramar','/citas/cancelar','/mis-citas/reprogramar','/mis-citas/cancelar')){
        $r=Invoke-WebRequest ($base+$route) -SkipHttpErrorCheck -MaximumRedirection 0 -ErrorAction SilentlyContinue
        Assert-Change ($r.StatusCode -eq 303 -and $r.Headers.Location -eq '/login') ('Sin sesión '+$route)
    }
    foreach($role in @('Administrador','Recepcionista','Paciente','Medico')){
        $session=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
        $session.Cookies.Add([Uri]$base,[Net.Cookie]::new('CVS_SESSION',$f.sessions.$role.id,'/'))
        $path=if($role -eq 'Paciente'){'/mis-citas'}else{'/citas'}
        $sample=$f.appointments.$role.sample
        $detail=Invoke-WebRequest ($base+$path+'/detalle?id='+$sample.id_cita) -WebSession $session -SkipHttpErrorCheck
        Assert-Change ($detail.StatusCode -eq 200) ($role+' detalle P5-A conservado')
        if($role -eq 'Medico'){
            Assert-Change (-not $detail.Content.Contains('>Cancelar cita<') -and -not $detail.Content.Contains('>Reprogramar<')) 'Médico sin controles de gestión'
            foreach($action in @('reprogramar','cancelar')){foreach($method in @('Get','Post')){
                $r=Invoke-WebRequest ($base+$path+'/'+$action+'?id='+$sample.id_cita) -Method $method -WebSession $session -SkipHttpErrorCheck
                Assert-Change ($r.StatusCode -eq 403) ('Médico '+$method+' '+$action+' denegado')
            }}
            continue
        }
        Assert-Change ($detail.Content.Contains('/reprogramar?id=') -and $detail.Content.Contains('/cancelar?id=')) ($role+' enlaces de gestión')
        foreach($action in @('reprogramar','cancelar')){
            $date=[string]$f.availability.$role.date
            $url=$base+$path+'/'+$action
            $r=Invoke-WebRequest ($url+'?id='+$sample.id_cita+'&fecha='+$date) -WebSession $session -SkipHttpErrorCheck
            Assert-Change ($r.StatusCode -eq 200 -and $r.Content.Contains('El guardado está deshabilitado')) ($role+' formulario '+$action)
            $version=[regex]::Match($r.Content,'name="version" value="([a-f0-9]{64})"').Groups[1].Value
            Assert-Change ($version.Length -eq 64 -and $r.Content.Contains('disabled')) ($role+' versión y botón bloqueado '+$action)
            $body=@{id=[string]$sample.id_cita;version=$version;csrf=[string]$f.sessions.$role.csrf}
            if($action -eq 'reprogramar'){$body.fecha=$date;$body.hora='11:00'}else{$body.motivo='Prueba HTTP sin persistencia'}
            $r=Invoke-WebRequest $url -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
            Assert-Change ($r.StatusCode -eq 423 -and $r.Content.Contains('No se reprogramó ni canceló ninguna cita') -and -not $r.Content.Contains('se reprogramó correctamente')) ($role+' POST bloqueado sin éxito falso '+$action)
            $body.csrf='invalido'
            $r=Invoke-WebRequest $url -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
            Assert-Change ($r.StatusCode -eq 419) ($role+' CSRF '+$action)
            $body.csrf=[string]$f.sessions.$role.csrf;$body.version=('0'*64)
            $r=Invoke-WebRequest $url -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
            Assert-Change ($r.StatusCode -eq 409) ($role+' versión obsoleta '+$action)
            $body.version=$version;$body.paciente='999'
            $r=Invoke-WebRequest $url -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
            Assert-Change ($r.StatusCode -eq 422) ($role+' parámetro no permitido '+$action)
            $body.Remove('paciente')
            if($role -eq 'Paciente'){
                $foreign=$f.appointments.$role.foreign.id_cita
                $r=Invoke-WebRequest ($url+'?id='+$foreign) -WebSession $session -SkipHttpErrorCheck
                Assert-Change ($r.StatusCode -eq 404) ('Paciente GET ajeno '+$action)
                $body.id=[string]$foreign
                $r=Invoke-WebRequest $url -Method Post -Body $body -WebSession $session -SkipHttpErrorCheck
                Assert-Change ($r.StatusCode -eq 404) ('Paciente POST ajeno '+$action)
            }
        }
    }
    Write-Output ($checks.ToString()+' comprobaciones HTTP P5-C pasaron; escrituras deshabilitadas.')
}finally{
    if($null -ne $process){Stop-Process -Id $process.Id -ErrorAction SilentlyContinue}
    if(Test-Path -LiteralPath $fixturePath){& php $tool cleanup;if($LASTEXITCODE -ne 0){throw 'Falló integridad de fixtures.'}}
}
