param(
    [string]$Output = (Join-Path $PSScriptRoot '..\teawkanna-deploy.zip')
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$staging = Join-Path ([System.IO.Path]::GetTempPath()) ('teawkanna-deploy-' + [guid]::NewGuid())

$excludedDirectories = @(
    '.git',
    'scripts',
    'assets\uploads',
    'handlers\uploads'
)

$excludedFiles = @(
    '.env',
    '.env.example',
    '.gitignore',
    'DEPLOY.md',
    'PATCH_README.txt',
    'component_diagrams.pptx',
    'service_account.json',
    'service_account.example.json',
    'teawkanna (10).sql',
    'test.php',
    'test_curl.php',
    'tkn.code-workspace',
    'debug.log',
    'line_debug.txt',
    'webhook_debug.txt',
    'handlers\debug.log',
    'admin\test_omise.php'
)

try {
    New-Item -ItemType Directory -Path $staging | Out-Null

    Get-ChildItem -LiteralPath $root -Recurse -File -Force | ForEach-Object {
        $relative = $_.FullName.Substring($root.Length + 1)
        $skipDirectory = $false
        foreach ($directory in $excludedDirectories) {
            if ($relative -eq $directory -or $relative.StartsWith($directory + '\')) {
                $skipDirectory = $true
                break
            }
        }

        if ($skipDirectory -or $excludedFiles -contains $relative -or
            $_.Extension -in @('.sql', '.log') -or $_.Name -like '*_debug.txt') {
            return
        }

        $destination = Join-Path $staging $relative
        New-Item -ItemType Directory -Path (Split-Path $destination) -Force | Out-Null
        Copy-Item -LiteralPath $_.FullName -Destination $destination
    }

    foreach ($uploadPath in @(
        'assets\uploads',
        'handlers\uploads',
        'handlers\uploads\avatars',
        'handlers\uploads\shop_pics',
        'handlers\uploads\activity_pics',
        'handlers\uploads\slips'
    )) {
        New-Item -ItemType Directory -Path (Join-Path $staging $uploadPath) -Force | Out-Null
    }

    Copy-Item -LiteralPath (Join-Path $root 'assets\uploads\.htaccess') `
        -Destination (Join-Path $staging 'assets\uploads\.htaccess')
    Copy-Item -LiteralPath (Join-Path $root 'handlers\uploads\.htaccess') `
        -Destination (Join-Path $staging 'handlers\uploads\.htaccess')

    $outputPath = if ([System.IO.Path]::IsPathRooted($Output)) {
        [System.IO.Path]::GetFullPath($Output)
    } else {
        [System.IO.Path]::GetFullPath((Join-Path $root $Output))
    }
    if (Test-Path -LiteralPath $outputPath) {
        Remove-Item -LiteralPath $outputPath
    }
    Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $outputPath
    Write-Output "Created: $outputPath"
}
finally {
    if (Test-Path -LiteralPath $staging) {
        Remove-Item -LiteralPath $staging -Recurse -Force
    }
}
