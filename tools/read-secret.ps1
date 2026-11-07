# Auxiliar local: solo memoria y pipe anónimo al proceso PHP. No escribe archivos ni consola.
$ErrorActionPreference = 'Stop'
$secret = [Security.SecureString]::new()
$pointer = [IntPtr]::Zero
try {
    if ([Console]::IsInputRedirected) { throw 'Se requiere consola interactiva.' }
    while ($true) {
        $key = [Console]::ReadKey($true)
        if ($key.Key -eq [ConsoleKey]::Enter) { break }
        if ($key.Key -eq [ConsoleKey]::Escape -or (($key.Modifiers -band [ConsoleModifiers]::Control) -and $key.Key -eq [ConsoleKey]::C)) { exit 1 }
        if ($key.Key -eq [ConsoleKey]::Backspace) {
            if ($secret.Length -gt 0) { $secret.RemoveAt($secret.Length - 1) }
        } elseif (-not [char]::IsControl($key.KeyChar)) {
            $secret.AppendChar($key.KeyChar)
        }
        if ($secret.Length -gt 256) { exit 1 }
    }
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
    $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    $bytes = [Text.Encoding]::UTF8.GetBytes($plain)
    [Console]::Out.Write([Convert]::ToBase64String($bytes))
    [Array]::Clear($bytes, 0, $bytes.Length)
    $plain = $null
} catch {
    exit 1
} finally {
    if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    $secret.Dispose()
}
