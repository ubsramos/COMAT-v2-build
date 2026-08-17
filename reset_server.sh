#!/bin/bash
# ==============================================================================
# SCRIPT DE RESET TOTAL E DESINSTALACAO DE PACOTES — COMAT v2.0
# Remove containers, desinstala MySQL, desinstala Docker e zera o servidor
# ==============================================================================

set -e

RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
GREEN='\033[0;32m'
BOLD='\033[1m'
NC='\033[0m'

echo -e "${RED}${BOLD}"
echo "=============================================================================="
echo "          RESTAURACAO TOTAL: DESINSTALACAO COMPLETA (DOCKER + MYSQL)          "
echo "=============================================================================="
echo -e "${NC}"

if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[ERRO] Execute este script como ROOT ou via SUDO.${NC}"
  echo "Exemplo: sudo bash reset_server.sh"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

export DEBIAN_FRONTEND=noninteractive

echo -e "${CYAN}[1/6] Parando e removendo todos os containers e volumes Docker...${NC}"
if command -v docker >/dev/null 2>&1; then
  docker compose -f "$SCRIPT_DIR/docker-compose.yml" down -v --remove-orphans 2>/dev/null || true
  docker rm -f $(docker ps -aq) 2>/dev/null || true
  docker volume prune -f 2>/dev/null || true
fi

echo -e "${CYAN}[2/6] Limpando configuracoes de Reverse Proxy do Nginx Host, Systemd e Crontab...${NC}"
rm -f /etc/nginx/sites-available/comat_v2.conf
rm -f /etc/nginx/sites-enabled/comat_v2.conf
systemctl reload nginx 2>/dev/null || true

# Limpa servico customizado do systemd e agendamento cron
systemctl disable comat-app.service 2>/dev/null || true
rm -f /etc/systemd/system/comat-app.service 2>/dev/null || true
systemctl daemon-reload 2>/dev/null || true
(crontab -l 2>/dev/null | grep -v "auto_check_update.sh") | crontab - 2>/dev/null || true
rm -f /var/log/comat_update.log 2>/dev/null || true

echo -e "${CYAN}[3/6] Desinstalando e expurgando pacotes do Docker...${NC}"
systemctl stop docker.socket 2>/dev/null || true
systemctl stop docker 2>/dev/null || true
systemctl stop containerd 2>/dev/null || true

apt-get purge -y \
  docker.io \
  docker-compose \
  docker-compose-v2 \
  docker-ce \
  docker-ce-cli \
  containerd.io \
  docker-buildx-plugin \
  docker-compose-plugin \
  podman-docker \
  runc 2>/dev/null || true

rm -rf /var/lib/docker
rm -rf /var/lib/containerd
rm -rf /etc/docker
rm -rf /etc/apt/sources.list.d/docker.list
rm -rf /etc/apt/keyrings/docker.gpg
echo -e "${GREEN}[OK] Docker e dados residuais desinstalados com sucesso.${NC}"

echo -e "${CYAN}[4/6] Desinstalando e expurgando MySQL Server e banco de dados...${NC}"
systemctl stop mysql 2>/dev/null || true

apt-get purge -y \
  mysql-server \
  mysql-client \
  mysql-common \
  mysql-server-core-* \
  mysql-client-core-* \
  mariadb-server \
  mariadb-client 2>/dev/null || true

rm -rf /var/lib/mysql
rm -rf /etc/mysql
rm -rf /var/log/mysql
echo -e "${GREEN}[OK] MySQL Server e todas as bases de dados desinstaladas.${NC}"

echo -e "${CYAN}[5/6] Executando autoremove e limpeza de dependencias do Ubuntu...${NC}"
apt-get autoremove --purge -y
apt-get clean

echo -e "${CYAN}[6/6] Limpando arquivos de credenciais e restaurando configuracoes...${NC}"
rm -f "$SCRIPT_DIR/credenciais_instalacao.txt"
rm -f "$SCRIPT_DIR/credenciais_banco.txt"
rm -rf "$SCRIPT_DIR/app/backend/uploads"/*

cat <<EOF > "$SCRIPT_DIR/.env.production"
DATABASE_URL=mysql://comat_user:SENHA_AQUI@host.docker.internal:3306/comat_db
SECRET_KEY=comat-v2-production-jwt-security-key-2026
ALGORITHM=HS256
ACCESS_TOKEN_EXPIRE_MINUTES=480
UPLOAD_DIR=/var/www/html/backend/uploads
APP_NAME=COMAT
APP_VERSION=2.1
APP_DOCKER_PORT=8033
EOF

echo -e "\n${GREEN}${BOLD}"
echo "=============================================================================="
echo "          SERVIDOR 100% LIMPO E RESTAURADO AO ESTADO VIRGEM!                  "
echo "          Docker e MySQL foram completamente desinstalados.                   "
echo "          Voce pode rodar novamente: sudo bash install_server.sh              "
echo "=============================================================================="
echo -e "${NC}"
