param(
    [ValidateSet('setup', 'serve', 'worker', 'doctor', 'proxy', 'tunnel')]
    [string]$Mode = 'setup'
)

# Uses the backend .env database connection and WhatsApp credentials.
$ErrorActionPreference = 'Stop'
$apiDirectory = Split-Path -Parent $PSScriptRoot
$previousIniScanDirectory = [Environment]::GetEnvironmentVariable('PHP_INI_SCAN_DIR', 'Process')

function Invoke-TestArtisan {
    param([string[]]$ArtisanArguments)
    & php artisan @ArtisanArguments
    if ($LASTEXITCODE -ne 0) {
        throw "Artisan command failed (exit $LASTEXITCODE)."
    }
}

Push-Location $apiDirectory
try {
    # PHP on this Windows PC needs an explicit CA bundle for HTTPS requests.
    # Export public certificates already trusted by Windows; keep TLS verification on.
    $runtimeDirectory = Join-Path $apiDirectory 'storage/app/whatsapp-runtime'
    New-Item -ItemType Directory -Force -Path $runtimeDirectory | Out-Null
    $certificatePath = Join-Path $runtimeDirectory 'windows-roots.pem'
    $certificates = @(Get-ChildItem Cert:/CurrentUser/Root, Cert:/LocalMachine/Root |
        Sort-Object Thumbprint -Unique)
    if ($certificates.Count -eq 0) { throw 'No trusted Windows root certificates were found.' }
    $pem = foreach ($certificate in $certificates) {
        '-----BEGIN CERTIFICATE-----'
        [Convert]::ToBase64String($certificate.RawData, [Base64FormattingOptions]::InsertLineBreaks)
        '-----END CERTIFICATE-----'
    }
    Set-Content -LiteralPath $certificatePath -Value $pem -Encoding ascii
    $iniCertificatePath = $certificatePath.Replace('\', '/')
    $iniSettings = @(
        ('curl.cainfo="' + $iniCertificatePath + '"')
        ('openssl.cafile="' + $iniCertificatePath + '"')
    )
    $extensions = @(& php -m)
    if ($LASTEXITCODE -ne 0) { throw 'Unable to inspect PHP extensions.' }
    if ($extensions -notcontains 'curl') { $iniSettings += 'extension=curl' }
    Set-Content -LiteralPath (Join-Path $runtimeDirectory 'whatsapp.ini') -Value $iniSettings -Encoding ascii
    # A blank first entry preserves PHP's default scan directory.
    [Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR', ($previousIniScanDirectory + ';' + $runtimeDirectory), 'Process')

    switch ($Mode) {
        'setup' {
            Invoke-TestArtisan -ArtisanArguments @('migrate', '--force')
            Invoke-TestArtisan -ArtisanArguments @('db:seed', '--class=SupportKnowledgeSeeder', '--force')
        }
        'serve' {
            Invoke-TestArtisan -ArtisanArguments @('serve', '--host=127.0.0.1', '--port=8000')
        }
        'worker' {
            Invoke-TestArtisan -ArtisanArguments @('queue:work', 'database', '--queue=support', '--sleep=1', '--tries=3', '--timeout=90')
        }
        'doctor' {
            Invoke-TestArtisan -ArtisanArguments @('support:doctor')
        }
        'proxy' {
            & php -S 127.0.0.1:8002 scripts/support-webhook-proxy.php
            if ($LASTEXITCODE -ne 0) { throw 'WhatsApp webhook proxy stopped with an error.' }
        }
        'tunnel' {
            $tunnelExecutable = Join-Path $runtimeDirectory 'cloudflared.exe'
            if (-not (Test-Path -LiteralPath $tunnelExecutable)) {
                throw 'Download the official Windows cloudflared executable to storage/app/whatsapp-runtime/cloudflared.exe first.'
            }
            & $tunnelExecutable tunnel --url http://127.0.0.1:8002 --protocol http2 --no-autoupdate
            if ($LASTEXITCODE -ne 0) { throw 'WhatsApp tunnel stopped with an error.' }
        }
    }
} finally {
    [Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR', $previousIniScanDirectory, 'Process')
    Pop-Location
}
