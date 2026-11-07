$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$port = 8088
$base = 'http://127.0.0.1:' + $port
$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, $port)
$listener.Start()
$listener.Stop()
$process = Start-Process -FilePath (Get-Command php).Source -WorkingDirectory $root -ArgumentList @('-d','display_errors=0','-S',('127.0.0.1:'+$port),'-t','public','tools/dev-router.php') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $root 'var/http-test-output.log') -RedirectStandardError (Join-Path $root 'var/http-test-error.log')
$checks = 0
function Assert-Check([bool]$ok, [string]$label) {
    if (-not $ok) { throw ('FALLO HTTP: ' + $label) }
    $script:checks++
    Write-Output ('OK HTTP: ' + $label)
}
try {
    Start-Sleep -Seconds 2
    if ($process.HasExited) { throw 'No se pudo iniciar el servidor temporal.' }
    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $response = Invoke-WebRequest -Uri ($base+'/login') -WebSession $session -SkipHttpErrorCheck
    Assert-Check ($response.StatusCode -eq 200 -and $response.Content.Contains('Iniciar sesión')) 'Formulario de acceso'
    $cookie = $session.Cookies.GetCookies($base) | Where-Object Name -eq 'CVS_SESSION'
    Assert-Check ($cookie.HttpOnly) 'Cookie HttpOnly'
    Assert-Check (($response.Headers['Set-Cookie'] -join '') -match 'SameSite=Lax') 'Cookie SameSite'
    Assert-Check (($response.Headers['Cache-Control'] -join '') -match 'no-store') 'Respuestas sin caché'
    $csrf = [regex]::Match($response.Content,'name="csrf" value="([a-f0-9]+)"').Groups[1].Value
    Assert-Check ($csrf.Length -eq 64) 'Token CSRF en formulario'
    $response = Invoke-WebRequest -Uri ($base+'/login') -Method Post -WebSession $session -Body @{identifier='usuario1';password='';csrf='incorrecto'} -SkipHttpErrorCheck
    Assert-Check ($response.StatusCode -eq 419) 'CSRF incorrecto rechazado'
    # Clave aleatoria de prueba, nunca configurada en ninguna cuenta ni impresa.
    $probe = [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(24))
    $response = Invoke-WebRequest -Uri ($base+'/login') -Method Post -WebSession $session -Body @{identifier='usuario1';password=$probe;csrf=$csrf;role='Administrador'} -SkipHttpErrorCheck
    $probe = $null
    Assert-Check ($response.StatusCode -eq 401 -and $response.Content.Contains('No se pudo iniciar sesión.')) 'Credencial incorrecta rechazada sin filtrar motivo'
    Assert-Check (-not $response.Content.Contains('contrasena_hash')) 'Sin hash en respuesta'
    foreach ($route in @('/administrador','/recepcionista','/medico','/paciente')) {
        $response = Invoke-WebRequest -Uri ($base+$route) -WebSession $session -MaximumRedirection 0 -SkipHttpErrorCheck -ErrorAction SilentlyContinue
        Assert-Check ($response.StatusCode -eq 303 -and $response.Headers.Location -eq '/login') ('Acceso anónimo bloqueado '+$route)
    }
    $response = Invoke-WebRequest -Uri ($base+'/logout') -WebSession $session -SkipHttpErrorCheck
    Assert-Check ($response.StatusCode -eq 405) 'Logout GET rechazado'
    $response = Invoke-WebRequest -Uri ($base+'/logout') -Method Post -WebSession $session -Body @{csrf='incorrecto'} -SkipHttpErrorCheck
    Assert-Check ($response.StatusCode -eq 419) 'Logout sin CSRF válido rechazado'
    $response = Invoke-WebRequest -Uri ($base+'/logout') -Method Post -WebSession $session -Body @{csrf=$csrf} -MaximumRedirection 0 -SkipHttpErrorCheck -ErrorAction SilentlyContinue
    Assert-Check ($response.StatusCode -eq 303 -and $response.Headers.Location -eq '/login?salida=1') 'Logout POST válido'
    $response = Invoke-WebRequest -Uri ($base+'/login') -Method Post -WebSession $session -Body @{csrf=$csrf;identifier='usuario1';password=''} -SkipHttpErrorCheck
    Assert-Check ($response.StatusCode -eq 419) 'Token anterior invalidado tras logout'
    foreach ($route in @('/.env','/backend/config/database.php','/tools/account.php','/tests/auth-database.php')) {
        $response = Invoke-WebRequest -Uri ($base+$route) -WebSession $session -SkipHttpErrorCheck
        Assert-Check ($response.StatusCode -eq 404) ('Archivo privado inaccesible '+$route)
    }
    Write-Output ($checks.ToString()+' comprobaciones HTTP pasaron. No se modificó PostgreSQL.')
} finally {
    Stop-Process -Id $process.Id -ErrorAction SilentlyContinue
}
