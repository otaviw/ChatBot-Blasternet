# ╔══════════════════════════════════════════════════════════════════════════════
# ║  Makefile — ChatBot-Blasternet
# ║
# ║  Requer: GNU Make 3.81+, PHP 8.3+, Composer, Node.js 20+, npm
# ║
# ║  Linux / macOS: funciona diretamente com `make <target>`
# ║  Windows      : use Git Bash ou WSL (cmd.exe não suporta `&` e `wait`)
# ║                 Instale Make via: winget install GnuWin32.Make
# ╚══════════════════════════════════════════════════════════════════════════════

# ── Caminhos internos ─────────────────────────────────────────────────────────
B   := backend
FE  := frontend
RT  := realtime
ART := php $(B)/artisan

# ── Target padrão quando `make` é chamado sem argumentos ──────────────────────
.DEFAULT_GOAL := help

.PHONY: help setup dev backend frontend realtime test lint migrate fresh queue workers

# ══════════════════════════════════════════════════════════════════════════════
# HELP  — gerado automaticamente a partir dos comentários "## "
# ══════════════════════════════════════════════════════════════════════════════

help: ## Lista todos os comandos disponíveis
	@echo ""
	@echo "  ChatBot-Blasternet — uso: make <target>"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'
	@echo ""
	@echo "  Portas: backend=8000  frontend=5173  realtime=8081"
	@echo ""

# ══════════════════════════════════════════════════════════════════════════════
# SETUP — configuração inicial (primeiro uso)
# ══════════════════════════════════════════════════════════════════════════════

setup: ## Instala dependências, cria .env, migra e gera secrets (primeiro uso)
	@echo ""
	@echo "  [1/5] Backend — composer install"
	@echo ""
	cd $(B) && composer install
	@echo ""
	@echo "  [2/5] Backend — .env + APP_KEY + secrets"
	@echo ""
	@if [ ! -f $(B)/.env ]; then \
		cp $(B)/.env.example $(B)/.env; \
		$(ART) key:generate --ansi; \
		$(ART) app:generate-secrets --write; \
	else \
		echo "  $(B)/.env já existe — pulando geração de chaves."; \
	fi
	@echo ""
	@echo "  [3/5] Backend — migrations"
	@echo ""
	$(ART) migrate --force --ansi
	@echo ""
	@echo "  [4/5] Frontend — npm install"
	@echo ""
	cd $(FE) && npm install
	@echo ""
	@echo "  [5/5] Realtime — npm install + .env"
	@echo ""
	cd $(RT) && npm install
	@if [ ! -f $(RT)/.env ]; then \
		cp $(RT)/.env.example $(RT)/.env; \
		echo "  $(RT)/.env criado a partir do .env.example."; \
	else \
		echo "  $(RT)/.env já existe — pulando."; \
	fi
	@echo ""
	@echo "  ✓ Setup concluído. Próximo passo: make dev"
	@echo ""

# ══════════════════════════════════════════════════════════════════════════════
# SERVIÇOS — iniciar cada componente
# ══════════════════════════════════════════════════════════════════════════════

dev: ## Inicia backend + frontend + realtime em paralelo (requer bash)
	@echo "  Iniciando serviços:"
	@echo "    backend  → http://localhost:8000"
	@echo "    frontend → http://localhost:5173"
	@echo "    realtime → http://localhost:8081"
	@echo "  Ctrl+C para encerrar todos."
	@echo ""
	@$(ART) config:clear --ansi > /dev/null 2>&1; \
	cd $(B) && composer run dev & \
	cd $(FE) && npm run dev & \
	cd $(RT) && npm run dev & \
	wait

backend: ## Laravel serve + queue + logs via composer (porta 8000)
	$(ART) config:clear --ansi
	cd $(B) && composer run dev

frontend: ## Vite dev server do React (porta 5173)
	cd $(FE) && npm run dev

realtime: ## Node.js Socket.io com hot-reload (porta 8081)
	cd $(RT) && npm run dev

queue: ## Queue listener do Laravel em modo desenvolvimento
	$(ART) queue:listen --tries=3 --timeout=90

workers: ## Queue worker + agendador em modo produção (requer bash)
	@$(ART) queue:work --tries=3 --timeout=90 --sleep=3 & \
	$(ART) schedule:work & \
	wait

# ══════════════════════════════════════════════════════════════════════════════
# QUALIDADE — testes e linters
# ══════════════════════════════════════════════════════════════════════════════

test: ## Roda todos os testes: Pest (backend) + Vitest (frontend) + node --test (realtime)
	@echo ""
	@echo "  ── Backend (Pest) ────────────────────────────────"
	@echo ""
	cd $(B) && php vendor/bin/pest --ansi
	@echo ""
	@echo "  ── Frontend (Vitest) ─────────────────────────────"
	@echo ""
	cd $(FE) && npm run test:run
	@echo ""
	@echo "  ── Realtime (node --test) ────────────────────────"
	@echo ""
	cd $(RT) && npm run test:run
	@echo ""

lint: ## Corrige estilo PHP (Pint) e verifica JS/Node (ESLint + node --check)
	@echo ""
	@echo "  ── Backend (Laravel Pint) ────────────────────────"
	@echo ""
	cd $(B) && php vendor/bin/pint --ansi
	@echo ""
	@echo "  ── Frontend (ESLint) ─────────────────────────────"
	@echo ""
	cd $(FE) && npm run lint
	@echo ""
	@echo "  ── Realtime (node --check) ───────────────────────"
	@echo ""
	cd $(RT) && npm run lint
	@echo ""

# ══════════════════════════════════════════════════════════════════════════════
# BANCO DE DADOS
# ══════════════════════════════════════════════════════════════════════════════

migrate: ## Executa migrations pendentes
	$(ART) migrate --ansi

fresh: ## ⚠ Drop + migrate:fresh + seed (apaga todos os dados locais)
	@echo "  ⚠ ATENÇÃO: todos os dados locais serão apagados."
	@echo "  Pressione Ctrl+C para cancelar ou Enter para continuar."
	@read _confirm
	$(ART) migrate:fresh --seed --ansi
