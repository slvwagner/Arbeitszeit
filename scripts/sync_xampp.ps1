param(
    [string]$TargetPath = "E:\Software\xampp\htdocs\Arbeitszeit"
)

$ErrorActionPreference = "Stop"

$ProjectRoot = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "..")
$TargetRoot = $TargetPath

if (-not (Test-Path -LiteralPath $TargetRoot)) {
    New-Item -ItemType Directory -Force -Path $TargetRoot | Out-Null
}

$directories = @(
    "assets",
    "config",
    "database",
    "src"
)

$rootFiles = @(
    "index.php",
    "entry.php",
    "monthly.php",
    "report.php",
    "README.md"
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

Write-Host "Synced Arbeitszeit source to $TargetRoot"
