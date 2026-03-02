# ChatBot Blasternet

Projeto multi-servico com:

- `backend/`: Laravel (API, autorizacao, regras de negocio, auditoria)
- `frontend/`: React + Vite (painel operacional)
- `realtime/`: Node.js + Socket.IO (somente notificacoes realtime)

## Arquitetura realtime

Fluxo obrigatorio implementado:

1. React chama REST no Laravel
2. Laravel valida, autoriza e persiste
3. Laravel publica evento realtime
4. Realtime consome evento e emite para rooms
5. React atualiza estado/UI

Importante:

- Frontend nao executa acao critica via WebSocket
- WebSocket nao e canal de comando de negocio
- Realtime apenas notifica

## Eventos realtime implementados

- `message.created`
- `bot.updated`
- `conversation.transferred`

## Rooms

- Base:
  - `company:{companyId}`
  - `user:{userId}`
- Conversa:
  - `conversation:{conversationId}` (join somente com token curto validado pelo backend)

## Requisitos

- PHP 8.2+
- Composer
- Node.js 20+
- NPM
- Redis
- Banco de dados configurado em `backend/.env`

## Setup rapido

### 1) Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
```

### 2) Frontend

```bash
cd frontend
cp .env.example .env
npm install
```

### 3) Realtime

```bash
cd realtime
cp .env.example .env
npm install
```

## Rodar local

### Backend

```bash
cd backend
php artisan serve
```

### Frontend

```bash
cd frontend
npm run dev
```

### Realtime

```bash
cd realtime
npm run dev
```

## Producao (baseline)

1. Subir `backend`, `frontend` e `realtime` em processos isolados
2. Configurar Redis compartilhado para backend/realtime
3. Definir segredos:
   - `REALTIME_JWT_SECRET` (backend + realtime, mesmo valor)
   - `REALTIME_INTERNAL_KEY` (backend + realtime, mesmo valor)
4. Build frontend:

```bash
cd frontend
npm run build
```

5. Rodar realtime:

```bash
cd realtime
npm install --omit=dev
npm run start
```

6. Rodar migrations no backend e garantir worker de fila ativo se `REALTIME_PUBLISH_MODE=queue`

## Checklist de seguranca

- [ ] JWT curto para socket (`REALTIME_TOKEN_TTL_SECONDS`)
- [ ] Rate limit em `/api/realtime/token` e join token
- [ ] CORS restrito no realtime (`REALTIME_CORS_ORIGINS`)
- [ ] Somente websocket (sem polling)
- [ ] Chave interna (`X-INTERNAL-KEY`) forte e rotacionada
- [ ] Endpoint `/internal/emit` exposto apenas internamente
- [ ] Nao incluir payload sensivel nos broadcasts
- [ ] Logs monitorados para `realtime.publish.*` e `socket.auth_failed`

## Escalabilidade

- Laravel publica envelopes em `realtime.events` via Redis Pub/Sub
- Cada instancia realtime assina o canal e entrega para suas conexoes locais
- Para cenarios avancados de Socket.IO cross-node, evoluir para Redis adapter (`@socket.io/redis-adapter`)

## Logs estruturados

- Backend: eventos de publish/fallback em logs Laravel com contexto (`event`, `rooms`, `meta`)
- Realtime: logs JSON por linha (`timestamp`, `level`, `message`, contexto)
