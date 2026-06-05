param(
    [string]$XamppRoot,
    [string]$TargetPath
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

$ResolvedXamppRoot = Resolve-XamppRoot -RequestedRoot $XamppRoot
if (-not $TargetPath) {
    $TargetPath = Join-Path $ResolvedXamppRoot "htdocs\Arbeitszeit"
}

$TargetRoot = $TargetPath

if (-not (Test-Path -LiteralPath $TargetRoot)) {
    New-Item -ItemType Directory -Force -Path $TargetRoot | Out-Null
}

$directories = @(
    "assets",
    "config",
    "database",
    "scripts",
    "src"
)

$rootFiles = @(
    "index.php",
    "projekte.php",
    "monat_bearbeiten.php",
    "monthly.php",
    "report.php",
    "private workbook export",
    "README.md"
)

$obsoleteRootFiles = @(
    "entry.php"
)

foreach ($directory in $directories) {
    $source = Join-Path $ProjectRoot $directory
    $destination = Join-Path $TargetRoot $directory

    if (Test-Path -LiteralPath $source) {
        New-Item -ItemType Directory -Force -Path $destination | Out-Null
        Get-ChildItem -LiteralPath $source -Force | ForEach-Object {
            Copy-Item -LiteralPath $_.FullName -Destination $destination -Recurse -Force
        }
    }
}

foreach ($file in $rootFiles) {
    $source = Join-Path $ProjectRoot $file
    if (Test-Path -LiteralPath $source) {
        Copy-Item -LiteralPath $source -Destination (Join-Path $TargetRoot $file) -Force
    }
}

foreach ($file in $obsoleteRootFiles) {
    $obsoleteTarget = Join-Path $TargetRoot $file
    if (Test-Path -LiteralPath $obsoleteTarget) {
        Remove-Item -LiteralPath $obsoleteTarget -Force
    }
}

Write-Host "Synced Arbeitszeit source to $TargetRoot"
Write-Host "XAMPP root: $ResolvedXamppRoot"
