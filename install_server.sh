#!/bin/bash
# ==============================================================================
# PAINEL DE CONFIGURACOES GERAIS (EDITE AQUI ANTES DE EXECUTAR)
# ==============================================================================
# Altere os parametros abaixo conforme a infraestrutura e dominio da instituicao:

DOMINIO_SISTEMA="comat.aspa.org.br"     # Dominio DNS ou Hostname da aplicacao
APP_DOCKER_PORT="8033"                  # Porta interna exclusiva do container COMAT
USAR_SSL="nao"                          # Ativar HTTPS no Nginx Host ("sim" ou "nao")
SSL_CERT_PATH="ssl/comat.crt"           # Caminho do Certificado (.crt / .pem / fullchain.pem)
SSL_KEY_PATH="ssl/comat.key"            # Caminho da Chave Privada (.key / privkey.pem)

DB_NAME="comat_db"                      # Nome do Banco de Dados
DB_USER="comat_user"                    # Nome do Usuario do Banco
DB_PASS="Comat@2026#App"                # Senha do Usuario do Banco
MYSQL_ROOT_PASS="Root@2026#Admin"       # Senha do ROOT do MySQL
SCHEMA_FILE="database/schema_comat.sql" # Caminho do dump/schema inicial do banco
RESPONSAVEL_DEPLOY="ubsramos@gmail.com" # E-mail do responsavel pelo deploy/GitHub
ATIVAR_AUTO_UPDATE="sim"                # Ativar verificacao periodica automatica no Crontab ("sim" ou "nao")
INTERVALO_UPDATE_MIN="10"               # Intervalo da verificacao em minutos (ex: 10)
REPO_BUILD_GIT="git@github.com:ubsramos/COMAT-v2-build.git" # Repositorio de Build


# ==============================================================================
# NAO E NECESSARIO ALTERAR NADA DAQUI PARA BAIXO
# ==============================================================================

set -e

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
WHITE='\033[1;37m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m' # Sem Cor

echo -e "${BLUE}${BOLD}"
echo "=============================================================================="
echo "          INICIANDO INSTALACAO AUTOMATIZADA — COMAT v2.0                      "
echo "        Arquitetura Gateway Reverse Proxy (Nginx Host + Docker)               "
echo "=============================================================================="
echo -e "${NC}"

# 1. Validacao de privilegios de superusuario (root)
if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[ERRO] Este script precisa ser executado como ROOT ou via SUDO.${NC}"
  echo "Exemplo: sudo bash install_server.sh"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Detectar IP da maquina na rede local
SERVER_IP=$(hostname -I | awk '{print $1}')
if [ -z "$SERVER_IP" ]; then
  SERVER_IP="127.0.0.1"
fi

REAL_USER=${SUDO_USER:-$USER}

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

# 1. Atualizar sistema e instalar ferramentas basicas + Nginx no Host
echo -e "${CYAN}[1/6] Atualizando pacotes e instalando Nginx e Cron no Host Ubuntu...${NC}"
apt-get update -y
apt-get install -y nginx curl wget git ufw net-tools ca-certificates gnupg lsb-release openssl cron

systemctl enable nginx || true
systemctl start nginx || true
systemctl enable cron || true
systemctl start cron || true

# 2. Instalacao e Configuracao do MySQL Server
echo -e "\n${CYAN}[2/6] Instalando e Configurando MySQL Server Nativo...${NC}"
if ! command -v mysql >/dev/null 2>&1; then
  apt-get install -y mysql-server
  systemctl enable mysql || true
  systemctl start mysql || true
else
  systemctl start mysql 2>/dev/null || true
fi

echo -e "${YELLOW}Configurando bind-address do MySQL para conexoes locais e Docker...${NC}"
if [ -f /etc/mysql/mysql.conf.d/mysqld.cnf ]; then
  sed -i 's/^bind-address\s*=.*/bind-address = 0.0.0.0/' /etc/mysql/mysql.conf.d/mysqld.cnf
  sed -i 's/^mysqlx-bind-address\s*=.*/mysqlx-bind-address = 0.0.0.0/' /etc/mysql/mysql.conf.d/mysqld.cnf 2>/dev/null || true
  systemctl restart mysql || true
fi

SQL_SETUP="ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PASS}';
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;"

