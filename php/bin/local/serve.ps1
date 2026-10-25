<#
.SYNOPSIS
    Run the Speedat PHP app on the PHP built-in web server.

.DESCRIPTION
    Serves php/public/ on 127.0.0.1:8081 through public/router.php, which
    mimics the Apache .htaccess front-controller rewrite used on Hostinger:
    real files are served as-is, everything else goes to index.php.

    Port 8081, not 8080: 8080 is taken by the Kafbat UI service on this
    machine.

    This uses D:\php (PHP 8.2.34), which is the BUILD TARGET. D:\php83
    (PHP 8.3.35) exists only as a forward-compatibility check - pass
    -PhpRoot D:\php83 to run the same code on it.

    The built-in server is single-threaded and is a development tool only.
    It is not Apache: .htaccess is ignored here, so anything that .htaccess
    must enforce on the server has to be verified on the server.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\serve.ps1
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\serve.ps1 -PhpRoot D:\php83 -Port 8082
#>

[CmdletBinding()]
param(
    [string] $PhpRoot = 'D:\php',
    [string] $BindAddress = '127.0.0.1',
    [int]    $Port = 8081
)

$ErrorActionPreference = 'Stop'

$php = Join-Path $PhpRoot 'php.exe'
if (-not (Test-Path $php)) {
    throw "Missing $php - PHP is not installed at $PhpRoot. See bin/local/README-local.md."
}

# bin/local/serve.ps1 -> bin/local -> bin -> php
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$docRoot     = Join-Path $projectRoot 'public'
$router      = Join-Path $docRoot 'router.php'

foreach ($required in @($docRoot, $router)) {
    if (-not (Test-Path $required)) { throw "Missing $required" }
}

$client = New-Object System.Net.Sockets.TcpClient
try {
    $client.Connect($BindAddress, $Port)
    $client.Dispose()
    throw "Port $Port is already in use on $BindAddress. Pass -Port to pick another."
} catch [System.Net.Sockets.SocketException] {
    $client.Dispose()
}

Write-Host "PHP     : $(& $php -r 'echo PHP_VERSION;')"
Write-Host "php.ini : $(& $php -r 'echo php_ini_loaded_file();')"
Write-Host "docroot : $docRoot"
Write-Host "router  : $router"
Write-Host ""
Write-Host "Serving http://${BindAddress}:${Port}/  (Ctrl+C to stop)" -ForegroundColor Green
Write-Host ""

& $php -S "${BindAddress}:${Port}" -t $docRoot $router
