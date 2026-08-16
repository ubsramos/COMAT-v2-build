#!/bin/bash
# ==============================================================================
# SCRIPT DE ATUALIZACAO AUTOMATICA EM BACKGROUND (CRONTAB) — COMAT v2
# ==============================================================================
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR" || exit 1

# Garante que o diretorio e um repositorio git configurado
if [ ! -d ".git" ]; then
    exit 0
fi

# Executa fetch silencioso
git fetch origin main >/dev/null 2>&1 || exit 0

LOCAL_HASH=$(git rev-parse HEAD 2>/dev/null)
REMOTE_HASH=$(git rev-parse origin/main 2>/dev/null)

if [ -n "$LOCAL_HASH" ] && [ -n "$REMOTE_HASH" ] && [ "$LOCAL_HASH" != "$REMOTE_HASH" ]; then
    echo "=============================================================================="
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] NOVA VERSAO DETECTADA NO GITHUB!"
    echo "Versao Local:  $LOCAL_HASH"
    echo "Versao Remota: $REMOTE_HASH"
    echo "Executando atualizacao da aplicacao..."
    
    # Puxa os novos arquivos
    git reset --hard origin/main >/dev/null 2>&1 || git pull origin main
    
    # Recarrega o container Docker
    if docker compose version >/dev/null 2>&1; then
        docker compose up -d --build --remove-orphans
    else
        docker-compose up -d --build --remove-orphans
    fi
    
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Atualizacao concluida com sucesso!"
    echo "=============================================================================="
fi
