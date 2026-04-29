param(
    [string]$BaseUrl = 'http://127.0.0.1:8091',
    [int]$Requests = 120,
    [int]$Concurrency = 20,
    [int]$TimeoutSeconds = 20,
    [string]$ReportJson = 'docs/perf-initial-report.json',
    [string]$ReportMd = 'docs/performance-report-inicial.md'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$backendPath = Join-Path $projectRoot 'backend'

if (-not (Test-Path $backendPath)) {
    throw "Diretorio backend nao encontrado: $backendPath"
}

function Assert-LastExitCode([string]$stepName) {
    if ($LASTEXITCODE -ne 0) {
        throw "Step failed: $stepName (exit code $LASTEXITCODE)"
    }
}

$serveProcess = $null
$originalAppSecret = $env:WHATSAPP_APP_SECRET
$env:WHATSAPP_APP_SECRET = 'perf-local-secret'

Push-Location $backendPath
try {
    Write-Host '==> Seed baseline de dados'
    php artisan db:seed --force
    Assert-LastExitCode 'php artisan db:seed --force'

    Write-Host '==> Subindo servidor Laravel para coleta'
    $stdoutLog = Join-Path $projectRoot 'storage_perf_server_stdout.log'
    $stderrLog = Join-Path $projectRoot 'storage_perf_server_stderr.log'

    $serveProcess = Start-Process -FilePath 'php' `
        -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', '--port=8091') `
        -WorkingDirectory $backendPath `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdoutLog `
        -RedirectStandardError $stderrLog `
        -PassThru

    $healthUrl = "$BaseUrl/up"
    $deadline = (Get-Date).AddSeconds(25)
    $ready = $false
    while ((Get-Date) -lt $deadline) {
        try {
            $response = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 3
            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 500) {
                $ready = $true
                break
            }
        } catch {
            Start-Sleep -Milliseconds 500
        }
    }

    if (-not $ready) {
        throw 'Servidor nao respondeu no prazo para iniciar a coleta de performance.'
    }

    Write-Host '==> Executando suite de performance'
    Pop-Location
    php scripts/performance-endpoints.php `
        --base-url=$BaseUrl `
        --requests=$Requests `
        --concurrency=$Concurrency `
        --timeout=$TimeoutSeconds `
        --out-json=$ReportJson
    Assert-LastExitCode 'php scripts/performance-endpoints.php'
    Push-Location $backendPath
} finally {
    if ($serveProcess -and -not $serveProcess.HasExited) {
        Stop-Process -Id $serveProcess.Id -Force
    }
    if ($null -eq $originalAppSecret) {
        Remove-Item Env:WHATSAPP_APP_SECRET -ErrorAction SilentlyContinue
    } else {
        $env:WHATSAPP_APP_SECRET = $originalAppSecret
    }
    Pop-Location
}

Write-Host '==> Gerando relatorio markdown inicial'
$reportPath = Join-Path $projectRoot $ReportJson
if (-not (Test-Path $reportPath)) {
    throw "Relatorio JSON nao encontrado: $reportPath"
}

$data = Get-Content $reportPath -Raw | ConvertFrom-Json

$lines = @()
$lines += '# Relatorio Inicial de Performance de Endpoints Criticos'
$lines += ''
$lines += "Gerado em: $($data.generated_at)"
$lines += ""
$lines += "Base URL: $($data.base_url)"
$lines += "Requests por endpoint: $($data.requests_per_endpoint)"
$lines += "Concorrencia: $($data.concurrency)"
$lines += ""
$lines += '## Resumo geral'
$lines += ''
$lines += "- Total de requests: $($data.suite.total_requests)"
$lines += "- Total de erros: $($data.suite.total_errors)"
$lines += "- Taxa de erro: $($data.suite.error_rate_pct)%"
$lines += "- Tempo medio (media dos tempos medios por endpoint): $($data.suite.average_latency_avg_ms) ms"
$lines += "- Duracao da suite: $($data.suite.duration_ms) ms"
$lines += ''
$lines += '## Resultados por endpoint'
$lines += ''
$lines += '| Endpoint | Concorrencia | Requests | Avg (ms) | p95 (ms) | Erros | Taxa de erro | Throughput (req/s) |'
$lines += '|---|---:|---:|---:|---:|---:|---:|---:|'

foreach ($endpoint in $data.endpoints) {
    $lines += "| $($endpoint.name) ($($endpoint.method) $($endpoint.path)) | $($endpoint.concurrency) | $($endpoint.requests) | $($endpoint.latency_ms.avg) | $($endpoint.latency_ms.p95) | $($endpoint.error_count) | $($endpoint.error_rate_pct)% | $($endpoint.throughput_rps) |"
}

$lines += ''
$lines += '## Status HTTP por endpoint'
$lines += ''

foreach ($endpoint in $data.endpoints) {
    $statusPairs = @()
    foreach ($prop in $endpoint.status_codes.PSObject.Properties) {
        $statusPairs += "$($prop.Name): $($prop.Value)"
    }
    $statusText = if ($statusPairs.Count -gt 0) { $statusPairs -join ', ' } else { 'sem dados' }
    $lines += "- $($endpoint.name): $statusText"
}

$mdPath = Join-Path $projectRoot $ReportMd
$mdDir = Split-Path -Parent $mdPath
if (-not (Test-Path $mdDir)) {
    New-Item -ItemType Directory -Path $mdDir | Out-Null
}

Set-Content -Path $mdPath -Value ($lines -join "`n")
Write-Host "Relatorio markdown gerado em: $ReportMd"
