# Migration Template (Producao)

Padrao oficial para novas migrations no backend.

## Regras

- Forward-only: `down()` sem DDL destrutiva.
- Compatibilidade de producao: migration idempotente (deploy repetido nao falha).
- Rollback logico: desfazer via nova migration compensatoria.
- Pos-migrate: executar smoke test (`php artisan db:smoke`).

## Como usar

1. Copiar `forward_only_migration.stub.php` para `database/migrations` com timestamp.
2. Implementar `up()` com guards de tabela/coluna e/ou `addIndexIfMissing`.
3. Em `down()`, manter `forwardOnlyDown()`.
4. Executar:

```bash
php artisan migrate --force
php artisan db:smoke
```

## Rollback logico (exemplo)

Se um indice novo causar regressao, nao use rollback destrutivo em producao.
Crie uma migration nova, por exemplo:

- `2026_05_01_120000_drop_idx_x_and_add_idx_y.php`

Ela deve ajustar o schema para o estado desejado de forma controlada.
