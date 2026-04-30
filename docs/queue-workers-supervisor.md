# Queue Workers com Supervisor

Este guia usa o arquivo [supervisor.conf](./supervisor.conf) para subir workers de producao com filas separadas:

- `default` (tambem processa `emails`, `webhooks`, `campaigns`)
- `realtime`
- `ai` (2 processos)

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

## 5) Comandos uteis

Status geral e por grupo:

```bash
sudo supervisorctl status
sudo supervisorctl status chatbot-workers:*
```

Reiniciar apenas uma fila:

```bash
sudo supervisorctl restart chatbot-worker-default:*
sudo supervisorctl restart chatbot-worker-realtime:*
sudo supervisorctl restart chatbot-worker-ai:*
```

Parar/iniciar grupo completo:

```bash
sudo supervisorctl stop chatbot-workers:*
sudo supervisorctl start chatbot-workers:*
```

Inspecionar logs em tempo real:

```bash
sudo tail -f /var/log/supervisor/chatbot-worker-default.log
sudo tail -f /var/log/supervisor/chatbot-worker-realtime.log
sudo tail -f /var/log/supervisor/chatbot-worker-ai.log
```

Checar backlog de filas no Laravel:

```bash
php artisan queue:monitor redis:default redis:realtime redis:ai
php artisan queue:failed
```
