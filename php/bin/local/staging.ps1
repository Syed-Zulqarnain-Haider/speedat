<#
.SYNOPSIS
    Push the working tree to the Speedat staging container and prove it answers.

.DESCRIPTION
    The one command. It packs whatever is in D:\speedat\php right now - the same
    files the release ships, in the same layout Hostinger will have - streams it
    to the staging box over ssh, writes that box's config/config.php from its
    own .env, runs the migrations, reloads Apache and curls the site. It prints
    one summary line with files, bytes, seconds and the HTTP status.

    Safe to run twenty times an hour. Nothing outside ~/speedat-staging on the
    server is touched: that machine also runs the owner's Prometheus dashboard
    and a Kubernetes node.

    Everything of substance lives in php\bin\deploy-staging.php; this wrapper
    only finds PHP, finds the tree and forwards the exit code, so that the
    command the owner types has no paths in it.

    The staging site is at http://127.0.0.1:8085/ once the MobaXterm tunnel (or
    ssh -L 8085:127.0.0.1:8085 admin_tf@110.93.205.230) is up. See
    php\docs\staging.md.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\staging.ps1
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\staging.ps1 -DryRun -List
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\staging.ps1 -NoMigrate -DebugConfig
#>

[CmdletBinding()]
param(
    # PHP 8.2.34 is the build target. D:\php83 (8.3.35) exists only as a
    # forward-compatibility check and works here too.
    [string] $PhpRoot = 'D:\php',

    # The staging box. The ssh key is already in place for this account.
    [string] $Server = 'admin_tf@110.93.205.230',

    # app.url written into the generated staging config, and what gets curled.
    [string] $Url = 'http://127.0.0.1:8085',

    # Pack and report; do not contact the server at all.
    [switch] $DryRun,

    # Print every packed entry and every refusal.
    [switch] $List,

    # Skip bin/migrate.php on the server.
    [switch] $NoMigrate,

    # Generated staging config gets app.debug = true. Off by default: the
    # container is published on 0.0.0.0:8085, so the detail belongs in
    # storage/logs, not in a visitor's browser.
    [switch] $DebugConfig,

    # Keep the tarball in php\var instead of deleting it.
    [switch] $Keep,

    # Seconds allowed per ssh step.
    [int] $TimeoutSeconds = 180
)

$ErrorActionPreference = 'Stop'

# php/ is two levels up from bin/local/. Resolved from this script's own
# location so the command works from any working directory.
$phpTree = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$deploy  = Join-Path $phpTree 'bin\deploy-staging.php'

if (-not (Test-Path -LiteralPath $deploy)) {
    Write-Error "Cannot find $deploy - is this script still inside php\bin\local\?"
    exit 1
}

# Prefer the pinned interpreter; fall back to whatever php is on PATH so the
# script still works on a machine that keeps PHP somewhere else.
$php = Join-Path $PhpRoot 'php.exe'
if (-not (Test-Path -LiteralPath $php)) {
    $onPath = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $onPath) {
        Write-Error "No PHP at $php and none on PATH. Pass -PhpRoot."
        exit 1
    }
    $php = $onPath.Source
    Write-Host "PHP at $PhpRoot not found; using $php" -ForegroundColor DarkYellow
}

$arguments = @(
    $deploy,
    "--host=$Server",
    "--url=$Url",
    "--timeout=$TimeoutSeconds"
)
if ($DryRun)      { $arguments += '--dry-run' }
if ($List)        { $arguments += '--list' }
if ($NoMigrate)   { $arguments += '--no-migrate' }
if ($DebugConfig) { $arguments += '--debug-config' }
if ($Keep)        { $arguments += '--keep' }

# Run from the tree so relative paths inside the script resolve the same way
# they do when it is invoked by hand.
$code = 1
Push-Location $phpTree
try {
    & $php @arguments
    $code = $LASTEXITCODE
}
finally {
    Pop-Location
}

if ($code -ne 0) {
    Write-Host ''
    Write-Host "Staging deploy failed (exit $code). Logs:" -ForegroundColor Red
    Write-Host "  ssh $Server 'docker logs --tail 50 speedat-staging-web'"
    Write-Host "  ssh $Server 'tail -50 ~/speedat-staging/storage/logs/php-error.log'"
    Write-Host '  php\docs\staging.md has the rest.'
}

exit $code
