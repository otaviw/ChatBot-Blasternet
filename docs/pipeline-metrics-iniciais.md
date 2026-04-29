# Relatorio Simples de Metricas Iniciais de Pipeline

Data da coleta: 2026-04-27  
Escopo: baseline inicial do pipeline `quality-gate` com medicao local.

## 1) Tempo medio build

Observacao: como baseline inicial, foi usada 1 amostra por etapa executada localmente.

| Etapa | Tempo (s) |
|---|---:|
| Backend lint (php -l) | 150.27 |
| Backend testes | 109.88 |
| Frontend lint | 18.47 |
| Frontend testes + cobertura | 24.82 |
| Frontend build | 26.83 |
| Realtime lint | 3.08 |
| Realtime testes + cobertura | 5.70 |
| **Total quality gate (sem install de dependencias)** | **338.05** |

Resumo:
- Tempo total baseline do gate: `~5m38s`.
- Tempo de build do frontend: `26.83s`.

## 2) Cobertura atual

| Servico | Linhas | Branches | Functions | Statements |
|---|---:|---:|---:|---:|
| Backend | N/D local* | N/D local* | N/D local* | N/D local* |
| Frontend | 69.96% | 62.59% | 77.86% | 69.07% |
| Realtime | 84.30% | 61.42% | 100.00% | 84.30% |

\* No ambiente local, o backend nao possui driver de cobertura habilitado; no CI o workflow esta configurado com `pcov`.

## 3) Falhas por pipeline

Historico consolidado de falhas no GitHub Actions: **N/D neste ambiente** (sem `gh` e sem acesso historico remoto).

Baseline local desta coleta:
- Frontend lint/build/testes+cobertura: passou.
- Realtime lint/testes+cobertura: passou.
- Backend lint: passou.
- Backend cobertura: nao mensuravel localmente (falta driver), mensuravel no CI.

## 4) Tempo medio PR

**N/D neste ambiente** (depende de dados de PR no GitHub: `createdAt` vs `mergedAt`).

## 5) Bugs detectaveis pelo pipeline atual

Classes de bugs que o pipeline consegue detectar hoje:
- Erros de sintaxe JS/Node no `realtime` e sintaxe PHP no `backend`.
- Violacoes de lint no `frontend`.
- Regressao funcional coberta por testes automatizados (backend/frontend/realtime).
- Queda de cobertura abaixo dos thresholds configurados.
- Quebra de build do frontend.

## Notas de confiabilidade da linha de base

- Esta e uma **linha de base inicial** para acompanhar tendencia.
- Para metricas historicas (falhas por pipeline e tempo medio PR), e necessario coletar dados do GitHub Actions/PRs via API ou `gh`.
