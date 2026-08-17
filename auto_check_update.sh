#!/bin/bash
# ==============================================================================
# SCRIPT DE ATUALIZACAO AUTOMATICA EM BACKGROUND (CRONTAB) — COMAT v2
# ==============================================================================
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR" || exit 1

# Configura chave SSH de deploy explicitamente se existir
if [ -f "/root/.ssh/id_comat_deploy" ]; then
    export GIT_SSH_COMMAND="ssh -i /root/.ssh/id_comat_deploy -o StrictHostKeyChecking=no"
elif [ -f "$HOME/.ssh/id_comat_deploy" ]; then
    export GIT_SSH_COMMAND="ssh -i $HOME/.ssh/id_comat_deploy -o StrictHostKeyChecking=no"
fi

# Garante que o diretorio e um repositorio git configurado
if [ ! -d ".git" ]; then
    git init -b main >/dev/null 2>&1 || true
    git remote add origin "git@github.com:ubsramos/COMAT-v2-build.git" >/dev/null 2>&1 || git remote set-url origin "git@github.com:ubsramos/COMAT-v2-build.git" >/dev/null 2>&1 || true
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
        docker compose exec -T app php /var/www/html/api/db_migrate.php >/dev/null 2>&1 || true
    else
        docker-compose up -d --build --remove-orphans
        docker-compose exec -T app php /var/www/html/api/db_migrate.php >/dev/null 2>&1 || true
    fi
    
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Atualizacao e migracao concluidas com sucesso!"
    echo "=============================================================================="
fi
