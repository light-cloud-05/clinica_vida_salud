$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
Set-Location -LiteralPath $root
$logDirectory = Join-Path $root 'var/p4-checks'
New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null
$jobs = @(
    @{name='horarios-db';exe='php';arguments=@('tests/horarios-database.php')},
    @{name='auth-db';exe='php';arguments=@('tests/auth-database.php')},
    @{name='pacientes-db';exe='php';arguments=@('tests/pacientes-database.php')},
    @{name='medicos-db';exe='php';arguments=@('tests/medicos-database.php')},
    @{name='http-auth';exe='pwsh';arguments=@('-NoProfile','-File','tests/http-auth.ps1')},
    @{name='http-pacientes';exe='pwsh';arguments=@('-NoProfile','-File','tests/http-pacientes.ps1')},
    @{name='http-medicos';exe='pwsh';arguments=@('-NoProfile','-File','tests/http-medicos.ps1')},
    @{name='http-horarios';exe='pwsh';arguments=@('-NoProfile','-File','tests/http-horarios.ps1')}
)
foreach ($job in $jobs) {
    $arguments = $job.arguments
    $result = & $job.exe @arguments 2>&1
    $exitCode = $LASTEXITCODE
    $result | Set-Content -LiteralPath (Join-Path $logDirectory ($job.name+'.log')) -Encoding utf8
    if ($exitCode -ne 0) { throw ('Falló '+$job.name+'. Revisar el registro local en var/p4-checks; no se reintentaron escrituras.') }
    Write-Output ('PASÓ: '+$job.name)
    $result | Select-Object -Last 2 | Write-Output
}
& php tools/verify-p4-integrity.php verify
if ($LASTEXITCODE -ne 0) { throw 'Falló la comparación final de integridad. No se restauraron datos.' }
Write-Output 'Cierre P4: integraciones de lectura, cuatro suites HTTP e integridad correctas.'
