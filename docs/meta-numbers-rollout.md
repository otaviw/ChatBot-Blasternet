# Meta Numbers Rollout

## Deploy 1
- Aplicar migrations.
- Publicar backend com fallback seguro:
  - `META_NUMBERS_REQUIRE_SELECTION_ON_NEW_CONVERSATION=false`
  - `META_NUMBERS_ENFORCE_CAMPAIGN_CONTACT_NUMBER=false`

## Backfill
- Executar:
  - `php artisan meta-numbers:backfill-contact-defaults --dry-run`
  - `php artisan meta-numbers:backfill-contact-defaults`
- Regra: contatos sem `meta_number_id` recebem o número principal ativo da empresa, quando existir.

## Deploy 2
- Liberar UI de gestão e seleção (frontend).
- Ativar seleção obrigatória na abertura de conversa:
  - `META_NUMBERS_REQUIRE_SELECTION_ON_NEW_CONVERSATION=true`

## Deploy 3
- Ativar regra obrigatória em campanhas:
  - `META_NUMBERS_ENFORCE_CAMPAIGN_CONTACT_NUMBER=true`
- Garantir monitoramento ligado:
  - `META_NUMBERS_MONITORING_ENABLED=true`

## Pós Go-Live (7 dias)
- Executar monitoramento diário:
  - `php artisan meta-numbers:monitor --days=7`
- Acompanhar:
  - `% envios por fallback`
  - `% contatos sem número válido`
  - empresas sem número ativo
  - picos de falha `NO_ACTIVE_META_NUMBER_FOR_COMPANY`

