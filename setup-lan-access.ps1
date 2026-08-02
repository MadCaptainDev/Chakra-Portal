<#
    Chakra Productions Portal - LAN access setup
    ============================================

    RUN THIS AS ADMINISTRATOR (right-click -> "Run with PowerShell" won't be
    elevated; use: Start Menu -> type "PowerShell" -> right-click -> Run as
    administrator, then execute this file).

    It performs the four steps that require elevation:

      1. Marks this network "Private" so Windows will accept inbound
         connections at all. It is currently "Public", where inbound traffic
         is blocked by default - this alone would stop the phone connecting.
      2. Opens inbound TCP 80, scoped to Private profiles only.
      3. Installs Apache + MySQL as auto-start Windows services, so the
         portal comes back by itself after a reboot.
      4. Registers a scheduled task running `artisan schedule:run` every
         minute (recurring invoices + Notion sync).

    Safe to re-run. To undo, see UNDO notes at the bottom.
#>

$ErrorActionPreference = 'Stop'

$ProjectPath = 'D:\Claude Work Folder\Invoice System'
$XamppPath   = 'C:\xampp'
$Port        = 80
$TaskName    = 'Chakra Portal Scheduler'

function Step($n, $msg) { Write-Host "`n[$n] $msg" -ForegroundColor Cyan }
function Ok($msg)        { Write-Host "    OK  $msg" -ForegroundColor Green }
function Warn($msg)      { Write-Host "    !!  $msg" -ForegroundColor Yellow }

# --- guard: must be elevated -------------------------------------------------
$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "This script must be run as Administrator. Nothing was changed." -ForegroundColor Red
    exit 1
}

# --- 1. network profile ------------------------------------------------------
Step 1 'Setting network profile to Private'
Get-NetConnectionProfile | ForEach-Object {
    if ($_.NetworkCategory -eq 'Public') {
        Set-NetConnectionProfile -InterfaceIndex $_.InterfaceIndex -NetworkCategory Private
        Ok "'$($_.Name)' changed Public -> Private"
    } else {
        Ok "'$($_.Name)' already $($_.NetworkCategory)"
    }
}

# --- 2. firewall -------------------------------------------------------------
Step 2 "Opening inbound TCP $Port (Private profile only)"
$ruleName = "Chakra Portal (HTTP $Port)"
Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue | Remove-NetFirewallRule
New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Protocol TCP `
    -LocalPort $Port -Action Allow -Profile Private | Out-Null
Ok "firewall rule '$ruleName' created"

# --- 3. services -------------------------------------------------------------
Step 3 'Installing Apache + MySQL as auto-start services'

# Console-mode instances hold the ports; stop them or the services can't bind.
Get-Process httpd, mysqld -ErrorAction SilentlyContinue | ForEach-Object {
    $_ | Stop-Process -Force
    Ok "stopped running $($_.ProcessName) (pid $($_.Id))"
}
Start-Sleep -Seconds 3

if (-not (Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue)) {
    & "$XamppPath\apache\bin\httpd.exe" -k install -n 'Apache2.4' | Out-Null
    Ok 'Apache service installed'
} else { Ok 'Apache service already present' }

if (-not (Get-Service -Name 'mysql' -ErrorAction SilentlyContinue)) {
    & "$XamppPath\mysql\bin\mysqld.exe" --install mysql --defaults-file="$XamppPath\mysql\bin\my.ini" | Out-Null
    Ok 'MySQL service installed'
} else { Ok 'MySQL service already present' }

foreach ($svc in 'mysql', 'Apache2.4') {
    Set-Service -Name $svc -StartupType Automatic
    if ((Get-Service $svc).Status -ne 'Running') { Start-Service $svc }
    Ok "$svc -> $((Get-Service $svc).Status), StartupType Automatic"
}

# --- 4. scheduler ------------------------------------------------------------
Step 4 "Registering '$TaskName' (every minute)"
$action = New-ScheduledTaskAction -Execute "$XamppPath\php\php.exe" `
    -Argument 'artisan schedule:run' -WorkingDirectory $ProjectPath
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date)
$trigger.RepetitionInterval = (New-TimeSpan -Minutes 1)
$trigger.RepetitionDuration = (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
    -Settings $settings -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
Ok 'scheduled task registered (runs as SYSTEM, no password needed)'

# --- verification ------------------------------------------------------------
Step 'v' 'Verifying'
Start-Sleep -Seconds 4
try {
    $r = Invoke-WebRequest -Uri "http://192.168.1.10/" -MaximumRedirection 0 `
        -UseBasicParsing -ErrorAction SilentlyContinue
    $code = $r.StatusCode
} catch { $code = $_.Exception.Response.StatusCode.value__ }

if ($code -eq 302) { Ok 'http://192.168.1.10/ responds 302 -> /login (correct)' }
else { Warn "http://192.168.1.10/ returned '$code' - expected 302. Check Apache is running." }

Write-Host "`n============================================================" -ForegroundColor Cyan
Write-Host " Open this on your phone (same WiFi):  http://192.168.1.10" -ForegroundColor White
Write-Host "============================================================`n" -ForegroundColor Cyan

Write-Host "One manual step left - pin the IP so the address never changes:" -ForegroundColor Yellow
Write-Host "  Router admin (usually http://192.168.1.1) -> DHCP -> Address Reservation"
Write-Host "  Reserve 192.168.1.10 for MAC FC-9D-05-3E-05-FE"
Write-Host "  (Without this, a reboot can hand this PC a different IP.)`n"

<#
    UNDO
    ----
    Remove-NetFirewallRule -DisplayName "Chakra Portal (HTTP 80)"
    Unregister-ScheduledTask -TaskName "Chakra Portal Scheduler" -Confirm:$false
    Stop-Service Apache2.4, mysql
    C:\xampp\apache\bin\httpd.exe -k uninstall -n "Apache2.4"
    C:\xampp\mysql\bin\mysqld.exe --remove mysql
#>
