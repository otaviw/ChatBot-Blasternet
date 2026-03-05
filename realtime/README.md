# Realtime Service (`/realtime`)

Serviço Socket.IO desacoplado para notificações em tempo real.

## Responsabilidade

- Receber eventos publicados pelo backend Laravel via Redis (`realtime.events`)
- Emitir eventos para rooms autorizadas
- Nao executa comandos de negocio
- Nao valida regras de dominio (isso continua no Laravel)

## Fluxo oficial

1. Frontend chama REST no Laravel
2. Laravel valida, autoriza e persiste
3. Laravel publica envelope realtime
4. Realtime emite para rooms
5. Frontend atualiza UI

## Endpoints

- `GET /health`
- `POST /internal/emit` (fallback protegido por `X-INTERNAL-KEY`)

## Seguranca aplicada

- Auth obrigatoria no handshake com JWT curto
- `transports: ['websocket']` (sem polling)
- CORS restrito por `REALTIME_CORS_ORIGINS`
- Rooms base automáticas:
  - `company:{companyId}`
  - `user:{userId}`
- Room de conversa somente via `conversation.join` com join token valido emitido pelo backend
- Emissoes do cliente sao bloqueadas (somente `conversation.join` e `conversation.leave` sao aceitos)
- Logs estruturados JSON

## Escalabilidade

- Redis Pub/Sub como barramento de eventos entre backend e realtime
- Estrutura pronta para multiplas instancias: cada instancia assina o mesmo canal e entrega eventos localmente
- Para casos avancados de comunicacao inter-node Socket.IO (acks cross-node e estado compartilhado), evoluir para `@socket.io/redis-adapter`

## Desenvolvimento local

```bash
cd realtime
cp .env.example .env
npm install
npm run dev
```

## Producao

```bash
cd realtime
npm install --omit=dev
npm run start
```

Recomendações:

- Rodar atras de reverse proxy (Nginx/Traefik) com TLS
- Restringir acesso a `/internal/emit` para rede interna
- Rotacionar `REALTIME_JWT_SECRET` e `REALTIME_INTERNAL_KEY`
- Definir limites e alertas para reconexao Redis
