# Smoke Tests Pós-Deploy

Objetivo: validar em poucos minutos se o release está operacional em backend, frontend, realtime e fluxos críticos.

## 1. Escopo

Este smoke cobre:

- frontend carregando;
- backend `/api/health` e `/health`;
- webhook verify respondendo corretamente;
- realtime `/health`;
- autenticação básica e inbox (quando credenciais de teste estiverem disponíveis);
- endpoint de token realtime;
- checks operacionais de fila e logs.

Não substitui testes funcionais completos nem E2E.

## 2. Pré-requisitos

- Deploy já concluído.
- URLs do ambiente (staging/prod).
- Usuário técnico de smoke (opcional, recomendado) com acesso mínimo para login/inbox.
- Acesso shell ao servidor de aplicação (para checagem de queue/logs).

## 3. Execução automática (script simples)

Script: [scripts/smoke/post-deploy-smoke.sh](../scripts/smoke/post-deploy-smoke.sh)
e [scripts/smoke/post-deploy-smoke.ps1](../scripts/smoke/post-deploy-smoke.ps1)

Exemplo sem credenciais (somente checks públicos):

```bash
SMOKE_FRONTEND_URL="https://app.seudominio.com" \
SMOKE_BACKEND_API_URL="https://api.seudominio.com/api" \
SMOKE_BACKEND_WEB_URL="https://api.seudominio.com" \
SMOKE_REALTIME_URL="https://rt.seudominio.com" \
bash scripts/smoke/post-deploy-smoke.sh
```

PowerShell (Windows):

```powershell
$env:SMOKE_FRONTEND_URL="https://app.seudominio.com"
$env:SMOKE_BACKEND_API_URL="https://api.seudominio.com/api"
$env:SMOKE_BACKEND_WEB_URL="https://api.seudominio.com"
$env:SMOKE_REALTIME_URL="https://rt.seudominio.com"
powershell -ExecutionPolicy Bypass -File scripts/smoke/post-deploy-smoke.ps1
```

Exemplo com credenciais de usuário técnico de smoke:

```bash
SMOKE_FRONTEND_URL="https://app.seudominio.com" \
SMOKE_BACKEND_API_URL="https://api.seudominio.com/api" \
SMOKE_BACKEND_WEB_URL="https://api.seudominio.com" \
SMOKE_REALTIME_URL="https://rt.seudominio.com" \
SMOKE_LOGIN_EMAIL="smoke-user@empresa.com" \
SMOKE_LOGIN_PASSWORD="<senha-segura-via-secret-manager>" \
bash scripts/smoke/post-deploy-smoke.sh
```

PowerShell (Windows):

```powershell
$env:SMOKE_FRONTEND_URL="https://app.seudominio.com"
$env:SMOKE_BACKEND_API_URL="https://api.seudominio.com/api"
$env:SMOKE_BACKEND_WEB_URL="https://api.seudominio.com"
$env:SMOKE_REALTIME_URL="https://rt.seudominio.com"
$env:SMOKE_LOGIN_EMAIL="smoke-user@empresa.com"
$env:SMOKE_LOGIN_PASSWORD="<senha-segura-via-secret-manager>"
powershell -ExecutionPolicy Bypass -File scripts/smoke/post-deploy-smoke.ps1
```

## 4. Checklist manual complementar (obrigatório)

## 4.1 Fila/queue

No backend:

```bash
cd /var/www/html/ChatBot-Blasternet/backend
php artisan queue:failed
php artisan queue:monitor redis:default redis:realtime redis:ai
```

Supervisor:

```bash
sudo supervisorctl status chatbot-workers:*
```

## 4.2 Logs críticos (10–15 min após deploy)

- `backend/storage/logs/laravel.log`
- `/var/log/supervisor/chatbot-worker-default.log`
- `/var/log/supervisor/chatbot-worker-realtime.log`
- `/var/log/supervisor/chatbot-worker-ai.log`
- `journalctl -u chatbot-realtime -n 200 --no-pager`

Critério: sem loop de erro crítico novo, sem spike de 5xx/timeout.

## 4.3 Fluxo rápido de produto (manual)

- [ ] abrir frontend e autenticar com usuário válido;
- [ ] abrir inbox e listar conversas;
- [ ] abrir uma conversa e enviar mensagem manual curta;
- [ ] confirmar atualização de status de entrega no painel;
- [ ] validar que realtime não está em reconexão infinita.

## 5. O que é automático vs manual

Automático no script:

- frontend up;
- backend `/api/health` e `/health`;
- webhook verify (nega token inválido com 403);
- realtime `/health`;
- login + `/api/me` + inbox endpoint + `/api/realtime/token` (quando credenciais forem passadas).

Manual obrigatório:

- queue workers;
- análise de logs;
- fluxo visual/operacional de inbox;
- confirmação de envio/recebimento real do WhatsApp (depende de integração do ambiente).

## 6. Critério de aprovação pós-deploy

- script de smoke sem falhas;
- queue operacional sem backlog anormal;
- logs sem erro crítico recorrente;
- fluxo manual mínimo de atendimento ok.

Se algum item crítico falhar, abrir incidente e seguir [docs/rollback.md](./rollback.md).

## 7. Limitações

- script não valida entrega real fim-a-fim da Meta em todos os cenários.
- checks autenticados exigem credenciais externas (não ficam no repositório).
- conectividade socket é validada por health e endpoint de token; não abre websocket real completo.
- não cobre regressões de UX detalhadas.
