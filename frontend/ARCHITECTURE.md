# Arquitetura Frontend

Este documento feito por IA define convencoes de organizacao para manter consistencia e facilitar manutencao.

## Convencao de nomenclatura

- `*Service.js`: modulo responsavel por chamadas de API HTTP.
- Exemplo: `internalChatService.js`, `appointmentService.js`.
- `*Store.js`: estado compartilhado em memoria, ciclo de vida e assinaturas de eventos.
- Exemplo: `realtimeStore.js`.
- `*Payload.js` ou `*Utils.js`: funcoes puras de transformacao e normalizacao de dados.
- Exemplo: `botSettingsPayload.js`.

## Regras de uso

- Nao renomear arquivos legados apenas por padronizacao.
- Para arquivos novos, seguir obrigatoriamente a convencao acima.
- Evitar misturar responsabilidades no mesmo modulo.

## Observabilidade

- Logs internos devem usar `src/lib/logger.js`.
- Nao usar `console.log`, `console.warn` ou `console.error` diretamente no codigo de producao.
