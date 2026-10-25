<#
.SYNOPSIS
    Stop the local MariaDB server cleanly.

.DESCRIPTION
    Sends a graceful shutdown through mariadb-admin, which lets InnoDB flush
    and close its files.

    Do NOT replace this with Stop-Process / taskkill. Hard-killing the server
    can corrupt the data directory, and recovering it is a bad afternoon.
    If shutdown genuinely hangs, investigate the .err log in D:\mariadb\data
    before reaching for anything forceful.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\stop-db.ps1
#>

[CmdletBinding()]
param(
    [string] $MariaRoot = 'D:\mariadb',
    [int]    $Port      = 3307,
    [string] $User      = 'root',
    [string] $Password  = '',
    [int]    $TimeoutSeconds = 60
)

$ErrorActionPreference = 'Stop'

$admin = Join-Path $MariaRoot 'bin\mariadb-admin.exe'
if (-not (Test-Path $admin)) {
    throw "Missing $admin - MariaDB is not installed."
}

# Plain TCP connect - see the long comment in start-db.ps1 for why this is not
# `mariadb-admin ping`.
function Test-DbPort {
    param([int] $P)
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $client.Connect('127.0.0.1', $P)
        return $true
    } catch {
        return $false
    } finally {
        $client.Dispose()
    }
}

if (-not (Test-DbPort -P $Port)) {
    Write-Host "Nothing listening on 127.0.0.1:$Port - MariaDB is already stopped." -ForegroundColor Green
    exit 0
}

Write-Host "Asking MariaDB on 127.0.0.1:$Port to shut down ..." -NoNewline

$adminArgs = @("--host=127.0.0.1", "--port=$Port", "--protocol=tcp", "--user=$User")
if ($Password -ne '') { $adminArgs += "--password=$Password" }
$adminArgs += 'shutdown'

& $admin @adminArgs

$deadline = (Get-Date).AddSeconds($TimeoutSeconds)
while ((Get-Date) -lt $deadline) {
    if (-not (Test-DbPort -P $Port)) {
        Write-Host ' stopped.' -ForegroundColor Green
        exit 0
    }
    Start-Sleep -Milliseconds 500
}

Write-Host ' still up.' -ForegroundColor Red
Write-Host 'Do NOT hard-kill it. Check D:\mariadb\data\*.err and wait for the flush to finish.' -ForegroundColor Yellow
exit 1
