$ClientId    = '1513582704623353968'
$Port        = 6700
$StaleSeconds = 45
$AllowedOrigins = @(
    'https://nullpunkts.com.pr',
    'https://nullpunkts.share.zrok.io',
    'https://nullpunkts.saturngruppe.de',
    'http://localhost',
    'http://127.0.0.1',
    'http://nullpunkts.com.pr'
)
$TimestampsInSeconds = $false

$ErrorActionPreference = 'Stop'

function Log($msg) {
    Write-Host ("[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $msg)
}

$script:pipe = $null

function Connect-Discord {
    if ($script:pipe -and $script:pipe.IsConnected) { return $true }
    if ($ClientId -eq 'PASTE_YOUR_DISCORD_APPLICATION_ID_HERE' -or [string]::IsNullOrWhiteSpace($ClientId)) {
        Log 'ERROR: $ClientId is not set — edit presence.ps1 and paste your Discord Application ID.'
        return $false
    }
    for ($i = 0; $i -le 9; $i++) {
        try {
            $p = New-Object System.IO.Pipes.NamedPipeClientStream('.', "discord-ipc-$i", `
                    [System.IO.Pipes.PipeDirection]::InOut, [System.IO.Pipes.PipeOptions]::Asynchronous)
            $p.Connect(800)
            $script:pipe = $p
            Write-Frame 0 ('{"v":1,"client_id":"' + $ClientId + '"}')
            $ready = Read-Frame
            if ($ready) { Log "Discord connected (discord-ipc-$i)."; return $true }
        } catch {
            if ($p) { $p.Dispose() }
        }
    }
    $script:pipe = $null
    return $false
}

function Write-Frame([int]$op, [string]$json) {
    $payload = [System.Text.Encoding]::UTF8.GetBytes($json)
    $header  = New-Object byte[] 8
    [BitConverter]::GetBytes([int32]$op).CopyTo($header, 0)
    [BitConverter]::GetBytes([int32]$payload.Length).CopyTo($header, 4)
    $script:pipe.Write($header, 0, 8)
    $script:pipe.Write($payload, 0, $payload.Length)
    $script:pipe.Flush()
}

function Read-Frame {
    try {
        $header = New-Object byte[] 8
        $n = $script:pipe.Read($header, 0, 8)
        if ($n -lt 8) { return $null }
        $len = [BitConverter]::ToInt32($header, 4)
        $buf = New-Object byte[] $len
        $off = 0
        while ($off -lt $len) {
            $r = $script:pipe.Read($buf, $off, $len - $off)
            if ($r -le 0) { break }
            $off += $r
        }
        return [System.Text.Encoding]::UTF8.GetString($buf, 0, $off)
    } catch { return $null }
}

function Send-Activity($activity) {
    if (-not (Connect-Discord)) { return }
    $activityArgs = @{ pid = $PID; activity = $activity }
    $frame = @{ cmd = 'SET_ACTIVITY'; args = $activityArgs; nonce = [guid]::NewGuid().ToString() }
    $json = $frame | ConvertTo-Json -Depth 12 -Compress
    try {
        Write-Frame 1 $json
        [void](Read-Frame)
    } catch {
        Log "Discord write failed (client closed?) — will reconnect on next update."
        if ($script:pipe) { try { $script:pipe.Dispose() } catch {} }
        $script:pipe = $null
    }
}

function Clear-Activity {
    Send-Activity $null
}

function Build-Activity($d) {
    $activity = @{ type = 2 }
    if ($d.name)     { $activity.details = [string]$d.name }
    if ($d.category) { $activity.state   = [string]$d.category }

    $assets = @{}
    if ($d.art -and ([string]$d.art).StartsWith('http')) {
        $assets.large_image = [string]$d.art
    } elseif ($d.art) {
        $assets.large_image = 'logo'
    } else {
        $assets.large_image = 'logo'
    }
    $assets.large_text = 'Nullpunkts'

    if ($d.paused) {
        $assets.small_text = 'Paused'
    } elseif ($d.startMs -and $d.endMs -and ([double]$d.endMs -gt [double]$d.startMs)) {
        $start = [long][double]$d.startMs
        $end   = [long][double]$d.endMs
        if ($TimestampsInSeconds) { $start = [long]($start / 1000); $end = [long]($end / 1000) }
        $activity.timestamps = @{ start = $start; end = $end }
    }
    $activity.assets = $assets
    return $activity
}

$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add("http://127.0.0.1:$Port/")
try {
    $listener.Start()
} catch {
    Log "ERROR: could not bind http://127.0.0.1:$Port/ — is another instance running, or the port taken? ($($_.Exception.Message))"
    exit 1
}
Log "Listening on http://127.0.0.1:$Port/  (press Ctrl+C to stop)"
[void](Connect-Discord)   # try early; harmless if Discord isn't up yet

$script:lastUpdateUtc = $null
$script:isActive = $false

function Add-Cors($resp, $origin) {
    if ($origin -and ($AllowedOrigins -contains $origin)) {
        $resp.AddHeader('Access-Control-Allow-Origin', $origin)
    } else {
        $resp.AddHeader('Access-Control-Allow-Origin', '*')
    }
    $resp.AddHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
    $resp.AddHeader('Access-Control-Allow-Headers', 'content-type')
    $resp.AddHeader('Access-Control-Allow-Private-Network', 'true')
    $resp.AddHeader('Vary', 'Origin')
}

$ctxTask = $listener.GetContextAsync()
while ($true) {
    if ($ctxTask.Wait(1000)) {
        $ctx  = $ctxTask.Result
        $req  = $ctx.Request
        $resp = $ctx.Response
        $origin = $req.Headers['Origin']
        try {
            Add-Cors $resp $origin
            if ($req.HttpMethod -eq 'OPTIONS') {
                $resp.StatusCode = 204
            } elseif ($req.HttpMethod -eq 'POST' -and $req.Url.AbsolutePath -eq '/np') {
                $reader = New-Object System.IO.StreamReader($req.InputStream, $req.ContentEncoding)
                $body = $reader.ReadToEnd(); $reader.Close()
                $data = $null
                try { $data = $body | ConvertFrom-Json } catch {}
                if ($data) {
                    if ($data.clear) {
                        Clear-Activity; $script:isActive = $false
                        Log 'cleared'
                    } else {
                        Send-Activity (Build-Activity $data)
                        $script:isActive = $true
                        if (-not $data.paused) { $script:lastUpdateUtc = [DateTime]::UtcNow }
                        Log ("now: {0} — {1}{2}" -f $data.name, $data.category, ($(if ($data.paused) { ' (paused)' } else { '' })))
                    }
                }
                $resp.StatusCode = 200
                $bytes = [System.Text.Encoding]::UTF8.GetBytes('ok')
                $resp.OutputStream.Write($bytes, 0, $bytes.Length)
            } else {
                $resp.StatusCode = 404
            }
        } catch {
            Log "request error: $($_.Exception.Message)"
            try { $resp.StatusCode = 500 } catch {}
        } finally {
            try { $resp.OutputStream.Close() } catch {}
        }
        $ctxTask = $listener.GetContextAsync()
    }

    if ($script:isActive -and $script:lastUpdateUtc -ne $null) {
        if (([DateTime]::UtcNow - $script:lastUpdateUtc).TotalSeconds -gt $StaleSeconds) {
            Clear-Activity; $script:isActive = $false
            Log 'cleared (stale — no update)'
        }
    }
}
