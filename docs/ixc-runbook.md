# Runbook Operacional - Integracao IXC

Projeto: ChatBot Blasternet  
Ultima atualizacao: 2026-05-05

## 1. Objetivo

Padronizar operacao do modulo IXC em producao:
- diagnosticar falhas rapidamente;
- restaurar disponibilidade com menor impacto;
- executar fallback por tenant sem parar todo o sistema.

## 2. Escopo

- Configuracao IXC por empresa (`ixc_base_url`, `ixc_api_token`, flags).
- Rotas:
  - `GET /api/minha-conta/ixc/clientes`
  - `GET /api/minha-conta/ixc/clientes/{id}`
  - `GET /api/minha-conta/ixc/clientes/{id}/boletos`
  - `POST /api/minha-conta/ixc/clientes/{id}/boletos/{id}/download`
  - `POST /api/minha-conta/ixc/clientes/{id}/boletos/{id}/enviar-email`
  - `POST /api/minha-conta/ixc/clientes/{id}/boletos/{id}/enviar-sms`

## 3. Sinais de alerta

- Crescimento de `429` nas rotas IXC.
- Mensagem frequente: `Integracao IXC temporariamente indisponivel...` (circuit breaker aberto).
- Aumento de latencia em `ixc.request.ok`.
- Aumento de `ixc.request.fail` com `HTTP 5xx`.

## 4. Diagnostico rapido

1. Confirmar se falha e global ou por tenant.
2. Verificar logs:
- `ixc.request.fail`
- `ixc.request.ok`
3. Identificar tipo:
- auth/permissao IXC;
- timeout/rede;
- limitacao de taxa;
- indisponibilidade do provedor.

## 5. Mitigacao imediata

1. Tenant isolado com problema:
- desabilitar integracao da empresa: `ixc_enabled=false`.
2. Alto volume de consultas:
- reduzir uso operacional temporariamente;
- ajustar limites `RATE_LIMIT_IXC_READ` e `RATE_LIMIT_IXC_WRITE`.
3. Instabilidade do provedor:
- aguardar janela de recuperacao do breaker;
- orientar time de atendimento para fallback manual.

## 6. Recuperacao

1. Revalidar credenciais IXC na tela da empresa.
2. Confirmar rota de listagem de clientes.
3. Validar um download de boleto.
4. Validar um envio de e-mail/SMS em ambiente controlado.

## 7. Rollback funcional

Rollback sem deploy:
1. Desativar IXC por tenant (`ixc_enabled=false`).
2. Confirmar menu/fluxo bloqueado para a empresa afetada.
3. Manter demais tenants operando normalmente.

## 8. Seguranca operacional

- Nao registrar token IXC em logs.
- Nao expor linha digitavel completa em logs.
- Manter destino de envio mascarado na auditoria.

## 9. Checklist de saida do incidente

- [ ] Causa identificada (provedor/rede/configuracao/permissao).
- [ ] Integracao restaurada para tenant afetado.
- [ ] Fluxo clientes/boletos/download/envio validado.
- [ ] Stakeholders atualizados.
- [ ] Acao preventiva registrada.
