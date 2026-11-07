param([string]$CashClock='')
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
if(-not (Test-Path -LiteralPath (Join-Path $root 'var/integration/test.env'))){throw 'Falta la copia aislada verificada.'}
$previous=$env:INTEGRATION_CLOCK
if($CashClock -and $CashClock -notmatch '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-04:00$'){throw 'CashClock debe ser ISO 8601 explícito, por ejemplo 2026-11-09T08:30:00-04:00.'}
try {
    & 'C:/Program Files/PostgreSQL/18/bin/pg_ctl.exe' -D (Join-Path $root 'var/integration/cluster-final') status
    if($LASTEXITCODE -eq 3){& 'C:/Program Files/PostgreSQL/18/bin/pg_ctl.exe' -D (Join-Path $root 'var/integration/cluster-final') -l (Join-Path $root 'var/integration/postgres-final.log') -o '-p 55432 -h 127.0.0.1' -w start}
    if($CashClock){$env:INTEGRATION_CLOCK=$CashClock}
    & php -d ('auto_prepend_file='+ (Join-Path $PSScriptRoot 'integration-bootstrap.php')) (Join-Path $PSScriptRoot 'integration-check.php')
    if($LASTEXITCODE -ne 0){throw 'El clúster aislado no pasó la comprobación.'}
    Write-Output 'Solo PRUEBAS: http://127.0.0.1:8098/login — base clinica_vida_salud_test. Ctrl+C para detener.'
    & php -S 127.0.0.1:8098 -t (Join-Path $root 'public') (Join-Path $PSScriptRoot 'integration-router.php')
} finally {$env:INTEGRATION_CLOCK=$previous}
