# Delete already-imported session photos from the Samsung A34 (MTP).
#
# SAFETY: only deletes Camera files whose EXACT name appears in the delete-list
# (built from production `artwork.upload` provenance -- i.e. confirmed backed up).
# Any phone file not named in the list is left untouched. Any listed name not
# found on the phone is reported and skipped.
#
# MTP delete is PERMANENT (no recycle bin). Dry-run is the default; pass -Execute
# to actually delete.
#
#   powershell -ExecutionPolicy Bypass -File tools\unstage-phone-photos.ps1            # dry-run
#   powershell -ExecutionPolicy Bypass -File tools\unstage-phone-photos.ps1 -Execute   # delete

param(
  [string]$ListPath = 'c:\xampp\htdocs\lifedrawing\storage\photo-import\phone-delete-list.txt',
  [switch]$Execute
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $ListPath)) { throw "Delete-list not found: $ListPath" }
$targets = Get-Content $ListPath | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' }
$targetSet = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)
foreach ($t in $targets) { [void]$targetSet.Add($t) }
Write-Host ("Delete-list: {0} filename(s) from {1}" -f $targetSet.Count, $ListPath)

$shell    = New-Object -ComObject Shell.Application
$phone    = $shell.Namespace(0x11).Items() | Where-Object { $_.Name -like '*A34*' }
if (-not $phone) { throw "Phone (A34) not found - unlock it and set USB to file-transfer." }
$internal = $phone.GetFolder.Items()    | Where-Object { $_.Name -eq 'Internal storage' }
$dcim     = $internal.GetFolder.Items() | Where-Object { $_.Name -eq 'DCIM' }
$camera   = ($dcim.GetFolder.Items()    | Where-Object { $_.Name -eq 'Camera' }).GetFolder

$items = @($camera.Items())
Write-Host ("Camera folder: {0} item(s)" -f $items.Count)

# Match targets against phone items by exact name.
$onPhone = @{}
foreach ($it in $items) { $onPhone[$it.Name] = $it }

$toDelete = @()
$notFound = @()
foreach ($name in $targetSet) {
  if ($onPhone.ContainsKey($name)) { $toDelete += $onPhone[$name] } else { $notFound += $name }
}

Write-Host ("`nMatched on phone (would delete): {0}" -f $toDelete.Count)
Write-Host ("Listed but NOT on phone (skip):  {0}" -f $notFound.Count)
if ($notFound.Count -gt 0) { $notFound | Sort-Object | ForEach-Object { Write-Host ("    not found: {0}" -f $_) -ForegroundColor DarkYellow } }

if (-not $Execute) {
  Write-Host "`nDRY RUN -- nothing deleted. Re-run with -Execute to delete the matched files." -ForegroundColor Cyan
  return
}

Write-Host "`nDeleting..." -ForegroundColor Yellow
$deleted = 0; $failed = @()
foreach ($it in $toDelete) {
  $name = $it.Name
  $verb = $it.Verbs() | Where-Object { ($_.Name -replace '&','') -match '^(Delete|Delete file|Permanently delete)$' } | Select-Object -First 1
  try {
    if ($verb) { $verb.DoIt() } else { $it.InvokeVerb('delete') }
  } catch {
    $failed += $name; continue
  }
}

# Give MTP a moment, then re-enumerate to confirm removals.
Start-Sleep -Seconds 3
$after = @{}
foreach ($it in @($camera.Items())) { $after[$it.Name] = $true }
$stillThere = @()
foreach ($it in $toDelete) { if ($after.ContainsKey($it.Name)) { $stillThere += $it.Name } else { $deleted++ } }

Write-Host ("`nDeleted: {0}/{1}" -f $deleted, $toDelete.Count)
if ($stillThere.Count -gt 0) {
  Write-Host ("STILL ON PHONE (not deleted): {0}" -f $stillThere.Count) -ForegroundColor Red
  $stillThere | Sort-Object | ForEach-Object { Write-Host ("    remains: {0}" -f $_) -ForegroundColor Red }
}
