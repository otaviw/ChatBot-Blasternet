# Playbook Tecnico de Incidentes

Projeto: ChatBot Blasternet  
Ultima atualizacao: 2026-04-27

## 1. Objetivo

Padronizar resposta a incidentes com foco em:
- Restaurar servico rapidamente.
- Reduzir impacto em clientes.
- Garantir rastreabilidade de decisoes.
- Executar rollback seguro sem alterar regra de negocio.

## 2. Escopo

Cobertura deste playbook:
- Backend (Laravel/API/jobs).
- Frontend (React/Vite/build estatico).
- WebSocket/Realtime (Node.js + Socket.IO + Redis Pub/Sub).
- Banco de dados (MySQL + migrations/backups).

## 3. Niveis de Severidade

- `SEV-1`: indisponibilidade total ou risco de perda/corrupcao de dados.
- `SEV-2`: funcionalidade critica degradada com alto impacto de negocio.
- `SEV-3`: degradacao parcial com workaround operacional.
- `SEV-4`: baixo impacto, sem urgencia operacional.

SLA interno recomendado:
- `SEV-1`: iniciar resposta imediata, atualizar status a cada 15 min.
- `SEV-2`: iniciar em ate 15 min, atualizar a cada 30 min.
- `SEV-3/4`: janela normal de operacao.

## 4. Time de Resposta (comando minimo)

- `Incident Commander (IC)`: decide prioridade e estrategia (rollback/mitigacao).
- `Backend Owner`: responde API, filas, jobs, auth e integracoes.
- `Frontend Owner`: responde build, assets e regressao visual/fluxos.
- `Realtime Owner`: responde socket, conexoes, rooms e Redis.
- `DB Owner`: responde integridade, performance e restauracao.

## 5. Fluxo de Emergencia

1. `Detectar`: confirmar sintoma com evidencias (erro, log, monitor, usuario).
2. `Classificar`: definir severidade (`SEV-1..4`) e abrir canal de incidente.
3. `Conter`: aplicar mitigacao rapida para parar piora (feature flag, isolamento, desvio).
4. `Restaurar`: executar rollback rapido por camada afetada.
5. `Validar`: checar healthchecks, caminhos criticos e logs.
6. `Comunicar`: publicar status tecnico e de negocio.
7. `Encerrar`: registrar causa raiz preliminar e acoes de prevencao.

## 6. Checklist Global (primeiros 10 minutos)

- [ ] Confirmar `SEV` e nomear `Incident Commander`.
- [ ] Congelar deploys ate estabilizacao.
- [ ] Identificar camada principal afetada: backend/frontend/realtime/db.
- [ ] Coletar evidencias: erro, horario, endpoint/rota, commit/release atual.
- [ ] Confirmar escopo: todos clientes vs tenant especifico.
- [ ] Definir estrategia: mitigacao imediata ou rollback direto.
- [ ] Registrar linha do tempo (quem, o que, quando).

## 7. Runbook por Camada

### 7.1 Backend (Laravel)

Sintomas comuns:
- 5xx elevado, timeout, falha de login/autorizacao.
- Falha em jobs/queue.
- Erros de migration/schema.

Diagnostico rapido:
- Verificar logs de aplicacao.
- Verificar status da fila/jobs.
- Validar conectividade com banco/Redis.

Rollback rapido:
1. Reverter para release/tag anterior estavel do backend.
2. Limpar cache de configuracao/rotas/views da release revertida.
3. Reiniciar processo PHP-FPM/worker/queue conforme stack de deploy.

Checklist de validacao:
- [ ] `GET /api/health` (ou endpoint de sanidade equivalente) responde 200.
- [ ] Login e uma rota autenticada principal funcionando.
- [ ] Jobs novos processando sem erro.
- [ ] Erros 5xx voltaram ao baseline.

### 7.2 Frontend (React/Vite)

Sintomas comuns:
- Tela branca, assets 404, erro de bundle.
- Fluxos principais quebrados apos deploy.

