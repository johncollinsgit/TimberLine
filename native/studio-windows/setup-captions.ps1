$ErrorActionPreference = 'Stop'
$studioBase = if ($env:EVERBRANCH_STUDIO_HOME) { $env:EVERBRANCH_STUDIO_HOME } elseif (Test-Path 'G:/') { 'G:/Everbranch/Studio' } else { Join-Path $env:USERPROFILE 'Everbranch/Studio' }
$captionDir = Join-Path $studioBase 'captions'
New-Item -ItemType Directory -Force -Path $captionDir | Out-Null
$stage = Join-Path $captionDir ([guid]::NewGuid().ToString())
New-Item -ItemType Directory -Path $stage | Out-Null
function Get-VerifiedFile($url, $path, $sha) {
    if (Test-Path -LiteralPath $path) {
        if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash -eq $sha) { return }
    }
    $temp = Join-Path $stage ([guid]::NewGuid().ToString())
    Invoke-WebRequest -Uri $url -OutFile $temp
    if ((Get-FileHash -LiteralPath $temp -Algorithm SHA256).Hash -ne $sha) { throw "Checksum mismatch for $url" }
    Move-Item -Force -LiteralPath $temp -Destination $path
}
try {
    $archive = Join-Path $captionDir 'whisper-b5907-x64.zip'
    Get-VerifiedFile 'https://github.com/ggml-org/whisper.cpp/releases/download/b5907/whisper-bin-x64.zip' $archive 'ecca07b277d943e9b9f4712856a144b857271a6b333104e511e1988e80db582b'
    Expand-Archive -Force -LiteralPath $archive -DestinationPath (Join-Path $captionDir 'b5907')
    $model = Join-Path $captionDir 'ggml-base.en.bin'
    Get-VerifiedFile 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-base.en.bin?download=true' $model 'a03779c86df3323075f5e796cb2ce5029f00ec8869eee3fdfb897afe36c6d002'
    Write-Output "Offline English captions are ready in $captionDir."
} finally {
    $resolvedStage = [IO.Path]::GetFullPath($stage)
    $expected = [IO.Path]::GetFullPath($captionDir) + [IO.Path]::DirectorySeparatorChar
    if ($resolvedStage.StartsWith($expected,[StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $resolvedStage -Recurse -Force
    }
}
