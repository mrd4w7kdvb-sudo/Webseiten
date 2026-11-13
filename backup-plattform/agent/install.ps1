#Requires -RunAsAdministrator
<#
  Installiert den Backup-Agenten auf einem Windows-PC des Kunden.

  Aufruf (PowerShell als Administrator, im entpackten Paketordner):
    powershell -ExecutionPolicy Bypass -File .\install.ps1                  # installieren
    powershell -ExecutionPolicy Bypass -File .\install.ps1 -ErsteSicherung  # + sofort sichern
    powershell -ExecutionPolicy Bypass -File .\install.ps1 -Entfernen       # Zeitplaene entfernen

  Danach den Paketordner vom USB-Stick und vom PC loeschen - er enthaelt Zugangsdaten.
#>
param(
  [switch]$ErsteSicherung,
  [switch]$Entfernen
)
$ErrorActionPreference = 'Stop'
$paket = $PSScriptRoot
$prog  = 'C:\Program Files\BackupAgent'
$conf  = 'C:\ProgramData\BackupAgent'
$rp    = Join-Path $prog 'resticprofile.exe'
$cfg   = Join-Path $conf 'profiles.yaml'
$fehlablageTask = 'BackupAgent Fehlablage-Bericht'

function Schritt($text) { Write-Host "==> $text" -ForegroundColor Cyan }

if ($Entfernen) {
  Schritt 'Zeitplaene entfernen'
  if (Test-Path $rp) { & $rp --config $cfg unschedule --all }
  Unregister-ScheduledTask -TaskName $fehlablageTask -Confirm:$false -ErrorAction SilentlyContinue
  Write-Host 'Zeitplaene entfernt. Programm- und Konfigurationsordner bei Bedarf von Hand loeschen:'
  Write-Host "  $prog"
  Write-Host "  $conf"
  return
}

foreach ($f in 'bin\restic.exe', 'bin\resticprofile.exe', 'profiles.yaml', 'klassen.txt') {
  if (-not (Test-Path (Join-Path $paket $f))) { throw "Fehlt im Paket: $f" }
}

Schritt 'Programme kopieren'
New-Item -ItemType Directory -Force -Path $prog, $conf, "$conf\keys", "$conf\cache", "$conf\logs" | Out-Null
Copy-Item "$paket\bin\restic.exe", "$paket\bin\resticprofile.exe", "$paket\fehlablage-bericht.ps1" $prog -Force

Schritt 'Konfiguration und Schluessel uebernehmen'
Copy-Item "$paket\profiles.yaml", "$paket\fehlablage.url" $conf -Force
Copy-Item "$paket\keys\*.key" "$conf\keys" -Force
# Vorab-Skripte nur anlegen, wenn noch nicht vorhanden (angepasste Exporte bleiben erhalten)
Get-ChildItem "$paket\vorher-*.cmd" -ErrorAction SilentlyContinue | ForEach-Object {
  $ziel = Join-Path $conf $_.Name
  if (-not (Test-Path $ziel)) { Copy-Item $_.FullName $ziel }
}

Schritt 'Klassen-Ordner anlegen'
if (Test-Path "$paket\ordner.txt") {
  Get-Content "$paket\ordner.txt" | Where-Object { $_.Trim() } | ForEach-Object {
    New-Item -ItemType Directory -Force -Path $_.Trim() | Out-Null
    Write-Host "    $($_.Trim())"
  }
}

Schritt 'Zugriff auf Konfiguration sperren (nur SYSTEM und Administratoren)'
# SIDs statt Namen, damit es auch auf deutschem Windows ("Administratoren") funktioniert
& icacls.exe $conf /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'icacls fehlgeschlagen' }

Schritt 'Zeitplaene als Systemaufgaben anlegen'
& $rp --config $cfg schedule --all
if ($LASTEXITCODE -ne 0) { throw 'resticprofile schedule fehlgeschlagen' }

# Verpasste Laeufe (PC war aus) nachholen und auch im Akkubetrieb sichern
Get-ScheduledTask | Where-Object { $_.TaskPath -like '*resticprofile*' -or $_.TaskName -like '*resticprofile*' } | ForEach-Object {
  $_.Settings.StartWhenAvailable = $true
  $_.Settings.DisallowStartIfOnBatteries = $false
  $_.Settings.StopIfGoingOnBatteries = $false
  Set-ScheduledTask -InputObject $_ | Out-Null
  Write-Host "    $($_.TaskPath)$($_.TaskName)"
}

Schritt 'Woechentlichen Fehlablage-Bericht einrichten'
$action    = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$prog\fehlablage-bericht.ps1`""
$trigger   = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Monday -At '08:00'
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings  = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName $fehlablageTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null

if ($ErsteSicherung) {
  foreach ($klasse in (Get-Content "$paket\klassen.txt" | Where-Object { $_.Trim() })) {
    Schritt "Erste Sicherung: $klasse"
    & $rp --config $cfg --name $klasse.Trim() backup
    if ($LASTEXITCODE -ne 0) { Write-Warning "Sicherung $klasse fehlgeschlagen (Exit-Code $LASTEXITCODE)" }
  }
}

Write-Host ''
Write-Host 'Installation abgeschlossen.' -ForegroundColor Green
Write-Host 'Jetzt den Paketordner (USB-Stick und PC) loeschen - er enthaelt Zugangsdaten.' -ForegroundColor Yellow
