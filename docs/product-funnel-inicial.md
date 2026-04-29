# Relatorio inicial de funil de produto

Data: 2026-04-29

## Funis mapeados

- `cadastro`: `company_created` -> `user_created`
- `login`: `attempt` -> `success`
- `uso_chatbot`: `inbound_received` -> `auto_reply_sent`
- `transferencia`: `requested` -> `completed`
- `conversao_feature_principal`: `manual_or_template_sent` -> `conversation_closed`

## Eventos instrumentados no backend

- `admin_company_created`
- `admin_user_created`
- `company_user_created`
- `auth_login_attempt`
- `auth_login_success`
- `chatbot_inbound_received`
- `chatbot_auto_reply_sent`
- `conversation_transfer_requested`
- `conversation_transfer_completed`
- `manual_reply_sent`
- `template_message_sent`
- `conversation_closed`

## Metricas de funil

- `entered`: total do primeiro passo do funil
- `converted`: total do ultimo passo do funil
- `conversion_rate_pct`: `(converted / entered) * 100`

## Endpoint de consulta

- `GET /api/minha-conta/produto/funil?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`
- resposta com agrupamento por funil e passos

## Observacoes operacionais

- Escrita de evento e fail-safe: erros de gravação não quebram os fluxos de negócio.
- Leitura otimizada: sumarização usa `group by` único no período filtrado.
- Escopo multi-tenant: endpoint retorna apenas dados da empresa do usuário autenticado.
