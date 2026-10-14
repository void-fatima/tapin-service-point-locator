param([string]$OutputDirectory)
$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
if (-not $OutputDirectory) { $OutputDirectory = Join-Path $repoRoot 'dist' }
$buildRoot = Join-Path $repoRoot ('artifacts/package-' + [Guid]::NewGuid().ToString('N'))
$pluginRoot = Join-Path $buildRoot 'tapin-service-point-locator'
New-Item -ItemType Directory -Path $pluginRoot -Force | Out-Null
New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
$OutputDirectory = (Get-Item -LiteralPath $OutputDirectory).FullName
foreach ($directory in @('src', 'assets', 'docs')) {
    Copy-Item -LiteralPath (Join-Path $repoRoot $directory) -Destination (Join-Path $pluginRoot $directory) -Recurse
}
foreach ($file in @('tapin-service-point-locator.php', 'uninstall.php', 'README.md', 'README.en.md', 'readme.txt', 'LICENSE')) {
    Copy-Item -LiteralPath (Join-Path $repoRoot $file) -Destination (Join-Path $pluginRoot $file)
}
$pluginHeader = Get-Content -LiteralPath (Join-Path $repoRoot 'tapin-service-point-locator.php') -Raw
$releaseVersion = [regex]::Match($pluginHeader, 'Version:\s+([0-9.]+)').Groups[1].Value
if (-not $releaseVersion) { throw 'Missing plugin release version.' }
$archivePath = Join-Path $OutputDirectory ('tapin-service-point-locator-' + $releaseVersion + '.zip')
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archiveStream = [System.IO.File]::Open($archivePath, [System.IO.FileMode]::Create)
try {
    $archive = [System.IO.Compression.ZipArchive]::new($archiveStream, [System.IO.Compression.ZipArchiveMode]::Create, $true)
    try {
        foreach ($packageFile in (Get-ChildItem -LiteralPath $pluginRoot -Recurse -File | Sort-Object FullName)) {
            $entryName = $packageFile.FullName.Substring($buildRoot.Length + 1).Replace('\', '/')
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $packageFile.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    } finally { $archive.Dispose() }
} finally { $archiveStream.Dispose() }
$zip = [System.IO.Compression.ZipFile]::OpenRead($archivePath)
try {
    $names = @($zip.Entries | ForEach-Object { $_.FullName })
    if ($names | Where-Object { $_.Contains('\') -or -not $_.StartsWith('tapin-service-point-locator/') -or $_ -match '(^|/)\.\.(/|$)' }) { throw 'Non-portable or invalid archive path.' }
    foreach ($required in @('tapin-service-point-locator.php', 'README.md', 'README.en.md', 'src/UI/App.php', 'assets/vendor/leaflet.js', 'assets/vendor/leaflet.css', 'assets/fonts/Vazirmatn.woff2', 'assets/brand/tapin.png', 'assets/brand/post.png', 'assets/brand/tipax.svg', 'assets/iran-provinces.geojson')) {
        if ($names -notcontains ('tapin-service-point-locator/' + $required)) { throw "Missing runtime asset: $required" }
    }
    if ($names | Where-Object { $_ -match '/(node_modules|tests|artifacts|\.git)/|wp-config\.php|\.env|session.*\.json' }) { throw 'Unexpected development or credential file in archive.' }
    Write-Output "Validated $($zip.Entries.Count) archive entries."
} finally { $zip.Dispose() }
Get-Item -LiteralPath $archivePath | Select-Object FullName, Length
Get-FileHash -LiteralPath $archivePath -Algorithm SHA256 | Select-Object Algorithm, Hash
