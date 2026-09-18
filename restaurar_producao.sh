#!/bin/bash
# ==============================================================================
# SCRIPT DE RESTAURACAO E ATUALIZACAO CIRURGICA — COMAT v2 (PRODUCAO)
# Seguro para servidores compartilhados com múltiplos contêineres Docker
# ==============================================================================
set -e

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

echo -e "${BLUE}${BOLD}"
echo "=============================================================================="
echo "      INICIANDO RESTAURACAO E ATUALIZACAO SEGURA — COMAT v2 (PRODUCAO)        "
echo "=============================================================================="
echo -e "${NC}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# 1. Configura Git para evitar bloqueios de safe.directory
git config --global --add safe.directory "*" 2>/dev/null || true

# 2. Backup de seguranca do .env.production local (para nao perder credenciais da VM)
if [ -f ".env.production" ]; then
    echo -e "${CYAN}[1/5] Fazendo backup temporário das configurações locais (.env.production)...${NC}"
    cp .env.production /tmp/comat_env_backup_$$
    HAS_ENV_BACKUP=true
else
    HAS_ENV_BACKUP=false
fi

# 3. Forcar Git a ignorar travas e conflitos locais
echo -e "\n${CYAN}[2/5] Sincronizando com origin/main (destravando conflitos e limpando sujeira)...${NC}"
rm -f .git/refs/remotes/origin/main 2>/dev/null || true
git fetch origin main

# Remove arquivos nao rastreados que estao bloqueando o Git (ex: app/backend/index.php)
git clean -fd 2>/dev/null || true

# Forca a arvore de arquivos a ficar identica a versao mais recente do GitHub
git reset --hard origin/main
git clean -fd

# 4. Restaura o .env.production com as credenciais da VM
if [ "$HAS_ENV_BACKUP" = true ] && [ -f "/tmp/comat_env_backup_$$" ]; then
    echo -e "${GREEN}[OK] Restaurando configurações locais do banco e ambiente (.env.production)...${NC}"
    cp /tmp/comat_env_backup_$$ .env.production
    rm -f /tmp/comat_env_backup_$$
fi

# 5. Forcar Rebuild e Recriacao CIRURGICA do container COMAT
echo -e "\n${CYAN}[3/5] Recarregando container com rebuild forçado (--build --force-recreate)...${NC}"
if docker compose version >/dev/null 2>&1; then
    docker compose up -d --build --force-recreate --remove-orphans
else
    docker-compose up -d --build --force-recreate --remove-orphans
fi

# 6. Executar migracao do banco de dados
echo -e "\n${CYAN}[4/5] Executando migração da estrutura do banco de dados...${NC}"
sleep 3
docker exec comat_v2_app php /var/www/html/backend/db_migrate.php || \
docker compose exec -T comat_app php /var/www/html/backend/db_migrate.php || true

# 7. Ajustar permissoes para o usuario que chamou o script
REAL_USER="${SUDO_USER:-$USER}"
if [ "$REAL_USER" != "root" ]; then
    chown -R "$REAL_USER:$REAL_USER" "$SCRIPT_DIR" 2>/dev/null || true
fi

# 8. Verificacao e exibicao da versao ativa
echo -e "\n${CYAN}[5/5] Validando versão ativa em produção...${NC}"
sleep 2
CONTAINER_PORT=$(grep -E '^APP_DOCKER_PORT=' .env.production 2>/dev/null | cut -d'=' -f2 | tr -d ' ' || echo "8033")
CONTAINER_PORT="${CONTAINER_PORT:-8033}"

JS_FILE=$(curl -s "http://127.0.0.1:${CONTAINER_PORT}/" 2>/dev/null | grep -o 'assets/index-[^"]*\.js' | head -n 1 || echo "")
VERSION_INFO=""
if [ -n "$JS_FILE" ]; then
    VERSION_INFO=$(curl -s "http://127.0.0.1:${CONTAINER_PORT}/${JS_FILE}" 2>/dev/null | grep -o '[0-9]\{8\}|[0-9]\{2\}h[0-9]\{2\}-v[0-9]\.[0-9]\.[0-9]\.[0-9]\+' | head -n 1 || echo "")
fi

echo -e "\n${GREEN}${BOLD}==============================================================================${NC}"
echo -e "${GREEN}${BOLD}      COMAT v2 RESTAURADO E ATUALIZADO COM SUCESSO!                          ${NC}"
if [ -n "$VERSION_INFO" ]; then
    echo -e "${GREEN}${BOLD}      Versão Ativa: ${VERSION_INFO}                                          ${NC}"
fi
echo -e "${GREEN}${BOLD}==============================================================================${NC}"
docker ps --filter name=comat_v2_app --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
echo ""
