# Testes de Performance de Endpoints Criticos

Este projeto possui um runner de carga leve para medir:

- p95
- tempo medio
- erros
- concorrencia

## Arquivos

- `scripts/performance-endpoints.php`: runner HTTP com carga concorrente e metricas.
- `scripts/run-performance.ps1`: orquestra seed baseline, sobe servidor local, executa suite e gera relatorios.
- `docs/perf-initial-report.json`: saida bruta em JSON.
- `docs/performance-report-inicial.md`: relatorio markdown consolidado.

## Execucao

Na raiz do projeto:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/run-performance.ps1 -Requests 30 -Concurrency 6 -TimeoutSeconds 15
```

Parametros importantes:

- `-Requests`: total de requisicoes por endpoint.
- `-Concurrency`: concorrencia simultanea por endpoint.
- `-TimeoutSeconds`: timeout de cada request.

## Endpoints avaliados na suite inicial

- `GET /api/me`
- `GET /api/minha-conta/conversas?per_page=15&page=1`
- `GET /api/chat/conversations?per_page=20`
- `GET /api/notifications?per_page=50`
- `GET /api/suporte/minhas-solicitacoes`

## Observacoes

- O runner usa login por sessao (`/api/login`) com usuario baseline do `db:seed`:
  - email: `empresa@teste.local`
  - senha: `teste123`
- O script define `WHATSAPP_APP_SECRET` em runtime para evitar bloqueio de bootstrap no ambiente local.
