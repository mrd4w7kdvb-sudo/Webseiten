<#
  Woechentlicher Bericht: neue Dateien ausserhalb der Klassen-Ordner (Desktop, Dokumente, Downloads).
  Diese Dateien sind ueber die Klasse "unsortiert" gesichert, sollten aber in den richtigen
  Klassen-Ordner verschoben werden. Der Bericht geht an Healthchecks und nach
  C:\ProgramData\BackupAgent\logs.
#>
$ErrorActionPreference = 'Continue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$conf   = 'C:\ProgramData\BackupAgent'
$url    = Get-Content "$conf\fehlablage.url" -ErrorAction SilentlyContinue | Where-Object { $_.Trim() } | Select-Object -First 1
$seit   = (Get-Date).AddDays(-7)
$ordner = 'Desktop', 'Documents', 'Downloads'
$ignorieren = '.lnk', '.ini', '.tmp', '.url'

$treffer = @()
Get-ChildItem 'C:\Users' -Directory -ErrorAction SilentlyContinue | ForEach-Object {
  foreach ($o in $ordner) {
    $pfad = Join-Path $_.FullName $o
    if (Test-Path $pfad) {
      $treffer += Get-ChildItem $pfad -File -Recurse -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -ge $seit -and $ignorieren -notcontains $_.Extension.ToLower() }
    }
  }
}

$zeilen = $treffer | Sort-Object LastWriteTime -Descending | Select-Object -First 300 |
  ForEach-Object { '{0:yyyy-MM-dd HH:mm}  {1}' -f $_.LastWriteTime, $_.FullName }

$text = "Rechner: $env:COMPUTERNAME`n" +
        "Neue Dateien ausserhalb der Klassen-Ordner (letzte 7 Tage): $($treffer.Count)`n`n" +
        ($zeilen -join "`n")

New-Item -ItemType Directory -Force -Path "$conf\logs" | Out-Null
$text | Out-File "$conf\logs\fehlablage-$(Get-Date -Format yyyy-MM-dd).txt" -Encoding utf8

if ($url) {
  try {
    Invoke-RestMethod -Method Post -Uri $url.Trim() -ContentType 'text/plain; charset=utf-8' `
      -Body ([Text.Encoding]::UTF8.GetBytes($text)) -TimeoutSec 30 | Out-Null
  } catch {
    Write-Warning "Bericht konnte nicht gesendet werden: $_"
  }
}
