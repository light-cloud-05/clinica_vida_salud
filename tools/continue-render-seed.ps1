#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$Php,
    [Parameter(Mandatory=$true)][string]$ExpectedHost,
    [switch]$RotatedCredentialConfirmed
)
$ErrorActionPreference = 'Stop'
$roles = @('ADMIN','RECEPTION','DOCTOR','PATIENT')
$names = @('APP_ENV','DATABASE_URL','DB_SSLMODE','DB_SCHEMA','DEMO_SEED_TARGET','DEMO_RENDER_RESOURCE','DEMO_RENDER_DATABASE_NAME','DEMO_RENDER_DATABASE_HOST','DEMO_SEED_CONFIRM','PGSSLSNI')
foreach ($role in $roles) { $names += "DEMO_${role}_PASSWORD"; $names += "VERIFY_${role}_PASSWORD" }
$previous = @{}
foreach ($name in $names) { $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
function Unprotect-Value([Security.SecureString]$Value) {
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
}
function Invoke-Demo([string]$File) {
    # Capture both streams: a startup/native failure must never expose secrets.
    $output = & $Php -d display_errors=0 -d log_errors=0 (Join-Path $PSScriptRoot $File) 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'Operación PHP detenida; detalles privados suprimidos. No reintentar el seed si ya se confirmó.' }
    $output | Write-Output
}
try {
    if (-not $RotatedCredentialConfirmed) { throw 'Rotar primero la credencial en Render y usar -RotatedCredentialConfirmed.' }
    $Php = (Resolve-Path -LiteralPath $Php).Path
    if ($Php -like '*\xampp\*') { throw 'Seleccione PHP CLI independiente, no XAMPP.' }
    foreach ($extension in @('pdo_pgsql','pgsql')) {
        $info = (& $Php --ri $extension 2>&1 | Out-String)
        if ($LASTEXITCODE -ne 0 -or $info -notmatch 'PostgreSQL\s*\(libpq\)\s*Version\s*=>\s*(\d+)\.') { throw 'No se pudo comprobar libpq en ambas extensiones.' }
        if ([int]$Matches[1] -lt 14) { throw 'libpq debe ser 14 o posterior para SNI.' }
    }
    Write-Output 'pdo_pgsql y pgsql con libpq compatible con SNI: OK'
    if ($ExpectedHost -cnotmatch '^dpg-[a-z0-9-]+\.[a-z0-9-]+-postgres\.render\.com$') { throw 'Host externo Render inválido.' }
    $secureUrl = Read-Host 'External Database URL ACTUALIZADA tras rotar la credencial' -AsSecureString
    $renderUrl = Unprotect-Value $secureUrl
    $uri = [Uri]$renderUrl
    if ($uri.Scheme -notin @('postgres','postgresql') -or $uri.Host -cne $ExpectedHost -or
        [Uri]::UnescapeDataString($uri.AbsolutePath.Trim('/')) -cne 'clinica_vida_salud_db' -or
        $uri.UserInfo -notmatch ':' -or $uri.Fragment -ne '' -or ($uri.Port -ne -1 -and $uri.Port -ne 5432)) { throw 'Destino incorrecto.' }
    # Query options are forbidden here so they cannot override TLS or identity.
    if ($uri.Query -ne '' -and $uri.Query -notin @('?sslmode=require','?sslmode=verify-full','?sslmode=verify-ca')) { throw 'Opciones de URL no autorizadas.' }
    $env:APP_ENV = 'production'
    $env:DATABASE_URL = $renderUrl
    $env:DB_SSLMODE = 'require'
    $env:DB_SCHEMA = 'public'
    $env:PGSSLSNI = '1'
    $env:DEMO_SEED_TARGET = 'render'
    $env:DEMO_RENDER_RESOURCE = 'clinica-vida-salud-db'
    $env:DEMO_RENDER_DATABASE_NAME = 'clinica_vida_salud_db'
    $env:DEMO_RENDER_DATABASE_HOST = $ExpectedHost
    $env:DEMO_SEED_CONFIRM = 'EMPTY_DATABASE_ONLY'
    Invoke-Demo 'preflight-demo.php'
    $secretPath = Join-Path $HOME 'clinica-vida-salud-render-demo-secrets.clixml'
    if (-not (Test-Path -LiteralPath $secretPath -PathType Leaf)) { throw 'Falta el archivo DPAPI existente; no se generarán contraseñas.' }
    $stored = Import-Clixml -LiteralPath $secretPath
    foreach ($role in $roles) {
        if ($stored[$role] -isnot [Security.SecureString]) { throw 'Formato DPAPI inválido o usuario de Windows incorrecto.' }
        $plain = Unprotect-Value $stored[$role]
        if ($plain.Length -lt 16 -or $plain.Length -gt 72) { throw 'Contraseña DPAPI inválida.' }
        [Environment]::SetEnvironmentVariable("DEMO_${role}_PASSWORD", $plain, 'Process')
        [Environment]::SetEnvironmentVariable("VERIFY_${role}_PASSWORD", $plain, 'Process')
        $plain = $null
    }
    Invoke-Demo 'seed-demo.php'
    Invoke-Demo 'verify-demo.php'
    Write-Output 'Continuación completada: cuatro cuentas verificadas.'
} catch {
    # Do not print $_: URI parser, DPAPI and native exceptions may contain secrets.
    Write-Output 'Procedimiento detenido. Verifique rotación, PHP/libpq, host, DPAPI y ausencia de datos. No se muestran errores privados.'
    throw 'Continuación Render no completada.'
} finally {
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
    $renderUrl = $null; $uri = $null; $plain = $null; $stored = $null; $secureUrl = $null; $info = $null; $output = $null
}