if mysql -u root -e "$SQL_SETUP" 2>/dev/null; then
  echo -e "${GREEN}[OK] Banco de dados e usuarios configurados via socket root.${NC}"
elif mysql -u root -p"${MYSQL_ROOT_PASS}" -e "$SQL_SETUP" 2>/dev/null; then
  echo -e "${GREEN}[OK] Banco de dados e usuarios configurados via senha root.${NC}"
else
  mysql -e "$SQL_SETUP" 2>/dev/null || true
  echo -e "${GREEN}[OK] Banco de dados configurado.${NC}"
fi

# 3. Restauracao do Schema Inicial do COMAT
echo -e "\n${CYAN}[3/6] Restaurando Schema do Banco de Dados (${DB_NAME})...${NC}"
FULL_SCHEMA_PATH="$SCRIPT_DIR/$SCHEMA_FILE"
if [ -f "$FULL_SCHEMA_PATH" ]; then
  if mysql -u root -p"${MYSQL_ROOT_PASS}" "${DB_NAME}" < "$FULL_SCHEMA_PATH" 2>/dev/null; then
    echo -e "${GREEN}[OK] Schema e dados iniciais importados com sucesso!${NC}"
  elif mysql -u root "${DB_NAME}" < "$FULL_SCHEMA_PATH" 2>/dev/null; then
    echo -e "${GREEN}[OK] Schema e dados iniciais importados com sucesso!${NC}"
  else
    mysql -u "${DB_USER}" -p"${DB_PASS}" -h 127.0.0.1 "${DB_NAME}" < "$FULL_SCHEMA_PATH" 2>/dev/null || true
    echo -e "${GREEN}[OK] Schema restaurado.${NC}"
  fi
else
  echo -e "${RED}[AVISO] Arquivo '${SCHEMA_FILE}' nao encontrado. O banco '${DB_NAME}' foi criado vazio.${NC}"
fi

# 4. Instalacao Oficial e Limpa do Docker & Docker Compose
echo -e "\n${CYAN}[4/6] Verificando e Instalando Docker e Docker Compose...${NC}"

if ! command -v docker >/dev/null 2>&1; then
  echo -e "${YELLOW}Instalando Docker Engine via APT...${NC}"
  apt-get update -y
  apt-get install -y docker.io docker-compose-v2 || \
  apt-get install -y docker.io docker-compose || \
  apt-get install -y docker.io || true
fi

if ! command -v docker >/dev/null 2>&1; then
  echo -e "${YELLOW}Instalando Docker via script get.docker.com...${NC}"
  curl -fsSL https://get.docker.com | sh || true
fi

systemctl daemon-reload 2>/dev/null || true
systemctl unmask docker.service 2>/dev/null || true
systemctl unmask docker.socket 2>/dev/null || true
systemctl enable --now docker 2>/dev/null || true
systemctl start docker 2>/dev/null || true

if ! command -v docker >/dev/null 2>&1; then
  echo -e "${RED}[ERRO FATAL] Docker nao foi encontrado apos a instalacao.${NC}"
  exit 1
fi

echo -e "${GREEN}[OK] Docker instalado e ativo: $(docker --version)${NC}"

if ! docker compose version >/dev/null 2>&1 && command -v docker-compose >/dev/null 2>&1; then
  mkdir -p /usr/local/lib/docker/cli-plugins
  ln -sf $(which docker-compose) /usr/local/lib/docker/cli-plugins/docker-compose 2>/dev/null || true
fi

if [ "$REAL_USER" != "root" ]; then
  usermod -aG docker "$REAL_USER" 2>/dev/null || true
fi

# 5. Configuracao do Ambiente de Producao
echo -e "\n${CYAN}[5/6] Configurando .env.production da Aplicacao...${NC}"

DOCKER_GATEWAY=$(docker network inspect bridge --format='{{range .IPAM.Config}}{{.Gateway}}{{end}}' 2>/dev/null || echo "172.17.0.1")
if [ -z "$DOCKER_GATEWAY" ]; then
  DOCKER_GATEWAY="172.17.0.1"
fi

