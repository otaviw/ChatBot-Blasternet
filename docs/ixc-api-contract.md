# Contrato de Integracao IXC

## Objetivo
Definir contrato tecnico minimo para integracao IXC no backend, incluindo requests, respostas esperadas, fallback e erros tratados.

## Configuracao por empresa
- `ixc_base_url`: URL base da API IXC (ex.: `https://provedor.exemplo/webservice/v1`)
- `ixc_api_token`: token da integracao
- `ixc_self_signed`: permite certificado self-signed
- `ixc_timeout_seconds`: timeout por request (5-60)
- `ixc_enabled`: habilita/desabilita integracao

## Cabecalhos enviados para IXC
- `ixcsoft`: modo `listar` ou token
- `Authorization`: `Basic {base64(token)}`
- `Accept`: `application/json` (rotas JSON)

## Endpoints internos (backend -> frontend)
- `GET /api/minha-conta/ixc/clientes`
- `GET /api/minha-conta/ixc/clientes/{clientId}`
- `GET /api/minha-conta/ixc/clientes/{clientId}/boletos`
- `GET /api/minha-conta/ixc/clientes/{clientId}/boletos/{invoiceId}`
- `POST /api/minha-conta/ixc/clientes/{clientId}/boletos/{invoiceId}/download`
- `POST /api/minha-conta/ixc/clientes/{clientId}/boletos/{invoiceId}/enviar-email`
- `POST /api/minha-conta/ixc/clientes/{clientId}/boletos/{invoiceId}/enviar-sms`

## Recursos IXC usados
- `cliente` (listagem e detalhe)
- `fn_areceber` (boletos)
- `get_boleto` (download/envio de boleto)
- alternativos de cliente (config): `listar_clientes_por_cpf`, `listar_clientes_por_telefone`, `listar_clientes_fibra`

## Contrato de resposta esperado da IXC
Formatos aceitos para lista:
- `registros` + `total`
- `items|rows|records|data|dados` + `total|total_records|totalRecords|recordsTotal`

Erro funcional do provedor:
- payload com `type=error` e/ou `error`
- `message|mensagem` usado como mensagem final

## Fallback de autenticacao (somente GET de lista)
1. Tenta modo `listar` (`ixcsoft=listar`)
2. Se vier lista vazia (`items=0` e `total=0`), tenta modo `token`
3. Se `type=error` em `listar` e houver `token` disponivel, tenta `token`

## Circuit breaker
- Janela de falhas: `120s`
- Threshold: `5` falhas consecutivas elegiveis
- Breaker aberto por `90s`
- Falhas que contam:
  - conexao
  - erro inesperado
  - HTTP 5xx

## Erros padronizados no backend
- `Integracao IXC temporariamente indisponivel...` (breaker aberto)
- `Credenciais IXC incompletas...`
- `URL base da IXC invalida ou nao permitida.`
- `Falha de conexao com a API IXC.`
- `Erro inesperado ao consultar a API IXC.`
- `IXC respondeu com HTTP {status}.`

## Observabilidade operacional
Eventos de log:
- `ixc.request.ok`
- `ixc.request.fail`
- `ixc.breaker.opened`
- `ixc.breaker.blocked_request`
- `ixc.metric`

Metricas em `ixc.metric`:
- `request_latency_ms`
- `request_error_total`
- `breaker_open_total`
- `breaker_blocked_total`

## Fixtures de referencia
Fixtures versionadas para testes:
- `backend/tests/Fixtures/ixc/cliente-list-success.json`
- `backend/tests/Fixtures/ixc/cliente-list-empty.json`
- `backend/tests/Fixtures/ixc/provider-error.json`
- `backend/tests/Fixtures/ixc/fn-areceber-list-success.json`
- `backend/tests/Fixtures/ixc/get-boleto-binary-success.json`
