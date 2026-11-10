# NAMAA: allow boards and phones to reach the laptop (run once, as administrator).
# Adds inbound allow rules for TCP 8000 (API) and 8080 (Reverb) on the Private profile.
# Safe to run again: existing rules are updated, not duplicated.
# Usage (admin PowerShell):  powershell -ExecutionPolicy Bypass -File tools\open-firewall.ps1

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Run this script as administrator (right-click PowerShell > Run as administrator).' -ForegroundColor Red
    exit 1
}

$rules = @(
    @{ Name = 'NAMAA backend'; Port = 8000 },
    @{ Name = 'NAMAA reverb'; Port = 8080 }
)

foreach ($r in $rules) {
    $existing = Get-NetFirewallRule -DisplayName $r.Name -ErrorAction SilentlyContinue
    if ($existing) {
        $existing | Set-NetFirewallRule -Direction Inbound -Action Allow -Profile Private -Enabled True
        $existing | Get-NetFirewallPortFilter | Set-NetFirewallPortFilter -Protocol TCP -LocalPort $r.Port
        Write-Host ('[OK] Updated rule "' + $r.Name + '" (TCP ' + $r.Port + ', Private).') -ForegroundColor Green
    } else {
        New-NetFirewallRule -DisplayName $r.Name -Direction Inbound -Action Allow -Protocol TCP -LocalPort $r.Port -Profile Private | Out-Null
        Write-Host ('[OK] Added rule "' + $r.Name + '" (TCP ' + $r.Port + ', Private).') -ForegroundColor Green
    }
}

Write-Host ''
Write-Host 'The rules apply to Private networks only. Mobile Hotspot and your home Wi-Fi should be Private.'
Write-Host 'To remove them (admin PowerShell):'
Write-Host '  Remove-NetFirewallRule -DisplayName "NAMAA backend"'
Write-Host '  Remove-NetFirewallRule -DisplayName "NAMAA reverb"'
