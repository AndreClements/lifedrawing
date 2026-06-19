# Stage life-drawing session photos from the Samsung A34 (MTP) to local scratch.
# Read-only on the phone; copies Camera photos by date prefix into
# storage\photo-import\{sessionId}\ for review before upload.
# Verifies each copied file exists with a byte size matching the source.

$ErrorActionPreference = 'Stop'

$map = @(
  @{ id = 267; date = '20260524' },
  @{ id = 268; date = '20260605' },
  @{ id = 269; date = '20260606' },
  @{ id = 271; date = '20260619' }
)

$base = 'c:\xampp\htdocs\lifedrawing\storage\photo-import'

$shell    = New-Object -ComObject Shell.Application
$phone    = $shell.Namespace(0x11).Items() | Where-Object { $_.Name -like '*A34*' }
$internal = $phone.GetFolder.Items()    | Where-Object { $_.Name -eq 'Internal storage' }
$dcim     = $internal.GetFolder.Items() | Where-Object { $_.Name -eq 'DCIM' }
$camera   = ($dcim.GetFolder.Items()    | Where-Object { $_.Name -eq 'Camera' }).GetFolder

$allCamera = @($camera.Items())

foreach ($m in $map) {
  $dest = Join-Path $base ([string]$m.id)
  New-Item -ItemType Directory -Force -Path $dest | Out-Null
  $destFolder = $shell.Namespace($dest)

  $items = @($allCamera | Where-Object { $_.Name.StartsWith($m.date) })

  # source name -> size in bytes (System.Size = property index 0x300...; use ExtendedProperty)
  $srcSizes = @{}
  foreach ($it in $items) { $srcSizes[$it.Name] = [int64]$it.ExtendedProperty('System.Size') }

  Write-Host ("Session {0} ({1}): {2} source photos -> {3}" -f $m.id, $m.date, $items.Count, $dest)

  foreach ($it in $items) {
    $target = Join-Path $dest $it.Name
    if ((Test-Path $target) -and ((Get-Item $target).Length -eq $srcSizes[$it.Name])) { continue }
    $destFolder.CopyHere($it, 0x14)  # 0x10 yes-to-all + 0x04 no progress UI
  }

  # Wait until every target exists with a stable size matching the source (async MTP copy).
  $deadline = (Get-Date).AddMinutes(8)
  do {
    Start-Sleep -Milliseconds 1000
    $pending = 0
    foreach ($it in $items) {
      $t = Join-Path $dest $it.Name
      if (-not (Test-Path $t)) { $pending++; continue }
      if ((Get-Item $t).Length -ne $srcSizes[$it.Name]) { $pending++ }
    }
  } while ($pending -gt 0 -and (Get-Date) -lt $deadline)

  # Report
  $ok = 0; $bad = @()
  foreach ($it in $items) {
    $t = Join-Path $dest $it.Name
    if ((Test-Path $t) -and ((Get-Item $t).Length -eq $srcSizes[$it.Name])) { $ok++ }
    else { $bad += $it.Name }
  }
  Write-Host ("  -> copied OK: {0}/{1}" -f $ok, $items.Count)
  if ($bad.Count -gt 0) { Write-Host ("  -> MISSING/SIZE-MISMATCH: {0}" -f ($bad -join ', ')) -ForegroundColor Red }
}

Write-Host "`nDONE. Staged under $base"
