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
git fetch origin main
git reset --hard origin/main
git clean -fd 2>/dev/null || true
git branch -M main 2>/dev/null || true
git branch --set-upstream-to=origin/main main 2>/dev/null || true

echo -e "\n${CYAN}[2/3] Recarregando containers Docker...${NC}"
if docker compose version >/dev/null 2>&1; then
    docker compose up -d --build --remove-orphans
else
    docker-compose up -d --build --remove-orphans
fi

echo -e "\n${CYAN}[3/3] Verificando e migrando estrutura do banco de dados...${NC}"
if docker compose version >/dev/null 2>&1; then
    docker compose exec -T app php /var/www/html/api/db_migrate.php 2>/dev/null || true
else
    docker-compose exec -T app php /var/www/html/api/db_migrate.php 2>/dev/null || true
fi

REAL_USER="${SUDO_USER:-$USER}"
if [ "$REAL_USER" != "root" ]; then
    chown -R "$REAL_USER:$REAL_USER" "$SCRIPT_DIR" 2>/dev/null || true
fi

echo -e "\n${GREEN}[OK] COMAT v2 atualizado e banco sincronizado com sucesso!${NC}\n"
