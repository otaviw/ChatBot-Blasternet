Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$backendPath = Join-Path $projectRoot 'backend'

if (-not (Test-Path $backendPath)) {
    throw "Diretorio backend nao encontrado em: $backendPath"
}

$pgDump = Get-Command pg_dump -ErrorAction SilentlyContinue
if (-not $pgDump) {
    throw @"
pg_dump nao encontrado no PATH.
Instale o cliente PostgreSQL e execute novamente:
  cd backend
  php artisan schema:dump
"@
}

Push-Location $backendPath
try {
    php artisan schema:dump
    Write-Host 'Schema dump atualizado com sucesso.'
} finally {
    Pop-Location
}
