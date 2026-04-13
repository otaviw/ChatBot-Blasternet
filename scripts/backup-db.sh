#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# backup-db.sh — Backup do banco MySQL do ChatBot Blasternet
#
# Uso:
#   bash scripts/backup-db.sh [/caminho/para/backend]
#
# Se o caminho não for passado, assume que o script está em scripts/ e o
# backend está em ../backend relativamente a ele.
#
# Saída:
#   - Arquivo:  backups/backup_YYYY-MM-DD_HH-MM.sql.gz
#   - Exit 0:   sucesso
#   - Exit 1:   falha
# -----------------------------------------------------------------------------

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuração de caminhos
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="${1:-"$SCRIPT_DIR/../backend"}"
ENV_FILE="$BACKEND_DIR/.env"
BACKUP_DIR="$SCRIPT_DIR/../backups"
KEEP_LAST=7

log()  { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
fail() { log "ERRO: $*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Verificações iniciais
# ---------------------------------------------------------------------------
log "Iniciando backup do banco de dados..."

[[ -f "$ENV_FILE" ]] || fail "Arquivo .env não encontrado em: $ENV_FILE"
command -v mysqldump >/dev/null 2>&1 || fail "mysqldump não encontrado. Instale o MySQL client."
command -v gzip      >/dev/null 2>&1 || fail "gzip não encontrado."

# ---------------------------------------------------------------------------
# Leitura das variáveis do .env
# ---------------------------------------------------------------------------
parse_env() {
    local key="$1"
    local default="${2:-}"
    local value
    value=$(grep -E "^${key}=" "$ENV_FILE" | head -1 | cut -d'=' -f2- | tr -d '"' | tr -d "'" | tr -d '\r')
    echo "${value:-$default}"
}

DB_CONNECTION=$(parse_env "DB_CONNECTION" "mysql")
DB_HOST=$(parse_env      "DB_HOST"       "127.0.0.1")
DB_PORT=$(parse_env      "DB_PORT"       "3306")
DB_DATABASE=$(parse_env  "DB_DATABASE"   "")
DB_USERNAME=$(parse_env  "DB_USERNAME"   "")
DB_PASSWORD=$(parse_env  "DB_PASSWORD"   "")

log "Conexão: ${DB_CONNECTION} | Host: ${DB_HOST}:${DB_PORT} | Banco: ${DB_DATABASE}"

[[ "$DB_CONNECTION" == "mysql" ]] || fail "Este script suporta apenas MySQL (DB_CONNECTION=mysql). Encontrado: $DB_CONNECTION"
[[ -n "$DB_DATABASE" ]]           || fail "DB_DATABASE não definido no .env"
[[ -n "$DB_USERNAME" ]]           || fail "DB_USERNAME não definido no .env"

# ---------------------------------------------------------------------------
# Criação do diretório de backups
# ---------------------------------------------------------------------------
mkdir -p "$BACKUP_DIR"
log "Diretório de backups: $(realpath "$BACKUP_DIR")"

# ---------------------------------------------------------------------------
# Execução do dump
# ---------------------------------------------------------------------------
TIMESTAMP=$(date '+%Y-%m-%d_%H-%M')
FILENAME="backup_${TIMESTAMP}.sql.gz"
FILEPATH="$BACKUP_DIR/$FILENAME"

log "Executando mysqldump → $FILENAME ..."

MYSQL_PWD="$DB_PASSWORD" mysqldump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USERNAME" \
    --single-transaction \
    --routines \
    --triggers \
    --set-gtid-purged=OFF \
    --no-tablespaces \
    "$DB_DATABASE" \
    | gzip > "$FILEPATH"

# Valida que o arquivo foi criado e tem tamanho > 0
[[ -s "$FILEPATH" ]] || fail "Arquivo de backup gerado está vazio ou não foi criado: $FILEPATH"

FILESIZE=$(du -sh "$FILEPATH" | cut -f1)
log "Backup gerado com sucesso: $FILENAME ($FILESIZE)"

# ---------------------------------------------------------------------------
# Rotação: manter apenas os últimos $KEEP_LAST backups
# ---------------------------------------------------------------------------
TOTAL=$(find "$BACKUP_DIR" -maxdepth 1 -name "backup_*.sql.gz" | wc -l)
log "Total de backups no diretório: $TOTAL (mantendo últimos $KEEP_LAST)"

if [[ "$TOTAL" -gt "$KEEP_LAST" ]]; then
    EXCESSO=$((TOTAL - KEEP_LAST))
    log "Removendo $EXCESSO backup(s) antigo(s)..."
    find "$BACKUP_DIR" -maxdepth 1 -name "backup_*.sql.gz" \
        | sort \
        | head -n "$EXCESSO" \
        | while read -r old_file; do
            log "  Removendo: $(basename "$old_file")"
            rm -f "$old_file"
        done
fi

log "Backup concluído com sucesso."
exit 0
