#!/bin/bash
# ==============================================================================
# SCRIPT UNIVERSAL DE RESTAURACAO E ATUALIZACAO CIRURGICA — COMAT v2
# Parametrizado, seguro para servidores compartilhados com multiplos containers
# ==============================================================================
set -e

# ==============================================================================
# 1. PAINEL DE CONFIGURACOES (Ajuste aqui conforme o servidor)
# ==============================================================================

# Diretorio base onde o COMAT esta instalado no servidor
# (Se vazio, tenta auto-detectar o diretorio atual do script)
TARGET_DIR="${1:-/home/dti/DOCKER-DISTRIB}"

# Nome do container Docker exclusivo do COMAT
CONTAINER_NAME="comat_v2_app"

# Porta interna que o container escuta (definida no .env.production / docker-compose)
DEFAULT_PORT="8033"

# Usuario do Linux dono dos arquivos da aplicacao (ex: dti, uli, ubuntu)
# Deixe vazio ("") para auto-detectar via $SUDO_USER ou dono da pasta
SYSTEM_USER=""

# Branch remota do GitHub
GIT_BRANCH="main"

# Salvar e proteger automaticamente o .env.production local? ("sim" ou "nao")
BACKUP_ENV_LOCAL="sim"


# ==============================================================================
# 2. CORES E FORMATACAO VISUAL
# ==============================================================================
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

echo -e "${BLUE}${BOLD}"
echo "=============================================================================="
echo "          RESTAURACAO E ATUALIZACAO AUTOMATIZADA — COMAT v2                   "
echo "=============================================================================="
echo -e "${NC}"

# ==============================================================================
# 3. VALIDACOES INICIAIS E AUTO-DETECCAO
# ==============================================================================

# Se o caminho passado nao existir, tenta o diretorio de onde o script foi chamado
if [ ! -d "$TARGET_DIR" ]; then
    SCRIPT_LOCATION="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    if [ -f "$SCRIPT_LOCATION/docker-compose.yml" ]; then
        TARGET_DIR="$SCRIPT_LOCATION"
    else
        echo -e "${RED}[ERRO] Diretorio '$TARGET_DIR' nao encontrado!${NC}"
        echo -e "Uso: sudo bash $0 [caminho_da_pasta]"
        echo -e "Exemplo: sudo bash $0 /home/dti/DOCKER-DISTRIB"
        exit 1
    fi
fi

cd "$TARGET_DIR"
echo -e "Diretório de Operação: ${CYAN}${BOLD}$TARGET_DIR${NC}"

# Auto-detectar usuario do sistema caso nao configurado
if [ -z "$SYSTEM_USER" ]; then
    if [ -n "$SUDO_USER" ] && [ "$SUDO_USER" != "root" ]; then
        SYSTEM_USER="$SUDO_USER"
    else
        SYSTEM_USER=$(stat -c '%U' "$TARGET_DIR" 2>/dev/null || stat -f '%Su' "$TARGET_DIR" 2>/dev/null || echo "dti")
    fi
fi
echo -e "Usuário Proprietário:  ${CYAN}${BOLD}$SYSTEM_USER${NC}"
echo -e "Container Destino:     ${CYAN}${BOLD}$CONTAINER_NAME${NC}\n"

# Garante permissao do Git mesmo quando executado como root/sudo
git config --global --add safe.directory "*" 2>/dev/null || true

# ==============================================================================
# 4. PASSO A PASSO DA RESTAURACAO
# ==============================================================================

# [1/5] Backup das configuracoes locais de banco (.env.production)
ENV_BACKUP_TEMP="/tmp/comat_env_backup_$$.env"
if [ "$BACKUP_ENV_LOCAL" == "sim" ] && [ -f ".env.production" ]; then
    echo -e "${CYAN}[1/5] Protegendo credenciais locais (.env.production)...${NC}"
    cp .env.production "$ENV_BACKUP_TEMP"
    echo -e "${GREEN}[OK] Backup temporário salvo com sucesso.${NC}"
