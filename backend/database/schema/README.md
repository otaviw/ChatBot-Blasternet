# Schema Dump

Este diretorio armazena o snapshot de schema do Laravel gerado via `php artisan schema:dump`.

## Objetivo

- Reduzir tempo de `migrate:fresh` em ambientes novos.
- Facilitar entendimento do estado atual do schema sem ler todas as migrations antigas.

## Como atualizar

Na raiz do projeto:

```bash
powershell -ExecutionPolicy Bypass -File scripts/update-schema-dump.ps1
```

Ou manualmente:

```bash
cd backend
php artisan schema:dump
```

## Observacoes

- Nao usar `--prune` sem planejamento explicito (para preservar historico de migrations de producao).
- Migrations recentes continuam individuais; o dump funciona como snapshot periodico.
