# ChatBot Blasternet

Plataforma de atendimento via chat com suporte a bot, inbox por empresa e painel administrativo.

O projeto é dividido em três partes: o backend em Laravel, o frontend em React e um servidor de tempo real em Node.js que cuida das notificações ao vivo.

## Como rodar localmente

**Backend**

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan serve
```

**Frontend**

```bash
cd frontend
cp .env.example .env
npm install
npm run dev
```

**Realtime**

```bash
cd realtime
cp .env.example .env
npm install
npm run dev
```

## O que cada parte faz

O **backend** (Laravel) é o núcleo: cuida das regras de negócio, autenticação, banco de dados e publica eventos para o servidor realtime.

O **frontend** (React) é o painel: exibe conversas, inbox, configurações de bot e área administrativa.

O **realtime** (Node.js + Socket.IO) só recebe eventos do backend e os entrega para o navegador. Ele não processa lógica de negócio.

## Dependências necessárias

- PHP 8.2+
- Composer
- Node.js 20+
- Redis
- Banco de dados configurado no `.env` do backend

## Para produção

Sobe os três serviços em processos separados. O Redis precisa ser compartilhado entre o backend e o realtime. Variáveis importantes a definir:

- `REALTIME_JWT_SECRET` — mesma chave no backend e no realtime
- `REALTIME_INTERNAL_KEY` — mesma chave no backend e no realtime

Build do frontend:

```bash
cd frontend
npm run build
```
