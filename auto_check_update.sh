#!/bin/bash
# ==============================================================================
# SCRIPT DE ATUALIZACAO AUTOMATICA EM BACKGROUND (CRONTAB) — COMAT v2
# ==============================================================================
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR" || exit 1

BUILD_REPO="https://github.com/ubsramos/COMAT-v2-build.git"

# Garante que o diretorio e um repositorio git configurado
if [ ! -d ".git" ]; then
    git init -b main >/dev/null 2>&1 || git init >/dev/null 2>&1 || true
    git remote add origin "$BUILD_REPO" >/dev/null 2>&1 || git remote set-url origin "$BUILD_REPO" >/dev/null 2>&1 || true
else
    git remote set-url origin "$BUILD_REPO" >/dev/null 2>&1 || true
fi

# Executa fetch do GitHub
git fetch origin main >/dev/null 2>&1 || exit 0

LOCAL_HASH=$(git rev-parse HEAD 2>/dev/null || echo "SEM_VERSAO_LOCAL")
REMOTE_HASH=$(git rev-parse origin/main 2>/dev/null || echo "")

if [ -n "$REMOTE_HASH" ] && [ "$LOCAL_HASH" != "$REMOTE_HASH" ]; then
    echo "=============================================================================="
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] NOVA VERSAO DETECTADA NO GITHUB!"
    echo "Versao Local:  $LOCAL_HASH"
    echo "Versao Remota: $REMOTE_HASH"
    echo "Atualizando aplicacao e recarregando Docker..."
    
    # Puxa os novos arquivos forcando sobreescrita limpa
    git reset --hard origin/main >/dev/null 2>&1 || git pull origin main >/dev/null 2>&1 || true
    git branch -M main >/dev/null 2>&1 || true
    git branch --set-upstream-to=origin/main main >/dev/null 2>&1 || true
    
    # Recarrega o container Docker
    if docker compose version >/dev/null 2>&1; then
        docker compose up -d --build --remove-orphans
        docker compose exec -T app php /var/www/html/api/db_migrate.php >/dev/null 2>&1 || true
    else
        docker-compose up -d --build --remove-orphans
        docker-compose exec -T app php /var/www/html/api/db_migrate.php >/dev/null 2>&1 || true
    fi
    
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Atualizacao para versao $REMOTE_HASH concluida com sucesso!"
    echo "=============================================================================="
else
    # Se executado interativamente no terminal, exibe status
    if [ -t 1 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] O sistema ja esta na versao mais recente ($LOCAL_HASH)."
    fi
fi
