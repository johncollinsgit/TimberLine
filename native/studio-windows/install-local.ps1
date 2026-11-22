[CmdletBinding()]
param([string]$Destination)
$ErrorActionPreference = 'Stop'
if (-not $Destination) {
    $Destination = if ($env:EVERBRANCH_STUDIO_HOME) { $env:EVERBRANCH_STUDIO_HOME } elseif (Test-Path 'G:/') { 'G:/Everbranch/Studio' } else { Join-Path $env:USERPROFILE 'Everbranch/Studio' }
}
$target = [IO.Path]::GetFullPath($Destination)
New-Item -ItemType Directory -Path $target -Force | Out-Null
foreach ($file in 'media.py','studio.py','capture.py','rundown.py','social_picker.py','social_import.py','start-studio.cmd','setup-captions.ps1','README.md') {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot $file) -Destination (Join-Path $target $file) -Force
}
New-Item -ItemType Directory -Path (Join-Path $target 'projects') -Force | Out-Null
Write-Output "Launch Everbranch Studio with $(Join-Path $target 'start-studio.cmd')."