Diagnostico rapido:
- Validar carregamento de `index.html` e assets versionados.
- Verificar erros JS no console e logs de CDN/proxy.

Rollback rapido:
1. Restaurar build estavel anterior no servidor/CDN.
2. Invalidate cache de CDN para remover assets quebrados.
3. Confirmar compatibilidade com backend atual (rotas/env).

Checklist de validacao:
- [ ] Login abre e autentica.
- [ ] Tela de inbox/carregamento principal abre sem erro.
- [ ] Sem 404 de assets criticos.
- [ ] Sem erro JS bloqueante no carregamento.

### 7.3 WebSocket/Realtime (Node.js + Socket.IO)

Sintomas comuns:
- Queda de notificacoes em tempo real.
- Reconexao infinita no frontend.
- Falha no `conversation.join`.

Diagnostico rapido:
- Verificar endpoint `GET /health` do realtime.
- Confirmar conexao Redis e assinatura de canal (`*realtime.events`).
- Validar segredos sincronizados:
  - `REALTIME_JWT_SECRET`
  - `REALTIME_INTERNAL_KEY`

Rollback rapido:
1. Reverter container/processo realtime para versao anterior estavel.
2. Reiniciar com mesma configuracao valida de JWT/internal key.
3. Confirmar que backend continua publicando eventos para canal correto.

Checklist de validacao:
- [ ] `/health` responde 200.
- [ ] Cliente conecta via websocket sem loop de reconnect.
- [ ] Evento de teste chega no room esperado.
- [ ] Erros de auth/token no socket voltam ao baseline.

### 7.4 Banco de Dados (MySQL)

Sintomas comuns:
- Erro de migration.
- Query lenta generalizada.
- Risco de perda/corrupcao de dados.

Diagnostico rapido:
- Verificar ultimas migrations executadas.
- Verificar locks, conexoes, latencia e consumo de recursos.
- Confirmar integridade das tabelas criticas afetadas.

Rollback rapido (dados):
1. Interromper escritas criticas (modo manutencao parcial, se necessario).
2. Gerar backup de estado atual antes de qualquer restauracao.
3. Restaurar backup valido mais recente (`backups/backup_YYYY-MM-DD_HH-MM.sql.gz`) se houver corrupcao confirmada.
4. Reaplicar apenas migracoes seguras e validadas.

Checklist de validacao:
- [ ] Conexao DB estavel.
- [ ] Tabelas/indices criticos acessiveis.
- [ ] Fluxos de escrita e leitura funcionando.
- [ ] Integridade pos-restore validada em amostras criticas.

## 8. Comandos de Referencia (operacao)

Qualidade rapida pos-incidente (ambiente de homologacao):

```bash
powershell -ExecutionPolicy Bypass -File scripts/quality-gate.ps1
```

Backup manual:

```bash
cd backend
php artisan backup:database
# ou
bash scripts/backup-db.sh
```

Restore (manual, quando aprovado):

```bash
gunzip -c backups/backup_YYYY-MM-DD_HH-MM.sql.gz | mysql -h <DB_HOST> -u <DB_USERNAME> -p <DB_DATABASE>
```

## 9. Criterios de Saida do Incidente

- [ ] Sintoma original nao ocorre mais por periodo minimo acordado.
- [ ] Monitoria estabilizada (erro, latencia, disponibilidade).
- [ ] Stakeholders informados com status final.
- [ ] RCA preliminar registrada em ate 24h.
- [ ] Acoes preventivas criadas com dono e prazo.

## 10. Modelo de Comunicacao de Emergencia

Status inicial:
- `Incidente`: [ID/Titulo]
- `Severidade`: [SEV-x]
- `Impacto`: [quem/quanto]
- `Escopo`: [backend/frontend/realtime/db]
- `Acao atual`: [mitigando/rollback]
- `Proxima atualizacao`: [HH:MM]

Status de encerramento:
- `Causa raiz preliminar`: [resumo tecnico]
- `Acao aplicada`: [rollback/fix]
- `Resultado`: [servico restaurado]
- `Pendencias`: [RCA final, hardening, testes, monitoria]

