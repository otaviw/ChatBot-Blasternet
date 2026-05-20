# Dashboard IA - contrato do endpoint

Endpoint: `GET /api/minha-conta/ia/analytics`

Objetivo: entregar o dashboard operacional de IA por empresa, com consumo, qualidade, handoff, custo estimado, intents e gargalos.

## Escopo multi-tenant

- `company_admin`: sempre limitado a `user.company_id`.
- `system_admin`: aceita `company_id`.
- `company_id=all`, `company_id=0` ou ausente em `system_admin`: agrega todas as empresas.

## Filtros

- `date_from`: data inicial `YYYY-MM-DD`.
- `date_to`: data final `YYYY-MM-DD`.
- `days`: fallback quando datas nao sao enviadas. Aceita `7`, `30` ou `90`.
- `channel`: `all`, `whatsapp` ou `internal_chat`.
- `area_id`: filtra logs de decisao por area de handoff/area atual da conversa.
- `flow`: filtra logs de decisao por fluxo registrado.
- `provider`: filtra chamadas de provider.
- `feature`: filtra chamadas por feature de `ai_usage_logs`.
- `export=csv`: retorna CSV basico.
- `export=json`: retorna JSON normal.

## Principais campos de resposta

- `summary.provider_requests`: chamadas registradas em `ai_usage_logs`.
- `summary.chatbot_decisions`: decisoes registradas em `ai_chatbot_decision_logs`.
- `summary.total_tokens`: tokens de chamadas ao provider.
- `summary.estimated_cost`: custo estimado baseado em `AI_ESTIMATED_COST_PER_1K_TOKENS`.
- `summary.resolution_rate_pct`: decisoes sem handoff nem erro.
- `summary.handoff_menu_count`: handoff originado por opcao de menu/fluxo.
- `summary.handoff_incapacity_count`: handoff por incapacidade, inseguranca ou fora de escopo.
- `daily`: serie diaria com provider, decisoes, tokens, handoffs e falhas.
- `top_intents`: intents mais frequentes e volume de handoff.
- `handoff_by_type`: separacao `menu` vs `incapacity`.
- `handoff_reasons`: motivos normalizados de transferencia.
- `bottlenecks_by_flow`: gargalos por fluxo, ordenados por handoff/falhas.
- `by_provider`: consumo tecnico por provider.
- `by_feature`: consumo tecnico por feature.
- `filter_options`: opcoes de canais, areas, fluxos e features para a UI.
- `export_urls`: URLs prontas para CSV/JSON.

## Observacoes de custo

O custo e estimado. A base de calculo e:

`summary.total_tokens / 1000 * AI_ESTIMATED_COST_PER_1K_TOKENS`

Se o provider nao retornar tokens, a chamada entra nas contagens de qualidade/falha, mas nao aumenta o custo estimado.
