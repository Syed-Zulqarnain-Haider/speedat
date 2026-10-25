<#
.SYNOPSIS
    Syntax-check every PHP file in the project against both installed runtimes.

.DESCRIPTION
    Runs `php -l` with D:\php (8.2.34, the BUILD TARGET) and D:\php83 (8.3.35,
    the forward-compatibility check).

    Two runtimes because Hostinger's PHP selector is a dropdown the owner can
    change. Building on 8.2 means the code also runs if the host is set to 8.3
    or 8.4; passing on 8.3 alone would NOT guarantee it runs on 8.2, and a
    fatal parse error on the live site is not a thing we get to debug quietly.

    This is a syntax check, not a linter. It will not catch a wrong price.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File D:\speedat\php\bin\local\lint.ps1
#>

[CmdletBinding()]
param(
    [string[]] $PhpRoots = @('D:\php', 'D:\php83')
)

$ErrorActionPreference = 'Stop'

# bin/local/lint.ps1 -> bin/local -> bin -> php
$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)

$files = Get-ChildItem -Path $projectRoot -Recurse -Filter *.php -File |
    Where-Object { $_.FullName -notmatch '\\(storage|var)\\' } |
    Select-Object -ExpandProperty FullName

if (-not $files) {
    Write-Host 'No PHP files found.' -ForegroundColor Yellow
    exit 0
}

$failed = 0

foreach ($root in $PhpRoots) {
    $php = Join-Path $root 'php.exe'
    if (-not (Test-Path $php)) {
        Write-Host "SKIP $root (not installed)" -ForegroundColor Yellow
        continue
    }

    $version = & $php -r 'echo PHP_VERSION;'
    Write-Host "== PHP $version ($root) - $($files.Count) file(s)" -ForegroundColor Cyan

    foreach ($file in $files) {
        $output = & $php -l $file 2>&1
        if ($LASTEXITCODE -ne 0) {
            $failed++
            Write-Host "  FAIL $file" -ForegroundColor Red
            $output | ForEach-Object { Write-Host "       $_" -ForegroundColor Red }
        }
    }

    if ($failed -eq 0) { Write-Host '  all clean' -ForegroundColor Green }
}

if ($failed -gt 0) {
    Write-Host "`n$failed syntax error(s)." -ForegroundColor Red
    exit 1
}

Write-Host "`nLint passed on every runtime." -ForegroundColor Green
exit 0
