param([switch]$Force)

$ErrorActionPreference = 'Stop'

if (-not (Get-Command ffmpeg -ErrorAction SilentlyContinue)) {
    Write-Host 'ERROR: ffmpeg not found on PATH.'
    exit 1
}

$imgDir = (Resolve-Path (Join-Path $PSScriptRoot '..\m\img')).Path
$posterDir = Join-Path $imgDir 'poster'
if (-not (Test-Path $posterDir)) { New-Item -ItemType Directory -Path $posterDir | Out-Null }

function Get-Frame($src, $ts, $out) {
    try { & ffmpeg -y -loglevel error -ss $ts -i $src -vf "scale='min(512,iw)':-1" -frames:v 1 -q:v 4 $out 2>$null } catch {}
    if (Test-Path $out) { return (Get-Item $out).Length }
    return 0
}

$sources = Get-ChildItem -Path $imgDir -File | Where-Object { $_.Extension -match '^\.(mp4|webm|gif)$' }
$made = 0; $skipped = 0; $failed = 0

foreach ($s in $sources) {
    $base = [System.IO.Path]::GetFileNameWithoutExtension($s.Name)
    $out = Join-Path $posterDir "$base.jpg"

    if ((Test-Path $out) -and -not $Force -and (Get-Item $out).LastWriteTime -ge $s.LastWriteTime) {
        $skipped++
        continue
    }

    if ($s.Extension -eq '.gif') {
        if ((Get-Frame $s.FullName 0 $out) -gt 0) { $made++ }
        else { $failed++; Write-Host "FAIL: $($s.Name)" }
        continue
    }

    $best = 0; $bestTmp = $null
    foreach ($t in @(0, 5, 15, 30, 60, 90, 120, 180)) {
        $tmp = Join-Path $posterDir ("_tmp_{0}_{1}.jpg" -f $base, $t)
        $sz = Get-Frame $s.FullName $t $tmp
        if ($sz -gt $best) {
            if ($bestTmp) { Remove-Item $bestTmp -Force -ErrorAction SilentlyContinue }
            $best = $sz; $bestTmp = $tmp
        } elseif (Test-Path $tmp) {
            Remove-Item $tmp -Force -ErrorAction SilentlyContinue
        }
    }

    if ($bestTmp -and $best -gt 0) {
        Move-Item -Force $bestTmp $out
        $made++
    } else {
        $failed++; Write-Host "FAIL: $($s.Name)"
    }
}

Write-Host "posters: made=$made skipped=$skipped failed=$failed  ($posterDir)"