cat <<EOF > "$SCRIPT_DIR/.env.production"
# ==============================================================================
# CONFIGURACOES GERADAS AUTOMATICAMENTE EM $(date)
# ==============================================================================
DATABASE_URL=mysql://${DB_USER}:${DB_PASS}@${DOCKER_GATEWAY}:3306/${DB_NAME}
SECRET_KEY=$(openssl rand -hex 32 2>/dev/null || echo "comat-jwt-secret-key-$(date +%s)")
ALGORITHM=HS256
ACCESS_TOKEN_EXPIRE_MINUTES=480
UPLOAD_DIR=/var/www/html/backend/uploads
APP_NAME=COMAT
APP_VERSION=2.1
APP_DOCKER_PORT=${APP_DOCKER_PORT}
EOF

echo -e "${GREEN}[OK] Arquivo .env.production configurado.${NC}"

# 6. Subir Container na Porta Interna e Configurar Reverse Proxy no Nginx Host
echo -e "\n${CYAN}[6/6] Subindo Container COMAT (Porta ${APP_DOCKER_PORT}) e Configurando Nginx Host...${NC}"
mkdir -p "$SCRIPT_DIR/app/backend/uploads"
chmod -R 777 "$SCRIPT_DIR/app/backend/uploads"

# Determinar comando de compose
COMPOSE_CMD="docker compose"
if ! docker compose version >/dev/null 2>&1; then
  if command -v docker-compose >/dev/null 2>&1; then
    COMPOSE_CMD="docker-compose"
  fi
fi

# Exporta porta para o docker-compose.yml
export APP_DOCKER_PORT="${APP_DOCKER_PORT}"

echo -e "${YELLOW}Subindo container Docker na porta interna 127.0.0.1:${APP_DOCKER_PORT}...${NC}"
$COMPOSE_CMD -f "$SCRIPT_DIR/docker-compose.yml" down --remove-orphans 2>/dev/null || true
$COMPOSE_CMD -f "$SCRIPT_DIR/docker-compose.yml" up -d --build

# Criacao do Virtual Host no Nginx do Host
NGINX_SITE_CONF="/etc/nginx/sites-available/comat_v2.conf"
mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled /etc/nginx/ssl

if [ "$USAR_SSL" = "sim" ]; then
  echo -e "${YELLOW}Configurando Nginx Host com SSL/HTTPS para ${DOMINIO_SISTEMA}...${NC}"
  
  SSL_CERT="/etc/nginx/ssl/${DOMINIO_SISTEMA}.crt"
  SSL_KEY="/etc/nginx/ssl/${DOMINIO_SISTEMA}.key"

  RESOLVED_CERT=""
  RESOLVED_KEY=""

  # Busca certificado nos caminhos informados (absoluto ou relativo ao script)
  if [ -n "$SSL_CERT_PATH" ] && [ -f "$SSL_CERT_PATH" ]; then
    RESOLVED_CERT="$SSL_CERT_PATH"
  elif [ -n "$SSL_CERT_PATH" ] && [ -f "$SCRIPT_DIR/$SSL_CERT_PATH" ]; then
    RESOLVED_CERT="$SCRIPT_DIR/$SSL_CERT_PATH"
  elif [ -f "$SCRIPT_DIR/ssl/comat.crt" ]; then
    RESOLVED_CERT="$SCRIPT_DIR/ssl/comat.crt"
  fi

  # Busca chave privada nos caminhos informados
  if [ -n "$SSL_KEY_PATH" ] && [ -f "$SSL_KEY_PATH" ]; then
    RESOLVED_KEY="$SSL_KEY_PATH"
  elif [ -n "$SSL_KEY_PATH" ] && [ -f "$SCRIPT_DIR/$SSL_KEY_PATH" ]; then
    RESOLVED_KEY="$SCRIPT_DIR/$SSL_KEY_PATH"
  elif [ -f "$SCRIPT_DIR/ssl/comat.key" ]; then
    RESOLVED_KEY="$SCRIPT_DIR/ssl/comat.key"
  fi

  if [ -n "$RESOLVED_CERT" ] && [ -n "$RESOLVED_KEY" ]; then
    echo -e "${GREEN}[OK] Utilizando certificado SSL fornecido:${NC}"
    echo -e "  Certificado: ${RESOLVED_CERT}"
    echo -e "  Chave:       ${RESOLVED_KEY}"
    cp "$RESOLVED_CERT" "$SSL_CERT"
    cp "$RESOLVED_KEY" "$SSL_KEY"
    chmod 644 "$SSL_CERT"
    chmod 600 "$SSL_KEY"
  elif [ -f "$SSL_CERT" ] && [ -f "$SSL_KEY" ]; then
    echo -e "${GREEN}[OK] Utilizando certificados ja existentes em /etc/nginx/ssl/...${NC}"
  else
    echo -e "${YELLOW}Certificado nao encontrado em '${SSL_CERT_PATH}'. Gerando autoassinado para testes em ${SSL_CERT}...${NC}"
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
      -keyout "$SSL_KEY" \
      -out "$SSL_CERT" \
      -subj "/C=BR/ST=SP/L=SaoPaulo/O=ASPA/CN=${DOMINIO_SISTEMA}" 2>/dev/null || true
    chmod 644 "$SSL_CERT"
    chmod 600 "$SSL_KEY"
  fi

  cat <<EOF > "$NGINX_SITE_CONF"
