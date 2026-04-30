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
php artisan db:smoke
php artisan db:seed --force
php artisan serve
```

Opcional (fluxo seguro de migrate + smoke):

```bash
cd backend
composer run migrate-safe
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

Workers de fila em producao (Supervisor):

- Config pronta: [docs/supervisor.conf](docs/supervisor.conf)
- Guia de uso: [docs/queue-workers-supervisor.md](docs/queue-workers-supervisor.md)

## Backup do banco de dados

O scheduler do Laravel executa o backup automaticamente todo dia às **03:00** via `php artisan backup:database`.

Para o agendamento funcionar, o cron do servidor precisa ter esta entrada:

```
* * * * * cd /var/www/html/ChatBot-Blasternet/backend && php artisan schedule:run >> /dev/null 2>&1
```

**Executar backup manualmente:**

```bash
# Via Artisan (recomendado — usa o mesmo fluxo do scheduler)
cd backend
php artisan backup:database

# Diretamente pelo script bash
bash scripts/backup-db.sh
```

Os arquivos ficam em `backups/` na raiz do projeto, no formato `backup_YYYY-MM-DD_HH-MM.sql.gz`. São mantidos os **7 backups mais recentes**.

**Restaurar um backup:**

```bash
# 1. Escolha o arquivo desejado
ls backups/

# 2. Descomprima e restaure (substitua os valores entre < >)
gunzip -c backups/backup_YYYY-MM-DD_HH-MM.sql.gz \
  | mysql -h <DB_HOST> -u <DB_USERNAME> -p <DB_DATABASE>

# Exemplo completo lendo as variáveis do .env
source <(grep -E '^DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)' backend/.env)
gunzip -c backups/backup_YYYY-MM-DD_HH-MM.sql.gz \
  | mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"
```

> **Atenção:** restaurar sobrescreve todos os dados existentes no banco. Faça um backup do estado atual antes de restaurar.
