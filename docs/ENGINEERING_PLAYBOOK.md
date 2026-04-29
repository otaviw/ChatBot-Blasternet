# Engineering Playbook

Este documento feito por IA define o padrao de manutencao do projeto para facilitar onboarding, refatoracao e evolucao com baixo risco.

## Objetivos

- Codigo facil de entender por times novos.
- Mudancas pequenas, testaveis e com baixo acoplamento.
- Mesma logica de organizacao entre backend, frontend e realtime.

## Regras de Arquitetura

- Controllers devem ser finos: validar request, autorizar e delegar para `Service` ou `Action`.
- Regras de negocio ficam em `app/Services` ou `app/Actions`, nunca espalhadas em controller.
- Validacao deve usar `FormRequest` quando existir fluxo com regras mais complexas.
- Acesso multi-tenant deve sempre respeitar escopo de empresa/revenda.
- No frontend, respeitar boundaries:
  - `pages` nao importa `pages`
  - `components` nao importa `pages`
  - `services` nao importa `ui`

## Checklist de Refatoracao (humano + IA)

1. Entender comportamento atual por testes e endpoints.
2. Extrair logica duplicada para `Service`/`Action` com nome explicito.
3. Reduzir metodos longos em passos pequenos e coesos.
4. Remover literais repetidos (chaves, mensagens, defaults) para um ponto unico.
5. Garantir tipagem/contratos claros nos metodos.
6. Cobrir casos criticos com testes unitarios e/ou feature.
7. Rodar quality gate completo antes de concluir.

## Definition of Done para cada mudanca

1. Sem alteracao de regra de negocio nao planejada.
2. Sem regressao em testes existentes.
3. Novas regras cobertas por testes.
4. Lint e build sem warnings bloqueantes.
5. Codigo com nomes claros e sem duplicacao obvia.

## Comandos obrigatorios antes de merge

Na raiz do projeto:

```bash
powershell -ExecutionPolicy Bypass -File scripts/quality-gate.ps1
powershell -ExecutionPolicy Bypass -File scripts/complexity-report.ps1
```

## Checklist de PR

- Use o template em `.github/pull_request_template.md`.
- O PR deve descrever escopo, evidencias de teste e riscos conhecidos.
- Se o relatorio de complexidade mudou, registrar os 3 principais alvos do proximo ciclo.

## Estrategia segura para proximas melhorias

1. Refatorar por modulo (ex.: Companies, Bot, Users), nao por projeto inteiro de uma vez.
2. Cada PR deve conter:
   - uma melhoria estrutural pequena
   - testes da melhoria
   - zero mudanca colateral fora do escopo
3. Manter padrao "thin controller + service/action" como default para novas features.