# Redirecionamento HTTP -> HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMINIO_SISTEMA} ${SERVER_IP};
    return 301 https://\$host\$request_uri;
}

# Servidor HTTPS com Reverse Proxy para o Docker
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${DOMINIO_SISTEMA} ${SERVER_IP};

    ssl_certificate ${SSL_CERT};
    ssl_certificate_key ${SSL_KEY};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    client_max_body_size 64M;

    location / {
        proxy_pass http://127.0.0.1:${APP_DOCKER_PORT};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_cache_bypass \$http_upgrade;
        proxy_read_timeout 180s;
    }
}
EOF
  URL_FINAL="https://${DOMINIO_SISTEMA}"
else
  echo -e "${YELLOW}Configurando Nginx Host em HTTP para ${DOMINIO_SISTEMA} e ${SERVER_IP}...${NC}"
  cat <<EOF > "$NGINX_SITE_CONF"
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMINIO_SISTEMA} ${SERVER_IP};

    client_max_body_size 64M;

    location / {
        proxy_pass http://127.0.0.1:${APP_DOCKER_PORT};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_cache_bypass \$http_upgrade;
        proxy_read_timeout 180s;
    }
}
EOF
  URL_FINAL="http://${DOMINIO_SISTEMA}"
fi

# Habilita o site no Nginx
ln -sf "$NGINX_SITE_CONF" /etc/nginx/sites-enabled/comat_v2.conf

# Se existir o arquivo default que conflita na porta 80, desabilita
if [ -f /etc/nginx/sites-enabled/default ]; then
  rm -f /etc/nginx/sites-enabled/default
fi

# Valida sintaxe e recarrega Nginx
nginx -t
systemctl reload nginx || systemctl restart nginx

# 7. Gerar Chave SSH de Deploy Somente Leitura (GitHub Deploy Key)
echo -e "\n${CYAN}[7/7] Configurando Chave de Atualizacao Automatica (Deploy Key)...${NC}"
SSH_DIR="/root/.ssh"
mkdir -p "$SSH_DIR"
chmod 700 "$SSH_DIR"
SSH_KEY_FILE="$SSH_DIR/id_comat_deploy"

if [ ! -f "$SSH_KEY_FILE" ]; then
  ssh-keygen -t ed25519 -C "comat-deploy-$(hostname)" -f "$SSH_KEY_FILE" -N "" >/dev/null 2>&1 || true
  chmod 600 "$SSH_KEY_FILE" 2>/dev/null || true
  chmod 644 "${SSH_KEY_FILE}.pub" 2>/dev/null || true
fi

# Se executado via sudo por um usuario normal, espelha a chave para a home dele tambem
if [ -n "$SUDO_USER" ] && [ "$SUDO_USER" != "root" ]; then
  USER_HOME=$(eval echo "~$SUDO_USER")
  USER_SSH="$USER_HOME/.ssh"
  mkdir -p "$USER_SSH"
  chmod 700 "$USER_SSH"
  cp -n "$SSH_KEY_FILE" "$USER_SSH/id_comat_deploy" 2>/dev/null || true
  cp -n "${SSH_KEY_FILE}.pub" "$USER_SSH/id_comat_deploy.pub" 2>/dev/null || true
  chown -R "$SUDO_USER:$SUDO_USER" "$USER_SSH" 2>/dev/null || true
  
  if ! grep -q "id_comat_deploy" "$USER_SSH/config" 2>/dev/null; then
    cat <<EOF >> "$USER_SSH/config"
