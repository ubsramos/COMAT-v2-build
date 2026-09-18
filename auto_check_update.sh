#!/bin/bash
# ==============================================================================
# SCRIPT DE ATUALIZACAO AUTOMATICA EM BACKGROUND (CRONTAB) — COMAT v2
# ==============================================================================
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:$PATH"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR" || exit 1

# Evita o erro 'dubious ownership' do Git no crontab
git config --global --add safe.directory "*" 2>/dev/null || true

BUILD_REPO="https://github.com/ubsramos/COMAT-v2-build.git"

if [ ! -d ".git" ]; then
    git init -b main >/dev/null 2>&1 || git init >/dev/null 2>&1 || true
fi

git remote remove origin >/dev/null 2>&1 || true
git remote add origin "$BUILD_REPO" >/dev/null 2>&1 || true

# Executa fetch do GitHub com auto-recuperacao contra refs corrompidas
if ! git fetch origin main >/dev/null 2>&1; then
    rm -f .git/refs/remotes/origin/main 2>/dev/null || true
    git fetch origin main >/dev/null 2>&1 || exit 0
fi

LOCAL_HASH=$(git rev-parse HEAD 2>/dev/null || echo "SEM_VERSAO_LOCAL")
REMOTE_HASH=$(git rev-parse origin/main 2>/dev/null || echo "")

if [ -n "$REMOTE_HASH" ] && [ "$LOCAL_HASH" != "$REMOTE_HASH" ]; then
    echo "=============================================================================="
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] NOVA VERSAO DETECTADA NO GITHUB!"
    echo "Versao Local:  $LOCAL_HASH"
    echo "Versao Remota: $REMOTE_HASH"
    echo "Atualizando aplicacao e recarregando Docker..."
    
    # Puxa os novos arquivos forcando sobreescrita limpa
    git reset --hard origin/main >/dev/null 2>&1 || true
    git clean -fd >/dev/null 2>&1 || true
    git branch -M main >/dev/null 2>&1 || true
    git branch --set-upstream-to=origin/main main >/dev/null 2>&1 || true
    
    # Garante que o .env.production exista antes de subir o Docker
    if [ ! -f "$SCRIPT_DIR/.env.production" ]; then
        DOCKER_GATEWAY=$(docker network inspect bridge --format='{{range .IPAM.Config}}{{.Gateway}}{{end}}' 2>/dev/null || echo "172.17.0.1")
        DB_USER="${DB_USER:-comat_user}"
        DB_PASS="${DB_PASS:-Comat@2026#App}"
        DB_NAME="${DB_NAME:-comat_db}"
        cat <<EOF > "$SCRIPT_DIR/.env.production"
DATABASE_URL=mysql://${DB_USER}:${DB_PASS}@${DOCKER_GATEWAY:-172.17.0.1}:3306/${DB_NAME}
SECRET_KEY=$(openssl rand -hex 32 2>/dev/null || echo "comat-jwt-secret-key-$(date +%s)")
ALGORITHM=HS256
ACCESS_TOKEN_EXPIRE_MINUTES=480
UPLOAD_DIR=/var/www/html/backend/uploads
APP_NAME=COMAT
APP_VERSION=2.1
APP_DOCKER_PORT=8033
EOF
    fi
    
    # Recarrega o container Docker
    if docker compose version >/dev/null 2>&1; then
        docker compose up -d --build --remove-orphans
    else
        docker-compose up -d --build --remove-orphans
    fi
    docker exec comat_v2_app php /var/www/html/backend/db_migrate.php >/dev/null 2>&1 || \
    docker compose exec -T comat_app php /var/www/html/backend/db_migrate.php >/dev/null 2>&1 || true
    
    REAL_USER="${SUDO_USER:-$USER}"
    if [ "$REAL_USER" != "root" ]; then
        chown -R "$REAL_USER:$REAL_USER" "$SCRIPT_DIR" 2>/dev/null || true
    fi
    
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Atualizacao para versao $REMOTE_HASH concluida com sucesso!"
    echo "=============================================================================="
else
    # Se executado interativamente no terminal, exibe status
    if [ -t 1 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] O sistema ja esta na versao mais recente ($LOCAL_HASH)."
    fi
fi
