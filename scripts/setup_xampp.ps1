param(
    [string]$XamppRoot,
    [string]$AppName = "Arbeitszeit",
    [string]$MySqlUser = "root",
    [string]$MySqlPassword = "",
    [switch]$SkipDatabase,
    [switch]$SkipData
)

$ErrorActionPreference = "Stop"

$ProjectRoot = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "..")

function Resolve-XamppRoot {
    param([string]$RequestedRoot)

    if ($RequestedRoot) {
        return (Resolve-Path -LiteralPath $RequestedRoot).Path
    }

    $candidates = @(
        "D:\xampp",
        "C:\xampp",
        "E:\xampp",
        "D:\xampp_server"
    )

    foreach ($candidate in $candidates) {
        if (
            (Test-Path -LiteralPath (Join-Path $candidate "htdocs")) -and
            (Test-Path -LiteralPath (Join-Path $candidate "mysql\bin\mysql.exe"))
        ) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }

    throw "Could not find a XAMPP installation with htdocs and mysql\bin\mysql.exe. Pass -XamppRoot explicitly."
}

function Convert-ToMysqlSourcePath {
    param([string]$Path)
    return ((Resolve-Path -LiteralPath $Path).Path -replace "\\", "/")
}

function Invoke-MysqlSource {
    param(
        [string]$MysqlExe,
        [string]$SourcePath,
        [string]$User,
        [string]$Password
    )

    $arguments = @("-u$User", "--default-character-set=utf8mb4")
    if ($Password) {
        $arguments += "-p$Password"
    }
    $arguments += "--execute=SOURCE $(Convert-ToMysqlSourcePath -Path $SourcePath)"

    & $MysqlExe @arguments
}

function Invoke-WorkbookImporter {
    foreach ($command in @("py", "python")) {
        $found = Get-Command $command -ErrorAction SilentlyContinue
        if ($found -and $found.Source -notlike "*\Microsoft\WindowsApps\python.exe") {
            & $found.Source (Join-Path $PSScriptRoot "private import script")
            return
        }
    }

    $localPythonRoots = @(
        (Join-Path $env:LOCALAPPDATA "Programs\Python\Python313\python.exe"),
        (Join-Path $env:LOCALAPPDATA "Programs\Python\Python312\python.exe"),
        (Join-Path $env:LOCALAPPDATA "Programs\Python\Python311\python.exe")
    )

    foreach ($pythonExe in $localPythonRoots) {
        if (Test-Path -LiteralPath $pythonExe) {
            & $pythonExe (Join-Path $PSScriptRoot "private import script")
            return
        }
    }

    throw "Could not find Python. Install Python and run scripts\setup_xampp.ps1 again."
}

$ResolvedXamppRoot = Resolve-XamppRoot -RequestedRoot $XamppRoot
$TargetPath = Join-Path (Join-Path $ResolvedXamppRoot "htdocs") $AppName
$MysqlExe = Join-Path $ResolvedXamppRoot "mysql\bin\mysql.exe"

if (-not $SkipDatabase -and -not $SkipData) {
    Invoke-WorkbookImporter
}

& (Join-Path $PSScriptRoot "sync_xampp.ps1") -XamppRoot $ResolvedXamppRoot -TargetPath $TargetPath

if (-not $SkipDatabase) {
    Invoke-MysqlSource -MysqlExe $MysqlExe -SourcePath (Join-Path $ProjectRoot "database\schema.sql") -User $MySqlUser -Password $MySqlPassword
    Write-Host "Imported schema into XAMPP MySQL."

    if (-not $SkipData) {
        Invoke-MysqlSource -MysqlExe $MysqlExe -SourcePath (Join-Path $ProjectRoot "database\private import SQL") -User $MySqlUser -Password $MySqlPassword
        Write-Host "Imported workbook data from private workbook export."

        Invoke-MysqlSource -MysqlExe $MysqlExe -SourcePath (Join-Path $ProjectRoot "database\003_backfill_weekly_tasks.sql") -User $MySqlUser -Password $MySqlPassword
        Write-Host "Backfilled weekly tasks from imported work entries."
    }
}

Write-Host "Open http://localhost/$AppName/"