Host github.com
    IdentityFile $USER_SSH/id_comat_deploy
    StrictHostKeyChecking no
EOF
    chown "$SUDO_USER:$SUDO_USER" "$USER_SSH/config" 2>/dev/null || true
    chmod 600 "$USER_SSH/config" 2>/dev/null || true
  fi
fi

if ! grep -q "id_comat_deploy" "$SSH_DIR/config" 2>/dev/null; then
  cat <<EOF >> "$SSH_DIR/config"
Host github.com
    IdentityFile $SSH_KEY_FILE
    StrictHostKeyChecking no
EOF
  chmod 600 "$SSH_DIR/config" 2>/dev/null || true
fi

DEPLOY_KEY_PUB=$(cat "${SSH_KEY_FILE}.pub" 2>/dev/null || echo "Chave nao encontrada")

# 8. Vincular ao Repositorio de Build e Configurar Crontab Automatico
if [ ! -d "$SCRIPT_DIR/.git" ]; then
  echo -e "\n${CYAN}[8/8] Vinculando diretorio ao Repositorio de Build (${REPO_BUILD_GIT})...${NC}"
  cd "$SCRIPT_DIR"
  git init >/dev/null 2>&1 || true
  git remote add origin "$REPO_BUILD_GIT" >/dev/null 2>&1 || git remote set-url origin "$REPO_BUILD_GIT" >/dev/null 2>&1 || true
  git branch -M main >/dev/null 2>&1 || true
fi

if [ "$ATIVAR_AUTO_UPDATE" = "sim" ]; then
  echo -e "${YELLOW}Configurando agendamento automatico no Crontab (A cada ${INTERVALO_UPDATE_MIN} minutos)...${NC}"
  
  if ! command -v crontab >/dev/null 2>&1; then
    apt-get update -y >/dev/null 2>&1 || true
    apt-get install -y cron >/dev/null 2>&1 || true
    systemctl enable cron >/dev/null 2>&1 || true
    systemctl start cron >/dev/null 2>&1 || true
  fi

  chmod +x "$SCRIPT_DIR/auto_check_update.sh" 2>/dev/null || true
  chmod +x "$SCRIPT_DIR/atualizar.sh" 2>/dev/null || true
  
  touch /var/log/comat_update.log 2>/dev/null || true
  chmod 666 /var/log/comat_update.log 2>/dev/null || true
  
  CRON_ENTRY="*/${INTERVALO_UPDATE_MIN} * * * * bash $SCRIPT_DIR/auto_check_update.sh >> /var/log/comat_update.log 2>&1"
  (crontab -l 2>/dev/null | grep -v "auto_check_update.sh" ; echo "$CRON_ENTRY") | crontab -
  echo -e "${GREEN}[OK] Atualizacao automatica agendada no Crontab do sistema!${NC}"
fi

# Salvar credenciais e instrucoes em arquivo
CREDS_FILE="$SCRIPT_DIR/credenciais_instalacao.txt"
cat <<EOF > "$CREDS_FILE"
==============================================================================
           COMAT v2.0 — CREDENCIAIS DO SERVIDOR E GATEWAY REVERSE PROXY
Gerado em: $(date)
==============================================================================

[ACESSO AO SISTEMA]
URL Oficial:           ${URL_FINAL}
Acesso Direto via IP:  http://${SERVER_IP} (ou https se SSL ativo)
Porta do Container:    127.0.0.1:${APP_DOCKER_PORT} (Gerenciado pelo Nginx Host)
Modo SSL/HTTPS:        ${USAR_SSL}

[USUARIO ADMINISTRADOR PADRAO]
Usuario: admin
Senha:   admin123

==============================================================================
[INSTRUCOES DE DNS PARA A EQUIPE DE REDE / INFRAESTRUTURA]
Para acessar pelo endereco ${DOMINIO_SISTEMA}, crie uma entrada DNS do tipo A:
Tipo:   A
Nome:   ${DOMINIO_SISTEMA}
Valor:  ${SERVER_IP}

==============================================================================
[BANCO DE DADOS MYSQL NATIVO]
Host:                  127.0.0.1 (Local) / ${DOCKER_GATEWAY} (Docker Gateway)
Porta:                 3306
Database:              ${DB_NAME}
Usuario ROOT:          root (Senha: ${MYSQL_ROOT_PASS})
Usuario da Aplicacao:  ${DB_USER} (Senha: ${DB_PASS})

