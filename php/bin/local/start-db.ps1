<#
.SYNOPSIS
    Start the local MariaDB server for the Speedat PHP port.

.DESCRIPTION
    Starts D:\mariadb (MariaDB 11.4 LTS, portable zip install) on port 3307,
    detached and hidden, reading D:\mariadb\my.ini.

    Port 3307 is deliberate: 3306 is left free for anything the owner installs
    later, and 55432 belongs to the Postgres test cluster at D:\pgtest, which
    this script must never touch.

    The server is NEVER hard-killed - see stop-db.ps1. A MariaDB/InnoDB data
    directory that is killed mid-write can need recovery, and this machine has
    lost a database once already.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\start-db.ps1
#>

[CmdletBinding()]
param(
    [string] $MariaRoot = 'D:\mariadb',
    [int]    $Port      = 3307,
    [int]    $TimeoutSeconds = 60
)

$ErrorActionPreference = 'Stop'

$mariadbd = Join-Path $MariaRoot 'bin\mariadbd.exe'
$defaults = Join-Path $MariaRoot 'my.ini'
$dataDir  = Join-Path $MariaRoot 'data'

foreach ($required in @($mariadbd, $defaults)) {
    if (-not (Test-Path $required)) {
        throw "Missing $required - MariaDB is not installed. See bin/local/README-local.md."
    }
}
if (-not (Test-Path (Join-Path $dataDir 'mysql'))) {
    throw "Data directory $dataDir is not initialised. See bin/local/README-local.md."
}

# Readiness is a plain TCP connect, deliberately.
#
# `mariadb-admin ping` looks like the better check and is not: it writes its
# "insecure passwordless login" notice to stderr, and PowerShell turns native
# stderr into a terminating NativeCommandError, so the whole script dies on a
# warning. It also still leaves "Bad handshake" lines in the error log, so it
# does not even buy quieter logs. Tried it; reverted it.
#
# The cost of this probe is a pair of harmless
#   Aborted connection N ... user: 'unauthenticated' ... host: '127.0.0.1'
# warnings in D:\mariadb\data\mariadb-error.err per attempt. They are this
# script knocking on the door, not a problem. Ignore them when reading the log.
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

if (Test-DbPort -P $Port) {
    Write-Host "MariaDB is already listening on 127.0.0.1:$Port - nothing to do." -ForegroundColor Green
    exit 0
}

Write-Host "Starting MariaDB on 127.0.0.1:$Port ..." -NoNewline

Start-Process -FilePath $mariadbd `
              -ArgumentList "--defaults-file=`"$defaults`"" `
              -WindowStyle Hidden

$deadline = (Get-Date).AddSeconds($TimeoutSeconds)
while ((Get-Date) -lt $deadline) {
    if (Test-DbPort -P $Port) {
        Write-Host ' up.' -ForegroundColor Green
        Write-Host "  socket : 127.0.0.1:$Port"
        Write-Host "  data   : $dataDir"
        Write-Host "  client : $MariaRoot\bin\mariadb.exe --port=$Port --user=speedat --password=speedat speedat_php"
        exit 0
    }
    Start-Sleep -Milliseconds 500
}

Write-Host ' FAILED.' -ForegroundColor Red
$errLog = Get-ChildItem -Path $dataDir -Filter '*.err' -ErrorAction SilentlyContinue | Select-Object -First 1
if ($errLog) {
    Write-Host "--- last 30 lines of $($errLog.FullName) ---" -ForegroundColor Yellow
    Get-Content $errLog.FullName -Tail 30
}
throw "MariaDB did not come up on port $Port within $TimeoutSeconds seconds."
