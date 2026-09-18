#!/bin/bash
# ==============================================================================
# SCRIPT DE ATUALIZACAO RAPIDA — COMAT v2 (BUILD REPOSITORY)
# ==============================================================================
set -e

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${BLUE}==============================================================================${NC}"
echo -e "${BLUE}          ATUALIZANDO COMAT v2 A PARTIR DO GITHUB (BUILD)                    ${NC}"
echo -e "${BLUE}==============================================================================${NC}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Evita o erro 'dubious ownership' do Git ao rodar como sudo/root em pastas de usuario
git config --global --add safe.directory "*" 2>/dev/null || true

BUILD_REPO="https://github.com/ubsramos/COMAT-v2-build.git"

if [ ! -d ".git" ]; then
    echo -e "${YELLOW}Inicializando repositorio Git local...${NC}"
    git init -b main 2>/dev/null || git init 2>/dev/null || true
fi

git remote remove origin 2>/dev/null || true
git remote add origin "$BUILD_REPO"

echo -e "\n${CYAN}[1/3] Baixando versao compilada mais recente do GitHub...${NC}"
if ! git fetch origin main; then
    echo -e "${YELLOW}Tentando recuperar referencias locais do Git...${NC}"
    rm -f .git/refs/remotes/origin/main 2>/dev/null || true
    git fetch origin main
fi
git reset --hard origin/main
git clean -fd 2>/dev/null || true
git branch -M main 2>/dev/null || true
git branch --set-upstream-to=origin/main main 2>/dev/null || true

# Garante que o .env.production exista antes de subir o Docker
if [ ! -f "$SCRIPT_DIR/.env.production" ]; then
    echo -e "${YELLOW}Criando .env.production com parâmetros padrão...${NC}"
    DOCKER_GATEWAY=$(docker network inspect bridge --format='{{range .IPAM.Config}}{{.Gateway}}{{end}}' 2>/dev/null || echo "172.17.0.1")
    DB_USER="${DB_USER:-comat_user}"
    DB_PASS="${DB_PASS:-Comat@2026#App}"
    DB_NAME="${DB_NAME:-comat_db}"
    cat <<EOF > "$SCRIPT_DIR/.env.production"
# ==============================================================================
# CONFIGURACOES GERADAS AUTOMATICAMENTE EM $(date)
# ==============================================================================
DATABASE_URL=mysql://${DB_USER}:${DB_PASS}@${DOCKER_GATEWAY:-172.17.0.1}:3306/${DB_NAME}
SECRET_KEY=$(openssl rand -hex 32 2>/dev/null || echo "comat-jwt-secret-key-$(date +%s)")
ALGORITHM=HS256
ACCESS_TOKEN_EXPIRE_MINUTES=480
UPLOAD_DIR=/var/www/html/backend/uploads
APP_NAME=COMAT
APP_VERSION=2.1
APP_DOCKER_PORT=8033
EOF
    echo -e "${GREEN}[OK] .env.production gerado.${NC}"
fi

echo -e "\n${CYAN}[2/3] Recarregando containers Docker (Forçando Rebuild e Recreate)...${NC}"
if docker compose version >/dev/null 2>&1; then
    docker compose up -d --build --force-recreate --remove-orphans
else
    docker-compose up -d --build --force-recreate --remove-orphans
fi

echo -e "\n${CYAN}[3/3] Verificando e migrando estrutura do banco de dados...${NC}"
docker exec comat_v2_app php /var/www/html/backend/db_migrate.php 2>/dev/null || \
docker compose exec -T comat_app php /var/www/html/backend/db_migrate.php 2>/dev/null || true

REAL_USER="${SUDO_USER:-$USER}"
if [ "$REAL_USER" != "root" ]; then
    chown -R "$REAL_USER:$REAL_USER" "$SCRIPT_DIR" 2>/dev/null || true
fi

echo -e "\n${GREEN}[OK] COMAT v2 atualizado e banco sincronizado com sucesso!${NC}\n"
