# Stage life-drawing session photos from the Samsung A34 (MTP) to local scratch.
# Read-only on the phone; copies Camera photos by date prefix into
# storage\photo-import\{sessionId}\ for review before upload.
# Verifies each copied file exists with a byte size matching the source.
#
# Copies images only. The date prefix alone used to match anything shot that day,
# which is how 147 MB of video from 2026-06-06 ended up in staging. Non-image files
# are listed rather than passed over silently: the phone delete-list is built from
# staged .jpg names, so anything not staged also never gets deleted off the phone.
#
# Staged JPEGs still need tools\strip-jpeg-trailers.php run over them before import --
# Samsung hides a video (Motion Photo) or a second photograph (Live Focus) after the
# JPEG's end-of-image marker, and nothing else in the pipeline looks for it.
#
# Stages every session in $map by default. Pass -Ids to stage only some of them --
# older sessions whose artwork is already imported can still have non-artwork strays
# left on the phone, and re-staging those resurrects photos that were pruned on purpose.
#
#   powershell -ExecutionPolicy Bypass -File tools\stage-phone-photos.ps1            # all
#   powershell -ExecutionPolicy Bypass -File tools\stage-phone-photos.ps1 -Ids 288   # one

param(
  [int[]]$Ids
)

$ErrorActionPreference = 'Stop'

$map = @(
  @{ id = 267; date = '20260524' },
  @{ id = 268; date = '20260605' },
  @{ id = 269; date = '20260606' },
  @{ id = 271; date = '20260619' },
  @{ id = 273; date = '20260621' },
  @{ id = 276; date = '20260705' },
  @{ id = 278; date = '20260718' },
  @{ id = 279; date = '20260719' },
  @{ id = 282; date = '20260802' },
  @{ id = 284; date = '20260815' },
  @{ id = 285; date = '20260816' },
  @{ id = 288; date = '20260830' },
  @{ id = 291; date = '20260913' }
)

if ($Ids) {
  $map = @($map | Where-Object { $Ids -contains $_.id })
  if ($map.Count -eq 0) { throw "No session in the map matches -Ids $($Ids -join ', ')" }
}

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

  $dated   = @($allCamera | Where-Object { $_.Name.StartsWith($m.date) })
  $items   = @($dated | Where-Object { $_.Name -match '\.(jpg|jpeg|png)$' })
  $skipped = @($dated | Where-Object { $_.Name -notmatch '\.(jpg|jpeg|png)$' })

  # source name -> size in bytes (System.Size = property index 0x300...; use ExtendedProperty)
  $srcSizes = @{}
  foreach ($it in $items) { $srcSizes[$it.Name] = [int64]$it.ExtendedProperty('System.Size') }

  Write-Host ("Session {0} ({1}): {2} source photos -> {3}" -f $m.id, $m.date, $items.Count, $dest)

  if ($skipped.Count -gt 0) {
    Write-Host ("  -> NOT staged, {0} non-image file(s) shot the same day:" -f $skipped.Count) -ForegroundColor Yellow
    foreach ($s in $skipped) {
      $sz = [int64]$s.ExtendedProperty('System.Size')
      Write-Host ("     {0}  {1:N0} bytes" -f $s.Name, $sz) -ForegroundColor Yellow
    }
    Write-Host "     These stay on the phone and will NOT enter phone-delete-list.txt." -ForegroundColor Yellow
  }

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
