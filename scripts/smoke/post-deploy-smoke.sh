#!/usr/bin/env bash

set -euo pipefail

FRONTEND_URL="${SMOKE_FRONTEND_URL:-http://localhost}"
BACKEND_API_URL="${SMOKE_BACKEND_API_URL:-http://localhost/api}"
BACKEND_WEB_URL="${SMOKE_BACKEND_WEB_URL:-http://localhost}"
REALTIME_URL="${SMOKE_REALTIME_URL:-http://localhost:8081}"

LOGIN_EMAIL="${SMOKE_LOGIN_EMAIL:-}"
LOGIN_PASSWORD="${SMOKE_LOGIN_PASSWORD:-}"

TMP_DIR="$(mktemp -d)"
COOKIE_JAR="$TMP_DIR/cookies.txt"

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

pass() { printf '[PASS] %s\n' "$1"; }
fail() { printf '[FAIL] %s\n' "$1"; exit 1; }
warn() { printf '[WARN] %s\n' "$1"; }

http_code() {
  local url="$1"
  shift || true
  curl -sS -o /dev/null -w '%{http_code}' "$@" "$url"
}

printf 'Smoke target:\n'
printf '  FRONTEND_URL=%s\n' "$FRONTEND_URL"
printf '  BACKEND_API_URL=%s\n' "$BACKEND_API_URL"
printf '  BACKEND_WEB_URL=%s\n' "$BACKEND_WEB_URL"
printf '  REALTIME_URL=%s\n' "$REALTIME_URL"

# 1) Frontend carrega
code="$(http_code "$FRONTEND_URL/")"
[[ "$code" =~ ^(200|301|302|304)$ ]] || fail "Frontend não carregou (HTTP $code)"
pass "Frontend carregando (HTTP $code)"

# 2) Backend API health
api_health="$(curl -sS "$BACKEND_API_URL/health" || true)"
echo "$api_health" | grep -q '"ok"[[:space:]]*:[[:space:]]*true' || fail "API /health não retornou ok=true"
pass "Backend API /health ok=true"

# 3) Backend infra health (DB/Redis)
web_health_code="$(http_code "$BACKEND_WEB_URL/health")"
[[ "$web_health_code" == "200" ]] || fail "Backend web /health retornou HTTP $web_health_code"
pass "Backend web /health HTTP 200"

# 4) Webhook endpoint responde corretamente (teste negativo sem segredo)
webhook_code="$(http_code "$BACKEND_API_URL/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=invalid-token&hub_challenge=123")"
[[ "$webhook_code" == "403" ]] || fail "Webhook verify deveria negar token inválido (esperado 403, veio $webhook_code)"
pass "Webhook verify negando token inválido (403)"

# 5) Realtime health
rt_health="$(curl -sS "$REALTIME_URL/health" || true)"
echo "$rt_health" | grep -q '"ok"[[:space:]]*:[[:space:]]*true' || fail "Realtime /health não retornou ok=true"
pass "Realtime /health ok=true"

# 6) Auth + endpoints críticos (opcional, exige usuário de staging/prod)
if [[ -n "$LOGIN_EMAIL" && -n "$LOGIN_PASSWORD" ]]; then
  csrf_code="$(http_code "$BACKEND_API_URL/sanctum/csrf-cookie" -c "$COOKIE_JAR" -b "$COOKIE_JAR")"
  [[ "$csrf_code" =~ ^(200|204)$ ]] || fail "CSRF cookie falhou (HTTP $csrf_code)"

  login_code="$(
    curl -sS -o /dev/null -w '%{http_code}' \
      -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
      -H 'Content-Type: application/json' \
      -X POST "$BACKEND_API_URL/login" \
      -d "{\"email\":\"$LOGIN_EMAIL\",\"password\":\"$LOGIN_PASSWORD\"}"
  )"
  [[ "$login_code" == "200" ]] || fail "Login falhou no smoke (HTTP $login_code)"
  pass "Login básico ok"

  me_code="$(http_code "$BACKEND_API_URL/me" -c "$COOKIE_JAR" -b "$COOKIE_JAR")"
  [[ "$me_code" == "200" ]] || fail "GET /api/me falhou após login (HTTP $me_code)"
  pass "Sessão autenticada em /api/me"

  inbox_code="$(http_code "$BACKEND_API_URL/minha-conta/conversas" -c "$COOKIE_JAR" -b "$COOKIE_JAR")"
  [[ "$inbox_code" == "200" ]] || fail "Inbox endpoint falhou (HTTP $inbox_code)"
  pass "Inbox endpoint /minha-conta/conversas ok"

  rt_token_code="$(http_code "$BACKEND_API_URL/realtime/token" -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST)"
  [[ "$rt_token_code" == "200" ]] || fail "Realtime token endpoint falhou (HTTP $rt_token_code)"
  pass "Endpoint de token realtime ok"
else
  warn "SMOKE_LOGIN_EMAIL/SMOKE_LOGIN_PASSWORD não definidos; checagens autenticadas foram puladas."
fi

pass "Smoke pós-deploy concluído."
