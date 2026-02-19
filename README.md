# ChatBot Blasternet

Projeto de chatbot multiempresa com:
- Backend Laravel (API, regras do bot, simulador, administracao)
- Frontend React + Vite (painel de configuracao e operacao)

## Estrutura

- `backend/`: API e regras de negocio (Laravel)
- `frontend/`: interface web (React)

## Requisitos

- PHP 8.2+
- Composer
- Node.js 20+
- NPM
- Banco de dados configurado no `backend/.env`

## Setup rapido

### 1. Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
```

### 2. Frontend

```bash
cd frontend
npm install
```

## Rodar em desenvolvimento

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

## Testes

### Backend (Laravel)

```bash
cd backend
php artisan test
```

## Padroes de projeto

- Multiempresa com isolamento por empresa
- Admin com visao global e capacidade de gerenciamento
- Logs de auditoria para acoes criticas
- Configuracao de bot por empresa (horarios, fallback, regras)
- Fluxo de atendimento manual com retorno ao bot

## Seguranca e boas praticas

- Nao versionar arquivos `.env` e segredos
- Validar permissoes por perfil e empresa em todas as rotas
- Manter dependencias atualizadas
- Executar testes antes de deploy

## Deploy (baseline)

1. Rodar testes no backend
2. Build do frontend: `npm run build`
3. Aplicar migracoes no backend
4. Revisar variaveis de ambiente de producao
5. Validar logs e health check apos subida
