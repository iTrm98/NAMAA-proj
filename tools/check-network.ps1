# NAMAA network check (no admin needed, reads only).
# Lists the laptop's IPv4 addresses, tests the API (8000) and Reverb (8080) on each,
# and checks the inbound firewall rules, so boards and phones can reach the laptop.
# Usage (PowerShell 5.1 or 7):  powershell -ExecutionPolicy Bypass -File tools\check-network.ps1

$ErrorActionPreference = 'Continue'
$script:fails = 0

function Say-Ok($msg) { Write-Host ('[OK]   ' + $msg) -ForegroundColor Green }
function Say-Info($msg) { Write-Host ('[INFO] ' + $msg) }
function Say-Fail($msg, $fix) {
    $script:fails++
    Write-Host ('[FAIL] ' + $msg) -ForegroundColor Red
    if ($fix) { Write-Host ('       Fix: ' + $fix) -ForegroundColor Yellow }
}

function Test-Port($ip, $port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $async = $client.BeginConnect($ip, $port, $null, $null)
        if (-not $async.AsyncWaitHandle.WaitOne(2000)) { return $false }
        $client.EndConnect($async)
        return $true
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

function Test-Health($ip) {
    try {
        $r = Invoke-WebRequest -Uri ('http://' + $ip + ':8000/api/health') -UseBasicParsing -TimeoutSec 4
        if ($r.StatusCode -eq 200 -and $r.Content -match '"status"\s*:\s*"ok"') { return $true }
        return $false
    } catch {
        return $false
    }
}

Write-Host 'NAMAA network check' -ForegroundColor Cyan
Write-Host ''

# 1) IPv4 addresses per adapter
$addrs = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' })
$adapters = @{}
foreach ($a in (Get-NetAdapter -IncludeHidden -ErrorAction SilentlyContinue)) { $adapters[[int]$a.ifIndex] = $a }
$profiles = @{}
foreach ($p in (Get-NetConnectionProfile -ErrorAction SilentlyContinue)) { $profiles[[int]$p.InterfaceIndex] = $p }

if ($addrs.Count -eq 0) {
    Say-Fail 'No IPv4 address found.' 'Connect to Wi-Fi or turn on Mobile Hotspot (Settings > Network > Mobile hotspot).'
}

$hotspotFound = $false
$targets = @()
foreach ($ip in $addrs) {
    $desc = ''
    if ($adapters.ContainsKey([int]$ip.InterfaceIndex)) { $desc = $adapters[[int]$ip.InterfaceIndex].InterfaceDescription }
    $kind = ''
    if ($ip.IPAddress -eq '192.168.137.1' -or $desc -match 'Wi-Fi Direct Virtual') {
        $kind = '  <- Mobile Hotspot (boards: SERVER_HOST 192.168.137.1)'
        $hotspotFound = $true
    } elseif ($ip.InterfaceAlias -match 'vEthernet|WSL|Docker') {
        $kind = '  (virtual, not for boards)'
    }
    $prof = ''
    if ($profiles.ContainsKey([int]$ip.InterfaceIndex)) { $prof = ' [' + $profiles[[int]$ip.InterfaceIndex].NetworkCategory + ']' }
    Say-Info ($ip.InterfaceAlias + ': ' + $ip.IPAddress + $prof + $kind)
    if ($ip.InterfaceAlias -notmatch 'vEthernet|WSL|Docker') { $targets += $ip.IPAddress }
}
if (-not $hotspotFound) {
    Say-Info 'Mobile Hotspot is off (no 192.168.137.1). Boards on the hotspot need it on.'
}
foreach ($p in $profiles.Values) {
    if ($p.NetworkCategory -eq 'Public') {
        Say-Info ('Network "' + $p.Name + '" (' + $p.InterfaceAlias + ') is Public: the NAMAA firewall rules only allow Private. Set it to Private in Settings > Network if boards use it.')
    }
}
Write-Host ''

# 2) Services on localhost and on each address
if (Test-Health '127.0.0.1') {
    Say-Ok 'API answers on http://127.0.0.1:8000/api/health'
} else {
    Say-Fail 'API does not answer on http://127.0.0.1:8000/api/health' 'Start the stack: start-namaa.bat (Docker Desktop must run), then docker compose ps.'
}
if (Test-Port '127.0.0.1' 8080) {
    Say-Ok 'Reverb port 8080 open on 127.0.0.1'
} else {
    Say-Fail 'Reverb port 8080 closed on 127.0.0.1' 'docker compose ps; docker compose logs --tail 30 reverb'
}
foreach ($t in $targets) {
    if (Test-Health $t) {
        Say-Ok ('API answers on http://' + $t + ':8000/api/health')
    } else {
        Say-Fail ('API does not answer on http://' + $t + ':8000/api/health') 'Check the stack is up; Docker must publish 8000 on all interfaces (0.0.0.0).'
    }
    if (Test-Port $t 8080) {
        Say-Ok ('Reverb port 8080 open on ' + $t)
    } else {
        Say-Fail ('Reverb port 8080 closed on ' + $t) 'Check the reverb container and that 8080 is published on all interfaces.'
    }
}
Say-Info 'These tests run on the laptop itself, so the firewall does not apply to them. Other devices also need the rules below.'
Write-Host ''

# 3) Inbound firewall rules for 8000 / 8080
# netsh works without admin (Get-NetFirewallPortFilter does not). Its output is
# parsed in English; on another Windows language the check falls back to rule names.
$fwRules = @()
$cur = $null
foreach ($line in (netsh advfirewall firewall show rule name=all dir=in 2>$null)) {
    if ($line -match '^Rule Name:\s*(.*)$') {
        if ($cur) { $fwRules += $cur }
        $cur = @{ Name = $Matches[1].Trim(); Enabled = ''; Action = ''; Protocol = ''; LocalPort = ''; Profiles = '' }
    } elseif ($cur -and $line -match '^(Enabled|Action|Protocol|LocalPort|Profiles):\s*(.*)$') {
        $cur[$Matches[1]] = $Matches[2].Trim()
    }
}
if ($cur) { $fwRules += $cur }

foreach ($port in @(8000, 8080)) {
    $rules = @()
    foreach ($r in $fwRules) {
        if ($r.Enabled -ne 'Yes' -or $r.Action -ne 'Allow' -or $r.Protocol -ne 'TCP') { continue }
        $ports = $r.LocalPort -split ','
        if ($ports -contains [string]$port) { $rules += $r }
    }
    if ($fwRules.Count -eq 0) {
        $named = @(Get-NetFirewallRule -DisplayName 'NAMAA*' -ErrorAction SilentlyContinue |
            Where-Object { $_.Direction -eq 'Inbound' -and $_.Enabled -eq 'True' })
        foreach ($n in $named) { $rules += @{ Name = $n.DisplayName; Profiles = [string]$n.Profile } }
    }
    if ($rules.Count -gt 0) {
        $names = ($rules | ForEach-Object { $_.Name + ' (' + $_.Profiles + ')' }) -join ', '
        Say-Ok ('Inbound TCP ' + $port + ' allowed by: ' + $names)
    } else {
        Say-Fail ('No inbound allow rule for TCP ' + $port + '.') 'Run as administrator: powershell -ExecutionPolicy Bypass -File tools\open-firewall.ps1'
    }
}

Write-Host ''
if ($script:fails -eq 0) {
    Write-Host 'All checks passed.' -ForegroundColor Green
} else {
    Write-Host ([string]$script:fails + ' check(s) failed. See the fixes above.') -ForegroundColor Red
}
Write-Host 'Phone worker app: http://<laptop-ip>:5173 needs Vite server.host and that origin in CORS_ALLOWED_ORIGINS (see tools/README.md).'
