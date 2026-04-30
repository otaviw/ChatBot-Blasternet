# Queue Workers com Supervisor

Este guia usa o arquivo [supervisor.conf](./supervisor.conf) para subir workers de producao com filas separadas:

- `default` (tambem processa `emails`, `webhooks`, `campaigns`)
- `realtime`
- `ai`

Todos os workers estao com:

- `autostart=true`
- `autorestart=true`

## 1) Instalar configuracao

No servidor Linux:

```bash
sudo cp /var/www/html/ChatBot-Blasternet/docs/supervisor.conf /etc/supervisor/conf.d/chatbot-workers.conf
```

Se o projeto estiver em outro caminho, ajuste `directory=` e `command=` antes.

## 2) Recarregar Supervisor

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start chatbot-workers:*
sudo supervisorctl status
```

## 3) Logs

Por padrao:

- `/var/log/supervisor/chatbot-worker-default.log`
- `/var/log/supervisor/chatbot-worker-realtime.log`
- `/var/log/supervisor/chatbot-worker-ai.log`

## 4) Escala rapida

- Aumente `numprocs` em `chatbot-worker-default` para alto volume de webhook/campanhas.
- Aumente `numprocs` em `chatbot-worker-realtime` para reduzir latencia de eventos ao vivo.
- Aumente `numprocs` em `chatbot-worker-ai` para maior throughput de tarefas de IA.

Depois de qualquer ajuste:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart chatbot-workers:*
```
