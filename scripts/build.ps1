param([string]$OutputDirectory)
$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
if (-not $OutputDirectory) { $OutputDirectory = Join-Path $repoRoot 'dist' }
$buildRoot = Join-Path $repoRoot ('artifacts/package-' + [Guid]::NewGuid().ToString('N'))
$pluginRoot = Join-Path $buildRoot 'tapin-service-point-locator'
New-Item -ItemType Directory -Path $pluginRoot -Force | Out-Null
New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
foreach ($directory in @('src', 'assets', 'docs')) {
    Copy-Item -LiteralPath (Join-Path $repoRoot $directory) -Destination (Join-Path $pluginRoot $directory) -Recurse
}
foreach ($file in @('tapin-service-point-locator.php', 'uninstall.php', 'README.md', 'readme.txt', 'LICENSE')) {
    Copy-Item -LiteralPath (Join-Path $repoRoot $file) -Destination (Join-Path $pluginRoot $file)
}
$archivePath = Join-Path $OutputDirectory 'tapin-service-point-locator-1.0.0.zip'
Compress-Archive -LiteralPath $pluginRoot -DestinationPath $archivePath -Force
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($archivePath)
try {
    $names = @($zip.Entries | ForEach-Object { $_.FullName.Replace('\', '/') })
    foreach ($required in @('tapin-service-point-locator.php', 'src/UI/App.php', 'assets/vendor/leaflet.js', 'assets/vendor/leaflet.css', 'assets/fonts/Vazirmatn.woff2', 'assets/brand/tapin.png', 'assets/brand/post.png', 'assets/brand/tipax.svg', 'assets/iran-provinces.geojson')) {
        if ($names -notcontains ('tapin-service-point-locator/' + $required)) { throw "Missing runtime asset: $required" }
    }
    if ($names | Where-Object { $_ -match '/(node_modules|tests|artifacts|\.git)/|wp-config\.php|\.env|session.*\.json' }) { throw 'Unexpected development or credential file in archive.' }
    Write-Output "Validated $($zip.Entries.Count) archive entries."
} finally { $zip.Dispose() }
Get-Item -LiteralPath $archivePath | Select-Object FullName, Length
Get-FileHash -LiteralPath $archivePath -Algorithm SHA256 | Select-Object Algorithm, Hash
