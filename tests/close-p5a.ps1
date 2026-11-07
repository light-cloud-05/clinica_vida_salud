$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
Set-Location -LiteralPath $root
$logDirectory = Join-Path $root 'var/p5a-checks'
New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null
$jobs = @(
    @{name='citas-db';exe='php';arguments=@('tests/citas-database.php')},
    @{name='horarios-db';exe='php';arguments=@('tests/horarios-database.php')},
    @{name='auth-db';exe='php';arguments=@('tests/auth-database.php')},
    @{name='pacientes-db';exe='php';arguments=@('tests/pacientes-database.php')},
    @{name='medicos-db';exe='php';arguments=@('tests/medicos-database.php')},
    @{name='http-auth';exe='pwsh';arguments=@('-NoProfile','-File','tests/http-auth.ps1')},
    @{name='http-pacientes';exe='pwsh';arguments=@('-NoProfile','-File','tests/http-pacientes.ps1')},
    @{name='http-medicos';exe='pwsh';arguments=@('-NoProfile','-File','tests/http-medicos.ps1')},
    @{name='http-horarios';exe='pwsh';arguments=@('-NoProfile','-File','tests/http-horarios.ps1')},
    @{name='http-citas';exe='pwsh';arguments=@('-NoProfile','-File','tests/http-citas.ps1')}
)
foreach ($job in $jobs) {
    $arguments = $job.arguments
    $result = & $job.exe @arguments 2>&1
    $exitCode = $LASTEXITCODE
    $result | Set-Content -LiteralPath (Join-Path $logDirectory ($job.name+'.log')) -Encoding utf8
    if ($exitCode -ne 0) { throw ('Falló '+$job.name+'. Revisar var/p5a-checks. No se reintentaron escrituras.') }
    Write-Output ('PASÓ: '+$job.name)
    $result | Select-Object -Last 2 | Write-Output
}
& php tools/verify-p4-integrity.php verify p5a
if ($LASTEXITCODE -ne 0) { throw 'Falló la comparación de integridad de P5-A; no se restauraron datos.' }
Write-Output 'Cierre P5-A: cinco integraciones de lectura, cinco suites HTTP e integridad correctas.'
