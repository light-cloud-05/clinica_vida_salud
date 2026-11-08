#requires -Version 5.1
[CmdletBinding()]
param([string]$BaseUrl='https://clinica-vida-salud-vgve.onrender.com')
$ErrorActionPreference='Stop'
if($BaseUrl -cne 'https://clinica-vida-salud-vgve.onrender.com'){throw 'Destino HTTP no autorizado.'}
$records=@(); $secret=$null; $plain=$null
function Read-Page([string]$Path,[Microsoft.PowerShell.Commands.WebRequestSession]$Session,[hashtable]$Body){
    $args=@{Uri=($BaseUrl+$Path);WebSession=$Session;UseBasicParsing=$true;TimeoutSec=90;MaximumRedirection=5}
    if($null -ne $Body){$args.Method='Post';$args.Body=$Body}
    try {$r=Invoke-WebRequest @args; return [pscustomobject]@{Code=[int]$r.StatusCode;Body=$r.Content;Headers=$r.Headers;Stream=$r.RawContentStream}}
    catch {if($null -ne $_.Exception.Response){return [pscustomobject]@{Code=[int]$_.Exception.Response.StatusCode;Body='';Headers=@{};Stream=$null}};throw 'Fallo de red HTTP; detalles privados omitidos.'}
}
function Test-Result([bool]$Condition,[string]$Label){if(-not $Condition){throw ('Verificacion fallida: '+$Label)};Write-Output ('OK: '+$Label);$script:records+=$Label}
try {
    $secret=Import-Clixml -LiteralPath (Join-Path $HOME 'clinica-vida-salud-render-demo-secrets.clixml')
    foreach($pair in @(@('ADMIN','demo_admin'),@('RECEPTION','demo_recepcion'),@('DOCTOR','demo_medico'),@('PATIENT','demo_paciente'))){
        $role=$pair[0];$username=$pair[1]
        if($secret[$role] -isnot [Security.SecureString]){throw 'Archivo DPAPI invalido.'}
        $session=New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $login=Read-Page '/login' $session $null
        Test-Result ($login.Code -eq 200) ($role+' formulario login HTTPS')
        $csrf=[regex]::Match([string]$login.Body,'name="csrf" value="([a-f0-9]{64})"').Groups[1].Value
        Test-Result ($csrf.Length -eq 64) ($role+' CSRF presente')
        $cookies=$session.Cookies.GetCookies([Uri]$BaseUrl)
        $cookie=@($cookies | Where-Object {$_.Name -eq 'CVS_SESSION'})
        Test-Result ($cookie.Count -eq 1 -and $cookie[0].Secure -and $cookie[0].HttpOnly) ($role+' cookie Secure y HttpOnly')
        $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret[$role])
        try {$plain=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)} finally {[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)}
        $result=Read-Page '/login' $session @{csrf=$csrf;identifier=$username;password=$plain}
        $plain=$null
        Test-Result ($result.Code -eq 200 -and [string]$result.Body -like ('*'+$username+'*') -and [string]$result.Body -notlike '*name="identifier"*') ($role+' login y panel')
        $reports=Read-Page '/reportes' $session $null
        Test-Result ($reports.Code -eq 200 -and [string]$reports.Body -like '*Reportes*') ($role+' reportes autorizados')
        $csv=Read-Page '/reportes/exportar?formato=csv' $session $null
        Test-Result ($csv.Code -eq 200 -and [string]$csv.Headers['Content-Type'] -like '*text/csv*') ($role+' CSV HTTP')
        $pdf=Read-Page '/reportes/exportar?formato=pdf' $session $null
        $signature=New-Object byte[] 5
        if($null -ne $pdf.Stream){$pdf.Stream.Position=0;$null=$pdf.Stream.Read($signature,0,5)}
        Test-Result ($pdf.Code -eq 200 -and [Text.Encoding]::ASCII.GetString($signature) -eq '%PDF-') ($role+' PDF HTTP')
        if($role -in @('DOCTOR','PATIENT')){Test-Result ((Read-Page '/pacientes' $session $null).Code -eq 403) ($role+' directorio privado bloqueado')}
        if($role -ne 'ADMIN'){Test-Result ((Read-Page '/usuarios' $session $null).Code -eq 403) ($role+' usuarios administrativos bloqueados')}
        $csrf=[regex]::Match([string]$result.Body,'name="csrf" value="([a-f0-9]{64})"').Groups[1].Value
        $logout=Read-Page '/logout' $session @{csrf=$csrf}
        Test-Result ($logout.Code -eq 200 -and [string]$logout.Body -like '*name="identifier"*') ($role+' logout')
    }
    Write-Output ($records.Count.ToString()+' comprobaciones HTTP Render correctas. Sin operaciones clinicas ni pagos.')
} catch {
    Write-Output 'Verificacion Render detenida. Detalles nativos, cuerpos y credenciales omitidos.'
    exit 1
} finally {$plain=$null;$secret=$null;$session=$null;$result=$null;$login=$null;$reports=$null;$csv=$null;$pdf=$null;$csrf=$null}
