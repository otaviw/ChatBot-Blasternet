# Relatorio Inicial de Performance de Endpoints Criticos

Gerado em: 2026-04-29T17:45:22+00:00

Base URL: http://127.0.0.1:8091
Requests por endpoint: 30
Concorrencia: 6

## Resumo geral

- Total de requests: 150
- Total de erros: 0
- Taxa de erro: 0%
- Tempo medio (media dos tempos medios por endpoint): 2267.79 ms
- Duracao da suite: 72149.06 ms

## Resultados por endpoint

| Endpoint | Concorrencia | Requests | Avg (ms) | p95 (ms) | Erros | Taxa de erro | Throughput (req/s) |
|---|---:|---:|---:|---:|---:|---:|---:|
| Auth Me (GET /api/me) | 6 | 30 | 2104.63 | 2373.27 | 0 | 0% | 2.25 |
| Inbox Conversas (GET /api/minha-conta/conversas?per_page=15&page=1) | 6 | 30 | 2401.11 | 2958.27 | 0 | 0% | 1.97 |
| Chat Conversas (GET /api/chat/conversations?per_page=20) | 6 | 30 | 2313.83 | 2955.32 | 0 | 0% | 2.04 |
| Notificacoes (GET /api/notifications?per_page=50) | 6 | 30 | 2217.22 | 2593.45 | 0 | 0% | 2.12 |
| Tickets Mine (GET /api/suporte/minhas-solicitacoes) | 6 | 30 | 2302.15 | 2719.64 | 0 | 0% | 2.04 |

## Status HTTP por endpoint

- Auth Me: 200: 30
- Inbox Conversas: 200: 30
- Chat Conversas: 200: 30
- Notificacoes: 200: 30
- Tickets Mine: 200: 30
