Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$backendPath = Join-Path $projectRoot 'backend'

if (-not (Test-Path $backendPath)) {
    throw "Diretorio backend nao encontrado em: $backendPath"
}

function Assert-LastExitCode([string]$stepName) {
    if ($LASTEXITCODE -ne 0) {
        throw "Step failed: $stepName (exit code $LASTEXITCODE)"
    }
}

Push-Location $backendPath
try {
    Write-Host '==> Executando migrations (forward-only)'
    php artisan migrate --force
    Assert-LastExitCode 'php artisan migrate --force'

    Write-Host '==> Executando smoke test de banco pos-migrate'
    php artisan db:smoke
    Assert-LastExitCode 'php artisan db:smoke'

    Write-Host 'Migrate safe finalizado com sucesso.'
} finally {
    Pop-Location
}