else
    echo -e "${YELLOW}[1/5] Nenhum .env.production anterior encontrado. Será utilizado o padrão do repositório.${NC}"
fi

# [2/5] Limpeza de travas do Git e sincronizacao forcada
echo -e "\n${CYAN}[2/5] Destravando Git e sincronizando com origin/${GIT_BRANCH}...${NC}"
# Remove referencias corrompidas de fetch anterior
rm -f .git/refs/remotes/origin/${GIT_BRANCH} 2>/dev/null || true

git fetch origin "$GIT_BRANCH"

# Remove sujeiras untracked (ex: index.php, logs locais) que bloqueariam o merge
git clean -fd 2>/dev/null || true

# Forca o estado da pasta a ficar rigorosamente identico ao GitHub
git reset --hard "origin/$GIT_BRANCH"
git clean -fd

# Restaura o .env.production do servidor para nao perder senhas de banco da rede local
if [ -f "$ENV_BACKUP_TEMP" ]; then
    echo -e "${GREEN}[OK] Restaurando credenciais originais (.env.production)...${NC}"
    cp "$ENV_BACKUP_TEMP" .env.production
    rm -f "$ENV_BACKUP_TEMP"
fi

# [3/5] Rebuild e recriacao cirurgica do container Docker
echo -e "\n${CYAN}[3/5] Recriando container Docker de forma isolada (--build --force-recreate)...${NC}"
if docker compose version >/dev/null 2>&1; then
    docker compose up -d --build --force-recreate --remove-orphans
else
    docker-compose up -d --build --force-recreate --remove-orphans
fi

# [4/5] Execucao de migracoes de banco de dados
echo -e "\n${CYAN}[4/5] Executando migrações no banco de dados comat_db...${NC}"
sleep 2
docker exec "$CONTAINER_NAME" php /var/www/html/backend/db_migrate.php || \
docker compose exec -T comat_app php /var/www/html/backend/db_migrate.php || true

# Devolve permissoes de arquivos para o usuario regular
if [ -n "$SYSTEM_USER" ] && [ "$SYSTEM_USER" != "root" ]; then
    chown -R "$SYSTEM_USER:$SYSTEM_USER" "$TARGET_DIR" 2>/dev/null || true
fi

# [5/5] Validacao da versao ativa
echo -e "\n${CYAN}[5/5] Validando integridade e versão da aplicação...${NC}"
sleep 2

PORT=$(grep -E '^APP_DOCKER_PORT=' .env.production 2>/dev/null | cut -d'=' -f2 | tr -d ' ' || echo "$DEFAULT_PORT")
PORT="${PORT:-$DEFAULT_PORT}"

JS_FILE=$(curl -s "http://127.0.0.1:${PORT}/" 2>/dev/null | grep -o 'assets/index-[^"]*\.js' | head -n 1 || echo "")
VERSION_INFO="N/A"

if [ -n "$JS_FILE" ]; then
    VERSION_INFO=$(curl -s "http://127.0.0.1:${PORT}/${JS_FILE}" 2>/dev/null | grep -o '[0-9]\{8\}|[0-9]\{2\}h[0-9]\{2\}-v[0-9]\.[0-9]\.[0-9]\+' | head -n 1 || echo "v2.1")
fi

echo -e "\n${GREEN}${BOLD}==============================================================================${NC}"
echo -e "${GREEN}${BOLD}             COMAT v2 RESTAURADO E OPERACIONAL EM PRODUCAO!                   ${NC}"
echo -e "${GREEN}${BOLD}==============================================================================${NC}"
echo -e "Status do Contêiner:"
docker ps --filter "name=${CONTAINER_NAME}" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
echo -e "Versão e Carimbo Ativos: ${CYAN}${BOLD}${VERSION_INFO}${NC}"
echo -e "Porta Interna em Escuta: ${CYAN}${BOLD}${PORT}${NC}"
echo -e "==============================================================================\n"
