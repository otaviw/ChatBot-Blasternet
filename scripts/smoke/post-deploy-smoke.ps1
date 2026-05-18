$ErrorActionPreference = "Stop"

$FrontendUrl = if ($env:SMOKE_FRONTEND_URL) { $env:SMOKE_FRONTEND_URL } else { "http://localhost" }
$BackendApiUrl = if ($env:SMOKE_BACKEND_API_URL) { $env:SMOKE_BACKEND_API_URL } else { "http://localhost/api" }
$BackendWebUrl = if ($env:SMOKE_BACKEND_WEB_URL) { $env:SMOKE_BACKEND_WEB_URL } else { "http://localhost" }
$RealtimeUrl = if ($env:SMOKE_REALTIME_URL) { $env:SMOKE_REALTIME_URL } else { "http://localhost:8081" }

$LoginEmail = $env:SMOKE_LOGIN_EMAIL
$LoginPassword = $env:SMOKE_LOGIN_PASSWORD

function Pass([string]$Message) { Write-Host "[PASS] $Message" -ForegroundColor Green }
function Warn([string]$Message) { Write-Host "[WARN] $Message" -ForegroundColor Yellow }
function Fail([string]$Message) { throw "[FAIL] $Message" }

function Get-StatusCode([string]$Url, [string]$Method = "GET", $WebSession = $null, [string]$ContentType = "application/json", $Body = $null) {
    try {
        $params = @{
            Uri = $Url
            Method = $Method
            UseBasicParsing = $true
            ContentType = $ContentType
            MaximumRedirection = 0
            ErrorAction = "Stop"
        }
        if ($WebSession) { $params.WebSession = $WebSession }
        if ($null -ne $Body) { $params.Body = $Body }
        $response = Invoke-WebRequest @params
        return [int]$response.StatusCode
    } catch {
        if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
            return [int]$_.Exception.Response.StatusCode.value__
        }
        throw
    }
}

Write-Host "Smoke target:"
Write-Host "  FRONTEND_URL=$FrontendUrl"
Write-Host "  BACKEND_API_URL=$BackendApiUrl"
Write-Host "  BACKEND_WEB_URL=$BackendWebUrl"
Write-Host "  REALTIME_URL=$RealtimeUrl"

$frontendCode = Get-StatusCode "$FrontendUrl/"
if ($frontendCode -notin 200, 301, 302, 304) { Fail "Frontend não carregou (HTTP $frontendCode)" }
Pass "Frontend carregando (HTTP $frontendCode)"

$apiHealth = Invoke-RestMethod -Uri "$BackendApiUrl/health" -Method GET
if (-not $apiHealth.ok) { Fail "API /health não retornou ok=true" }
Pass "Backend API /health ok=true"

$webHealthCode = Get-StatusCode "$BackendWebUrl/health"
if ($webHealthCode -ne 200) { Fail "Backend web /health retornou HTTP $webHealthCode" }
Pass "Backend web /health HTTP 200"

$webhookCode = Get-StatusCode "$BackendApiUrl/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=invalid-token&hub_challenge=123"
if ($webhookCode -ne 403) { Fail "Webhook verify deveria negar token inválido (esperado 403, veio $webhookCode)" }
Pass "Webhook verify negando token inválido (403)"

$rtHealth = Invoke-RestMethod -Uri "$RealtimeUrl/health" -Method GET
if (-not $rtHealth.ok) { Fail "Realtime /health não retornou ok=true" }
Pass "Realtime /health ok=true"

if ($LoginEmail -and $LoginPassword) {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

    $csrfCode = Get-StatusCode "$BackendApiUrl/sanctum/csrf-cookie" "GET" $session
    if ($csrfCode -ne 200 -and $csrfCode -ne 204) { Fail "CSRF cookie falhou (HTTP $csrfCode)" }

    $loginBody = @{ email = $LoginEmail; password = $LoginPassword } | ConvertTo-Json -Compress
    $loginCode = Get-StatusCode "$BackendApiUrl/login" "POST" $session "application/json" $loginBody
    if ($loginCode -ne 200) { Fail "Login falhou no smoke (HTTP $loginCode)" }
    Pass "Login básico ok"

    $meCode = Get-StatusCode "$BackendApiUrl/me" "GET" $session
    if ($meCode -ne 200) { Fail "GET /api/me falhou após login (HTTP $meCode)" }
    Pass "Sessão autenticada em /api/me"

    $inboxCode = Get-StatusCode "$BackendApiUrl/minha-conta/conversas" "GET" $session
    if ($inboxCode -ne 200) { Fail "Inbox endpoint falhou (HTTP $inboxCode)" }
    Pass "Inbox endpoint /minha-conta/conversas ok"

    $rtTokenCode = Get-StatusCode "$BackendApiUrl/realtime/token" "POST" $session
    if ($rtTokenCode -ne 200) { Fail "Endpoint de token realtime falhou (HTTP $rtTokenCode)" }
    Pass "Endpoint de token realtime ok"
} else {
    Warn "SMOKE_LOGIN_EMAIL/SMOKE_LOGIN_PASSWORD não definidos; checagens autenticadas foram puladas."
}

Pass "Smoke pós-deploy concluído."