==============================================================================
[CHAVE DE ATUALIZACAO AUTOMATICA (GITHUB DEPLOY KEY)]
Chave Publica:
${DEPLOY_KEY_PUB}

Envie esta chave para: ${RESPONSAVEL_DEPLOY}
Para que o servidor receba atualizacoes via: bash atualizar.sh
==============================================================================
EOF
chmod 600 "$CREDS_FILE"

# Exibicao do Banner Final no Terminal
echo -e "\n${GREEN}${BOLD}"
echo "=============================================================================="
echo "        INSTALACAO E GATEWAY REVERSE PROXY CONCLUIDOS COM SUCESSO!            "
echo "=============================================================================="
echo -e "${NC}"
echo -e "${BOLD}DOMINIO CONFIGURADO:${NC}    ${CYAN}${DOMINIO_SISTEMA}${NC}"
echo -e "${BOLD}URL DE ACESSO:${NC}          ${CYAN}${URL_FINAL}${NC} (ou ${CYAN}http://${SERVER_IP}${NC})"
echo -e "${BOLD}PORTA INTERNA DOCKER:${NC}  ${YELLOW}127.0.0.1:${APP_DOCKER_PORT}${NC}"
echo -e "${BOLD}MODO SSL/HTTPS:${NC}         ${YELLOW}${USAR_SSL}${NC} (Certificados em /etc/nginx/ssl/)"
echo -e "------------------------------------------------------------------------------"
echo -e "${BOLD}USUARIO ADMIN PADRAO:${NC}   ${YELLOW}admin${NC}"
echo -e "${BOLD}SENHA ADMIN PADRAO:${NC}     ${YELLOW}admin123${NC}"
echo -e "------------------------------------------------------------------------------"
echo -e "${BOLD}BANCO DE DADOS (MySQL):${NC}  ${DB_NAME}"
echo -e "${BOLD}USUARIO APP MYSQL:${NC}      ${DB_USER}"
echo -e "${BOLD}SENHA APP MYSQL:${NC}        ${GREEN}${DB_PASS}${NC}"
echo "=============================================================================="
echo -e "${BOLD}CONFIGURACAO DE DNS NECESSARIA:${NC}"
echo -e "Apontar entrada DNS tipo A de ${CYAN}${DOMINIO_SISTEMA}${NC} para o IP ${CYAN}${SERVER_IP}${NC}"
echo "=============================================================================="

# CAIXA DESTACADA DA CHAVE DE ATUALIZACAO GITHUB
echo -e "\n${MAGENTA}${BOLD}"
echo "=============================================================================="
echo "      CHAVE DE ATUALIZACAO AUTOMATICA DO SISTEMA (GITHUB DEPLOY KEY)          "
echo "=============================================================================="
echo -e "${NC}"
echo -e "${YELLOW}${BOLD}SE VOCE DESEJA QUE ESTE SERVIDOR RECEBA ATUALIZACOES DO COMAT v2:${NC}"
echo -e "Copie a chave publica abaixo e envie para o responsavel pela atualizacao:"
echo -e "${BOLD}Responsavel / E-mail:${NC} ${CYAN}${RESPONSAVEL_DEPLOY}${NC}"
echo -e "------------------------------------------------------------------------------"
echo -e "${GREEN}${BOLD}${DEPLOY_KEY_PUB}${NC}"
echo -e "------------------------------------------------------------------------------"
echo -e "${BOLD}INSTRUCOES PARA O RESPONSAVEL / ADMINISTRADOR:${NC}"
echo -e "1. Cadastre a chave no GitHub: ${CYAN}https://github.com/ubsramos/COMAT-v2-build/settings/keys${NC}"
echo -e "2. Deixe a opcao 'Allow write access' ${YELLOW}DESMARCADA${NC} (Somente Leitura)."
echo -e "3. Apos o cadastro, para atualizar o sistema basta rodar: ${CYAN}bash atualizar.sh${NC}"
echo "=============================================================================="
echo -e "${YELLOW}As credenciais completas foram salvas em:${NC} ${CREDS_FILE}"
echo "=============================================================================="

