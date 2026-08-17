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

# Configura chave SSH de deploy explicitamente se existir
if [ -f "/root/.ssh/id_comat_deploy" ]; then
    export GIT_SSH_COMMAND="ssh -i /root/.ssh/id_comat_deploy -o StrictHostKeyChecking=no"
elif [ -f "$HOME/.ssh/id_comat_deploy" ]; then
    export GIT_SSH_COMMAND="ssh -i $HOME/.ssh/id_comat_deploy -o StrictHostKeyChecking=no"
fi

# Inicializa Git se a pasta foi criada via .tar.gz
if [ ! -d ".git" ]; then
    echo -e "${YELLOW}Inicializando conexao Git com o repositorio COMAT-v2-build...${NC}"
    git init -b main
    git remote add origin "git@github.com:ubsramos/COMAT-v2-build.git"
    git fetch origin main
    git reset --hard origin/main
    git branch --set-upstream-to=origin/main main
fi

echo -e "\n${CYAN}[1/3] Baixando versao compilada mais recente...${NC}"
git pull origin main

echo -e "\n${CYAN}[2/3] Recarregando containers Docker...${NC}"
docker compose up -d --build --remove-orphans || docker-compose up -d --build --remove-orphans

echo -e "\n${CYAN}[3/3] Verificando e migrando estrutura do banco de dados...${NC}"
docker compose exec -T app php /var/www/html/api/db_migrate.php 2>/dev/null || docker-compose exec -T app php /var/www/html/api/db_migrate.php 2>/dev/null || true

echo -e "\n${GREEN}[OK] COMAT v2 atualizado e banco sincronizado com sucesso!${NC}\n"
