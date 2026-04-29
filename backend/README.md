# Backend — ChatBot Blasternet

API principal da plataforma, feita com Laravel. Cuida de autenticação, regras de negócio, banco de dados e publica eventos para o servidor realtime.

## Rodando

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan db:smoke
php artisan db:seed --force
php artisan serve
```

Ou use o fluxo seguro de producao:

```bash
composer run migrate-safe
```

## Variáveis de ambiente relevantes

As principais ficam no `.env`. As que merecem atenção para integração com o realtime:

- `REALTIME_PUBLISH_MODE` — como os eventos são publicados (`sync` ou `queue`)
- `REALTIME_JWT_SECRET` — chave JWT para tokens de socket
- `REALTIME_INTERNAL_KEY` — chave usada na comunicação interna com o servidor realtime

## Testes

```bash
php artisan test
```
