param(
	[string] $OutputDirectory = "artifacts"
)

$ErrorActionPreference = "Stop"

$pluginRoot = Split-Path -Parent $PSScriptRoot
$mainFile = Join-Path $pluginRoot "vemoro-socialfeed.php"
$distIgnoreFile = Join-Path $pluginRoot ".distignore"

$versionMatch = Select-String -LiteralPath $mainFile -Pattern '^\s*\*\s+Version:\s+([0-9]+\.[0-9]+\.[0-9]+)\s*$'
if (-not $versionMatch) {
	throw "The plugin version could not be read from vemoro-socialfeed.php."
}

$version = $versionMatch.Matches[0].Groups[1].Value
$outputRoot = if ([System.IO.Path]::IsPathRooted($OutputDirectory)) {
	[System.IO.Path]::GetFullPath($OutputDirectory)
} else {
	[System.IO.Path]::GetFullPath((Join-Path $pluginRoot $OutputDirectory))
}

New-Item -ItemType Directory -Path $outputRoot -Force | Out-Null
$archivePath = Join-Path $outputRoot "vemoro-socialfeed-$version.zip"
$checksumPath = "$archivePath.sha256"
$temporaryRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("vemoro-socialfeed-package-" + [guid]::NewGuid().ToString("N"))
$packageRoot = Join-Path $temporaryRoot "vemoro-socialfeed"

$ignorePatterns = Get-Content -LiteralPath $distIgnoreFile |
	ForEach-Object { $_.Trim() } |
	Where-Object { $_ -and -not $_.StartsWith("#") }

function Test-DistIgnored {
	param([string] $RelativePath)

	$normalized = $RelativePath.Replace("\", "/").TrimStart("/")
	foreach ($pattern in $ignorePatterns) {
		$normalizedPattern = $pattern.Replace("\", "/").TrimStart("/")
		if ($normalizedPattern -eq "*.zip") {
			if ($normalized -notmatch "/" -and $normalized.EndsWith(".zip", [StringComparison]::OrdinalIgnoreCase)) {
				return $true
			}
			continue
		}

		if ($normalized -eq $normalizedPattern -or $normalized.StartsWith("$normalizedPattern/", [StringComparison]::Ordinal)) {
			return $true
		}
	}

	return $false
}

try {
	New-Item -ItemType Directory -Path $packageRoot -Force | Out-Null
	$gitOutput = & git -C $pluginRoot ls-files --cached --others --exclude-standard -z
	if ($LASTEXITCODE -ne 0) {
		throw "git ls-files failed."
	}

	$files = ($gitOutput -join "`n") -split "`0"
	foreach ($relativePath in $files) {
		if (-not $relativePath -or (Test-DistIgnored $relativePath)) {
			continue
		}

		$sourcePath = Join-Path $pluginRoot $relativePath
		if (-not (Test-Path -LiteralPath $sourcePath -PathType Leaf)) {
			continue
		}

		$destinationPath = Join-Path $packageRoot $relativePath
		$destinationDirectory = Split-Path -Parent $destinationPath
		New-Item -ItemType Directory -Path $destinationDirectory -Force | Out-Null
		Copy-Item -LiteralPath $sourcePath -Destination $destinationPath
	}

	if (Test-Path -LiteralPath $archivePath) {
		Remove-Item -LiteralPath $archivePath
	}

	Add-Type -AssemblyName System.IO.Compression
	Add-Type -AssemblyName System.IO.Compression.FileSystem
	$archive = [System.IO.Compression.ZipFile]::Open($archivePath, [System.IO.Compression.ZipArchiveMode]::Create)
	try {
		Get-ChildItem -LiteralPath $packageRoot -File -Recurse |
			Sort-Object { $_.FullName.Substring($temporaryRoot.Length + 1).Replace("\", "/") } |
			ForEach-Object {
			$relativePath = $_.FullName.Substring($temporaryRoot.Length + 1).Replace("\", "/")
			$entry = $archive.CreateEntry($relativePath, [System.IO.Compression.CompressionLevel]::Optimal)
			$entry.LastWriteTime = [DateTimeOffset]::new(1980, 1, 1, 0, 0, 0, [TimeSpan]::Zero)
			$entryStream = $entry.Open()
			$fileStream = [System.IO.File]::OpenRead($_.FullName)
			try {
				$fileStream.CopyTo($entryStream)
			} finally {
				$fileStream.Dispose()
				$entryStream.Dispose()
			}
		}
	} finally {
		$archive.Dispose()
	}

	$checksum = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
	Set-Content -LiteralPath $checksumPath -Value "$checksum  $(Split-Path -Leaf $archivePath)" -Encoding ascii

	Write-Output "Package: $archivePath"
	Write-Output "SHA-256: $checksum"
} finally {
	$resolvedTemporaryRoot = [System.IO.Path]::GetFullPath($temporaryRoot)
	$resolvedSystemTemp = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
	if ($resolvedTemporaryRoot.StartsWith($resolvedSystemTemp, [StringComparison]::OrdinalIgnoreCase) -and (Test-Path -LiteralPath $resolvedTemporaryRoot)) {
		Remove-Item -LiteralPath $resolvedTemporaryRoot -Recurse -Force
	}
}
