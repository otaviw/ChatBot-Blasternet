# Rollback Seguro (Produção)

Projeto: ChatBot Blasternet  
Uso: rollback de release com falha funcional ou operacional

## 1. Quando iniciar rollback

Inicie rollback quando houver pelo menos um cenário:

- erro crítico após deploy (SEV-1/SEV-2);
- falha de login, API 5xx ou filas paradas;
- falha crítica no envio/recebimento WhatsApp;
- regressão que impacta cliente sem workaround seguro.

## 2. Pré-rollback

- [ ] Definir `Incident Commander`.
- [ ] Congelar novos deploys.
- [ ] Registrar versão atual e versão alvo (tag/commit anterior).
- [ ] Preservar evidências de falha (logs, horário, endpoint, stacktrace).
- [ ] Confirmar backup pré-deploy disponível.

## 3. Rollback de código (backend/frontend/realtime)

```bash
cd /var/www/html/ChatBot-Blasternet
git fetch --all --tags
git checkout <tag-ou-commit-estavel-anterior>
```

### 3.1 Backend após voltar código

```bash
cd /var/www/html/ChatBot-Blasternet/backend
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
php artisan optimize
```

### 3.2 Frontend após voltar código

```bash
cd /var/www/html/ChatBot-Blasternet/frontend
npm ci
npm run build
```

### 3.3 Realtime após voltar código

```bash
cd /var/www/html/ChatBot-Blasternet/realtime
npm ci --omit=dev
```

### 3.4 Reiniciar serviços

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart chatbot-workers:*
sudo systemctl restart chatbot-realtime
sudo systemctl reload php8.2-fpm
```

## 4. Rollback de migration (apenas quando seguro)

## 4.1 Regra

Só execute rollback de migration quando:

- a migration é explicitamente reversível (`down()` confiável);
- o impacto de dados foi avaliado pelo `DB owner`;
- existe backup válido para restauração se necessário.

## 4.2 Comando de rollback Laravel

```bash
cd /var/www/html/ChatBot-Blasternet/backend
php artisan migrate:rollback --step=1 --force
```

Use `--step` de forma incremental, nunca rollback massivo sem validação.

## 4.3 Quando migration não for reversível

Se a migration remove coluna/tabela, altera tipo com perda, ou mistura DDL+DML sem caminho de volta:

- **não** force `migrate:rollback`;
- execute rollback apenas de código;
- estabilize operação;
- planeje correção com migration forward;
- em caso de corrupção/perda, restaurar backup com aprovação do responsável de banco.

## 5. Restauração de banco (cenário extremo)

Se rollback de código não resolve por inconsistência de dados:

1. Colocar sistema em modo de contenção (reduzir escrita crítica).
2. Fazer backup do estado atual antes de restaurar.
3. Restaurar backup pré-deploy aprovado.
4. Validar integridade dos fluxos críticos.

## 6. Checklist pós-rollback

- [ ] Healthcheck/API estável.
- [ ] Login funcionando.
- [ ] Inbox/manual funcionando.
- [ ] Envio WhatsApp de teste funcionando.
- [ ] Webhook de status atualizando mensagens.
- [ ] Realtime conectando e entregando eventos.
- [ ] Filas sem acúmulo anormal.
- [ ] Logs sem erro crítico repetitivo.

## 7. Logs e sinais para confirmar recuperação

- `backend/storage/logs/laravel.log`
- `/var/log/supervisor/chatbot-worker-default.log`
- `/var/log/supervisor/chatbot-worker-realtime.log`
- `/var/log/supervisor/chatbot-worker-ai.log`
- `journalctl -u chatbot-realtime -n 200 --no-pager`
- logs de Nginx/Apache (5xx e latência)

## 8. Checklist de incidente após rollback

- [ ] Registrar linha do tempo (início, mitigação, rollback, recuperação).
- [ ] Identificar causa raiz preliminar.
- [ ] Abrir tarefas de correção definitiva.
- [ ] Definir validação obrigatória em staging antes de novo deploy.
- [ ] Comunicar encerramento e riscos residuais.

## 9. Referências

- Deploy: [docs/deploy.md](./deploy.md)
- Incidentes: [docs/INCIDENT_PLAYBOOK.md](./INCIDENT_PLAYBOOK.md)
