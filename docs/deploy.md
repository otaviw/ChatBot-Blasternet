# Deploy Seguro (Produção)

Projeto: ChatBot Blasternet  
Escopo: backend (Laravel), frontend (React/Vite), realtime (Node/Socket.IO), PostgreSQL, Redis

## 1. Responsáveis mínimos

- `Release owner`: executa deploy e decide go/no-go.
- `Backend owner`: valida API, migrations, queue.
- `Frontend owner`: valida build e rotas críticas.
- `Realtime owner`: valida socket/Redis/reconexão.
- `DB owner`: valida backup e risco de migration.

## 2. Pré-check obrigatório (antes de qualquer deploy)

- [ ] Janela de deploy aprovada e comunicada.
- [ ] Último commit/tag de rollback identificado.
- [ ] Sem incidentes SEV-1/SEV-2 em aberto.
- [ ] Variáveis de ambiente revisadas (`backend/.env`, `frontend/.env`, `realtime/.env`).
- [ ] Segredos sincronizados entre backend/realtime:
  - `REALTIME_JWT_SECRET`
  - `REALTIME_INTERNAL_KEY`
- [ ] Redis e PostgreSQL operacionais.
- [ ] Quality gate local ou CI verde para a versão:
  - backend: `php artisan test`
  - frontend: `npm run check`
  - realtime: `npm run check`

## 3. Backup recomendado (obrigatório antes de migration)

No servidor de produção:

```bash
cd /var/www/html/ChatBot-Blasternet/backend
php artisan backup:database
```

Se necessário, backup adicional PostgreSQL (fora da aplicação):

```bash
export PGPASSWORD="<DB_PASSWORD>"
pg_dump -h <DB_HOST> -p <DB_PORT> -U <DB_USERNAME> -d <DB_DATABASE> -F c -f /tmp/chatbot_predeploy_$(date +%F_%H%M).dump
unset PGPASSWORD
```

## 4. Sequência de deploy (ordem segura)

### 4.1 Atualizar código

```bash
cd /var/www/html/ChatBot-Blasternet
git fetch --all --tags
git checkout <tag-ou-commit-do-release>
```

### 4.2 Backend

```bash
cd /var/www/html/ChatBot-Blasternet/backend
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
php artisan migrate --force
php artisan optimize
```

### 4.3 Frontend

```bash
cd /var/www/html/ChatBot-Blasternet/frontend
npm ci
npm run build
```

Publicar os artefatos conforme seu servidor web (Nginx/Apache/CDN).

### 4.4 Realtime

```bash
cd /var/www/html/ChatBot-Blasternet/realtime
npm ci --omit=dev
```

### 4.5 Reinício controlado de serviços

Workers (Supervisor):

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart chatbot-workers:*
sudo supervisorctl status chatbot-workers:*
```

Realtime (systemd ou processo equivalente):

```bash
sudo systemctl restart chatbot-realtime
sudo systemctl status chatbot-realtime --no-pager
```

PHP-FPM (se aplicável):

```bash
sudo systemctl reload php8.2-fpm
```

## 5. Validação pós-deploy (go/no-go)

- [ ] Backend responde sem 5xx (healthcheck e login).
- [ ] Fluxo de inbox/manual funciona.
- [ ] Envio de mensagem WhatsApp (texto) funciona.
- [ ] Webhook de status atualiza mensagem corretamente.
- [ ] Frontend abre sem erro de bundle/asset 404.
- [ ] Realtime conecta e entrega evento em conversa ativa.
- [ ] Filas processando sem crescimento anormal:
  - `php artisan queue:failed`
  - `php artisan queue:monitor redis:default redis:realtime redis:ai`

## 6. Logs para checagem imediata

- Backend Laravel: `backend/storage/logs/laravel.log`
- Supervisor workers:
  - `/var/log/supervisor/chatbot-worker-default.log`
  - `/var/log/supervisor/chatbot-worker-realtime.log`
  - `/var/log/supervisor/chatbot-worker-ai.log`
- Realtime service:
  - `journalctl -u chatbot-realtime -n 200 --no-pager`
- Web server (Nginx/Apache): erros 4xx/5xx pós release.

## 7. Critérios de abortar deploy

Abortar e executar rollback quando houver:

- erro de migration com risco de inconsistência;
- aumento sustentado de 5xx;
- falha de autenticação generalizada;
- falha crítica de envio/recebimento WhatsApp;
- falha de conexão realtime sem recuperação rápida.

## 8. Referências

- Rollback: [docs/rollback.md](./rollback.md)
- Incidentes: [docs/INCIDENT_PLAYBOOK.md](./INCIDENT_PLAYBOOK.md)
- Workers supervisor: [docs/queue-workers-supervisor.md](./queue-workers-supervisor.md)
