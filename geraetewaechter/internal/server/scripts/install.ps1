# Geraetewaechter-Agent - Installation fuer Windows
# Aufruf in einer PowerShell "Als Administrator":
#   & ([scriptblock]::Create((irm __GW_SERVER__/install.ps1))) -Token <REGISTRIERUNGSSCHLUESSEL>
param([Parameter(Mandatory = $true)][string]$Token)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$Server = '__GW_SERVER__'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Bitte PowerShell als Administrator starten.'
}
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$arch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64') { 'arm64' } else { 'amd64' }
$bin = "gw-agent-windows_$arch.exe"
$dir = Join-Path $env:ProgramFiles 'Geraetewaechter'
New-Item -ItemType Directory -Force -Path $dir | Out-Null
$exe = Join-Path $dir 'gw-agent.exe'
$tmp = Join-Path $env:TEMP $bin
$sumsFile = Join-Path $env:TEMP 'gw-SHA256SUMS'

Write-Host "Lade $bin ..."
Invoke-WebRequest -UseBasicParsing "$Server/downloads/$bin" -OutFile $tmp
Invoke-WebRequest -UseBasicParsing "$Server/downloads/SHA256SUMS" -OutFile $sumsFile
$expected = $null
foreach ($line in Get-Content $sumsFile) {
    $parts = $line -split '\s+'
    if ($parts.Count -ge 2 -and $parts[1] -eq $bin) { $expected = $parts[0].ToLower() }
}
$actual = (Get-FileHash $tmp -Algorithm SHA256).Hash.ToLower()
Remove-Item $sumsFile -Force
if (-not $expected -or $expected -ne $actual) { Remove-Item $tmp -Force; throw 'Pruefsumme stimmt nicht - Abbruch.' }

if (Test-Path $exe) { try { & $exe stop | Out-Null } catch {} }
Move-Item -Force $tmp $exe

& $exe enroll --server $Server --token $Token
if ($LASTEXITCODE -ne 0) { throw 'Registrierung fehlgeschlagen.' }
& $exe install
& $exe start
Write-Host ''
Write-Host 'Fertig. Konfiguration (Datenschutz-Einstellungen):'
& $exe paths
