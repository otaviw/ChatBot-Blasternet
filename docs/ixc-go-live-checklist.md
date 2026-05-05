# Checklist de Go-live - Modulo IXC

Projeto: ChatBot Blasternet  
Ultima atualizacao: 2026-05-05

## 1. Pre-deploy

- [ ] Migration aplicada em homologacao e producao.
- [ ] `scripts/quality-gate.ps1` executado sem falhas.
- [ ] `scripts/complexity-report.ps1` executado e revisado.
- [ ] Permissoes RBAC IXC validadas para:
  - [ ] admin_empresa
  - [ ] agente com permissao
  - [ ] agente sem permissao

## 2. Validacao funcional

- [ ] Listar clientes IXC.
- [ ] Abrir detalhe do cliente.
- [ ] Listar boletos com filtro de status.
- [ ] Consultar detalhe de boleto.
- [ ] Baixar boleto.
- [ ] Enviar boleto por e-mail.
- [ ] Enviar boleto por SMS.

## 3. Validacao de seguranca

- [ ] Sem token IXC em logs.
- [ ] Auditoria criada para:
  - [ ] detalhe boleto
  - [ ] download boleto
  - [ ] envio e-mail
  - [ ] envio SMS
- [ ] Isolamento multi-tenant confirmado (empresa A nao acessa dados da B).

## 4. Validacao de resiliencia

- [ ] Rate limit IXC validado (`429`).
- [ ] Timeout e falhas do provedor com mensagem amigavel.
- [ ] Circuit breaker abre e fecha conforme esperado.

## 5. Rollout controlado

- [ ] Piloto com 1-2 empresas.
- [ ] Monitoramento por 24-48h:
  - [ ] taxa de erro
  - [ ] latencia
  - [ ] volume de `429`
- [ ] Sem regressao em modulos nao relacionados.

## 6. Rollback

- [ ] Procedimento validado: `ixc_enabled=false` por tenant.
- [ ] Time operacional ciente do fallback manual.

## 7. Encerramento

- [ ] Go-live aprovado.
- [ ] Runbook publicado: `docs/ixc-runbook.md`.
- [ ] Responsaveis de suporte treinados no fluxo.
