#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$Php,
    [string]$ExpectedHost,
    [switch]$SimulateSeedBaseline,
    [switch]$Apply,
    [string]$PlanHash
)
$ErrorActionPreference='Stop'
$stage='PS_CONFIG'; $reason='CONFIG_INVALID'; $sqlstate=''
$names=@('MIGRATION_RENDER_URL','MIGRATION_EXPECTED_HOST','MIGRATION_CONFIRM','PGSSLSNI')
$previous=@{}
foreach($name in $names){$previous[$name]=[Environment]::GetEnvironmentVariable($name,'Process')}
$messages=@{
    CONFIG_INVALID='Parametros o ruta PHP invalidos.'
    BASELINE_APPLY_FORBIDDEN='No se puede aplicar contra un baseline simulado.'
    HOST_INVALID='Copie el hostname EXTERNO completo del recurso Render.'
    PHP_START_FAILED='No se pudo iniciar el ejecutable PHP indicado.'
    PHP_PROTOCOL_INVALID='PHP no devolvio un diagnostico seguro. Su salida nativa fue ocultada.'
    PHP_INDEPENDENT_REQUIRED='Para Render use PHP CLI independiente, no XAMPP.'
    MBSTRING_REQUIRED='Falta mbstring. Habilite la extension del mismo paquete PHP.'
    PDO_PGSQL_REQUIRED='Falta el controlador pdo_pgsql.'
    PGSQL_SNI_REQUIRED='Ambos controladores deben utilizar libpq 14 o posterior.'
    TARGET_URL_INPUT_FAILED='No se pudo leer la URL oculta. No se muestran detalles nativos.'
    TARGET_URL_INVALID='URL no autorizada: revisar host, nombre clinica_vida_salud_db, puerto 5432 y TLS.'
    TARGET_CONFIRMATION_REQUIRED='Falta la confirmacion del recurso o el SHA256 del plan real revisado.'
    ARGUMENT_INVALID='Argumento CLI no admitido.'
    SOURCE_CONFIG_INVALID='El origen debe ser local y llamarse clinica_vida_salud. Se utilizan DB_*, no DATABASE_URL.'
    SOURCE_IDENTITY_INVALID='La identidad local o el modo READ ONLY no coinciden.'
    SOURCE_COUNTS_CHANGED='Los conteos locales cambiaron respecto al diagnostico. Revisar antes de continuar.'
    SOURCE_BLOCKS_OR_RESERVATIONS='Hay bloqueos o reservas locales; se requiere revisar el plan.'
    TARGET_IDENTITY_INVALID='El destino no confirma clinica_vida_salud_db, PostgreSQL 18 y TLS.'
    TARGET_TABLE_COUNT_INVALID='Render no tiene exactamente 21 tablas publicas.'
    SCHEMA_COLUMNS_DIFFER='Las columnas, tipos, defaults o identidades de origen y destino difieren.'
    SCHEMA_CONSTRAINTS_DIFFER='Las restricciones de origen y destino difieren.'
    SCHEMA_TRIGGERS_DIFFER='Los triggers o sus funciones de origen y destino difieren.'
    TARGET_ACCOUNTS_INVALID='Render debe conservar exactamente las cuatro cuentas demo previstas.'
    SEQUENCE_UNKNOWN='No se pudo identificar una secuencia autorizada.'
    SEQUENCE_STALE='Una secuencia esta atrasada. No se reajusta automaticamente.'
    PLAN_CHANGED='El origen, destino o plan cambio desde la simulacion aprobada.'
    MAPPING_INCOMPLETE='El mapeo de claves esta incompleto.'
    EXISTING_ROW_CHANGED='La verificacion detecto cambios en registros previos.'
    COUNTS_INVALID='Los conteos no coinciden con el plan.'
    IDEMPOTENCE_FAILED='No se pudo verificar la repeticion sin duplicados.'
    ORPHANS_FOUND='Se detectaron referencias huerfanas.'
    DB_CONNECTION_FAILED='No se pudo conectar. Revisar servicio, red, host, puerto y TLS del origen/destino indicado por la etapa.'
    DB_AUTH_FAILED='PostgreSQL rechazo la autenticacion. Revisar la credencial local o la URL rotada segun la etapa.'
    DB_PERMISSION_DENIED='El usuario PostgreSQL no tiene permiso para la lectura requerida.'
    DB_COLUMN_MISSING='Falta una columna requerida por el script.'
    DB_TABLE_MISSING='Falta una tabla requerida por el script.'
    DB_TIMEOUT='La consulta o espera de bloqueo excedio el tiempo permitido.'
    DB_QUERY_FAILED='Fallo una consulta. Revisar el SQLSTATE seguro; no se muestra la excepcion nativa.'
    SOURCE_REFERENCE_MISSING='Una relacion de origen no tiene padre en el lote.'
    SPECIALTY_STATE_CONFLICT='Una especialidad existente tiene un estado incompatible.'
    EXISTING_IMPORT_DIVERGED='Un registro UNI26 existente difiere del lote. No se sobrescribe.'
    DOCTOR_CODE_CONFLICT='Un registro profesional ya esta ocupado.'
    PATIENT_CODE_CONFLICT='Un documento ficticio ya esta ocupado.'
    SCHEDULE_OVERLAP='Se detectaron horarios superpuestos.'
    APPOINTMENT_INTERVAL_INVALID='Una cita tiene un intervalo de horas invalido.'
    APPOINTMENT_ASSOCIATION_MISSING='Una cita no tiene asociacion medico-especialidad.'
    APPOINTMENT_STATE_INVALID='Estado o contador de reprogramaciones invalido.'
    APPOINTMENT_OVERLAP='Se detectaron citas superpuestas.'
    APPOINTMENT_DATE_INVALID='Una cita dejo de ser futura o excede los diez dias habiles. No se cambian fechas automaticamente.'
    DOCTOR_INACTIVE='Una cita corresponde a un medico inactivo.'
    APPOINTMENT_SCHEDULE_INVALID='Una cita no respeta su horario, alineacion o duracion.'
    APPOINTMENT_BLOCKED='Una cita coincide con un bloqueo existente.'
    APPOINTMENT_RESERVATION_CONFLICT='Una cita coincide con una reserva temporal existente.'
    UNEXPECTED_ERROR='Fallo no clasificado; los detalles nativos se ocultaron por seguridad.'
}
$allowedStages=@('BOOTSTRAP','PHP_RUNTIME','SOURCE_CONFIG','SOURCE_CONNECT','SOURCE_IDENTITY','SOURCE_READ','SOURCE_SCHEMA','SOURCE_COUNTS','SOURCE_RELATIONS','TARGET_URL','TARGET_CONNECT','TARGET_IDENTITY','TARGET_SCHEMA','TARGET_ACCOUNTS','TARGET_READ','TARGET_SEQUENCES','PLAN_BUILD','PLAN_APPROVAL','WRITE','VERIFY')
function Invoke-MigrationPhp([string[]]$Arguments) {
    $start=New-Object Diagnostics.ProcessStartInfo
    $start.FileName=$Php
    $quoted=@()
    foreach($argument in $Arguments){
        if($argument.Contains('"') -or $argument.Contains("`r") -or $argument.Contains("`n")){throw 'Argumento no autorizado.'}
        $quoted+='"'+$argument+'"'
    }
    $start.Arguments=$quoted -join ' '
    $start.WorkingDirectory=Split-Path $PSScriptRoot -Parent
    $start.UseShellExecute=$false; $start.CreateNoWindow=$true
    $start.RedirectStandardOutput=$true; $start.RedirectStandardError=$true
    $start.StandardOutputEncoding=New-Object Text.UTF8Encoding($false)
    $start.StandardErrorEncoding=New-Object Text.UTF8Encoding($false)
    $process=New-Object Diagnostics.Process
    $process.StartInfo=$start
    try {
        $null=$process.Start()
        # Read both pipes concurrently. Windows PowerShell 5.1 never sees stderr as ErrorRecord.
        $stdoutTask=$process.StandardOutput.ReadToEndAsync()
        $stderrTask=$process.StandardError.ReadToEndAsync()
        $process.WaitForExit()
        return [pscustomobject]@{Code=$process.ExitCode;Out=$stdoutTask.GetAwaiter().GetResult();Err=$stderrTask.GetAwaiter().GetResult()}
    } finally {$process.Dispose()}
}
function Read-SafeMigrationError([string]$Text) {
    foreach($line in ($Text -split '\r?\n')){
        if($line.Length -gt 4096 -or -not $line.StartsWith('MIGRATION_ERROR ')){continue}
        try {$event=$line.Substring(16) | ConvertFrom-Json} catch {continue}
        if($allowedStages -ccontains $event.stage -and $messages.ContainsKey([string]$event.reason)){
            $state=if([string]$event.sqlstate -cmatch '^[A-Z0-9]{5}$'){[string]$event.sqlstate}else{''}
            return [pscustomobject]@{Stage=[string]$event.stage;Reason=[string]$event.reason;Sqlstate=$state}
        }
    }
    return $null
}
try {
    $Php=(Resolve-Path -LiteralPath $Php).Path
    if($SimulateSeedBaseline -and $Apply){$reason='BASELINE_APPLY_FORBIDDEN'; throw 'Bloqueado.'}
    if(-not $SimulateSeedBaseline -and $Php -like '*\xampp\*'){$reason='PHP_INDEPENDENT_REQUIRED'; throw 'Bloqueado.'}
    $script=Join-Path $PSScriptRoot 'migrate-demo-records.php'
    $stage='PHP_RUNTIME'; $reason='PHP_START_FAILED'
    $phpOptions=@('-d','display_errors=0','-d','log_errors=0')
    $runtimeResult=Invoke-MigrationPhp -Arguments ($phpOptions+@($script,'--check-runtime'))
    $reason='PHP_PROTOCOL_INVALID'
    if($runtimeResult.Code -ne 0){throw 'Bloqueado.'}
    $runtime=$runtimeResult.Out | ConvertFrom-Json
    if(-not $runtime.mbstring){
        # Load only the DLL shipped in this independent PHP package, for this process only.
        $reason='MBSTRING_REQUIRED'
        $dll=Join-Path (Split-Path $Php -Parent) 'ext\php_mbstring.dll'
        if(-not (Test-Path -LiteralPath $dll -PathType Leaf)){throw 'Bloqueado.'}
        $phpOptions+=@('-d',('extension='+$dll))
        $runtimeResult=Invoke-MigrationPhp -Arguments ($phpOptions+@($script,'--check-runtime'))
        if($runtimeResult.Code -ne 0){throw 'Bloqueado.'}
        $runtime=$runtimeResult.Out | ConvertFrom-Json
        if(-not $runtime.mbstring){throw 'Bloqueado.'}
        Write-Output 'PHP: mbstring cargada solo para este proceso; php.ini y XAMPP no se modifican.'
    }
    $reason='PDO_PGSQL_REQUIRED'
    if(-not $runtime.libpq.pdo_pgsql){throw 'Bloqueado.'}
    if(-not $SimulateSeedBaseline){
        $reason='PGSQL_SNI_REQUIRED'
        foreach($extension in @('pdo_pgsql','pgsql')){
            $version=[string]$runtime.libpq.$extension
            if($version -notmatch '^(\d+)\.' -or [int]$Matches[1] -lt 14){throw 'Bloqueado.'}
        }
    }
    Write-Output ('PHP efectivo: '+$runtime.php+' | '+$runtime.binary)
    Write-Output ('libpq pdo_pgsql='+$runtime.libpq.pdo_pgsql+'; pgsql='+$runtime.libpq.pgsql+'; mbstring=OK')
    $arguments=@()
    if($SimulateSeedBaseline){$arguments+='--simulate-seed-baseline'} else {
        $stage='TARGET_URL'; $reason='HOST_INVALID'
        if($ExpectedHost -cnotmatch '^dpg-[a-z0-9-]+\.[a-z0-9-]+-postgres\.render\.com$'){throw 'Bloqueado.'}
        $reason='TARGET_URL_INPUT_FAILED'
        $secure=Read-Host 'External Database URL ROTADA de clinica-vida-salud-db (oculta)' -AsSecureString
        $pointer=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        try {$plain=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)} finally {[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)}
        $env:MIGRATION_RENDER_URL=$plain; $env:MIGRATION_EXPECTED_HOST=$ExpectedHost; $env:PGSSLSNI='1'
        if($Apply){
            $reason='TARGET_CONFIRMATION_REQUIRED'
            if($PlanHash -cnotmatch '^[a-f0-9]{64}$'){throw 'Bloqueado.'}
            $confirmation=Read-Host 'Para ESCRIBIR, escriba IMPORTAR_FICTICIOS_EN_clinica-vida-salud-db'
            if($confirmation -cne 'IMPORTAR_FICTICIOS_EN_clinica-vida-salud-db'){throw 'Bloqueado.'}
            $env:MIGRATION_CONFIRM=$confirmation; $arguments+='--approve-plan='+$PlanHash
        }
    }
    $stage='PHP_RUNTIME'; $reason='PHP_START_FAILED'
    $result=Invoke-MigrationPhp -Arguments ($phpOptions+@($script)+$arguments)
    if($result.Code -ne 0){
        $reason='PHP_PROTOCOL_INVALID'
        $safeError=Read-SafeMigrationError $result.Err
        if($null -ne $safeError){$stage=$safeError.Stage; $reason=$safeError.Reason; $sqlstate=$safeError.Sqlstate}
        throw 'Bloqueado.'
    }
    # Successful stdout is the deliberately public migration report, never native stderr.
    Write-Output $result.Out.TrimEnd()
} catch {
    $safeMessage=if($messages.ContainsKey($reason)){$messages[$reason]}else{$messages['UNEXPECTED_ERROR']}
    $suffix=if($sqlstate -cmatch '^[A-Z0-9]{5}$'){' [SQLSTATE '+$sqlstate+']'}else{''}
    Write-Output ('DETENIDO ['+$stage+']: '+$reason+' - '+$safeMessage+$suffix)
    exit 1
} finally {
    foreach($name in $names){[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}
    $plain=$null; $secure=$null; $result=$null; $runtimeResult=$null; $previous=$null
}
