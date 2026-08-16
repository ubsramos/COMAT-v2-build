#!/bin/bash
# ==============================================================================
# PAINEL DE CONFIGURACOES DO BANCO DE DADOS (EDITE AQUI)
# ==============================================================================
# Altere os valores abaixo conforme a necessidade do seu ambiente:

DB_NAME="comat_db"                      # Nome do Banco de Dados a ser criado
DB_USER="comat_user"                    # Nome do Usuario do Sistema
DB_PASS="Comat@2026#App"                # Senha do Usuario do Sistema
MYSQL_ROOT_PASS="Root@2026#Admin"       # Senha que sera configurada para o ROOT do MySQL
SCHEMA_FILE="database/schema_comat.sql" # Caminho do arquivo SQL com tabelas e dados iniciais
PERMITIR_ACESSO_DOCKER="sim"            # Permitir acesso dos containers Docker ("sim" ou "nao")

# ==============================================================================
# NAO E NECESSARIO ALTERAR NADA DAQUI PARA BAIXO
# ==============================================================================

set -e

# Cores para o console
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m' # Sem Cor

echo -e "${BLUE}${BOLD}"
echo "=============================================================================="
echo "          INSTALADOR E RESTAURADOR DO BANCO DE DADOS (MySQL)                  "
echo "                             COMAT v2.0                                       "
echo "=============================================================================="
echo -e "${NC}"

# 1. Validacao de privilegios de Root
if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[ERRO] Este script precisa ser executado como ROOT ou com SUDO.${NC}"
  echo "Exemplo de uso: sudo bash setup_database.sh"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

# 2. Instalacao do MySQL Server se nao existir
echo -e "${CYAN}[1/4] Verificando instalacao do MySQL Server...${NC}"
if ! command -v mysql >/dev/null 2>&1; then
  echo -e "${YELLOW}MySQL nao encontrado. Instalando mysql-server automaticamente...${NC}"
  apt-get update -y
  apt-get install -y mysql-server
  systemctl enable mysql || true
  systemctl start mysql || true
  echo -e "${GREEN}[OK] MySQL Server instalado com sucesso.${NC}"
else
  echo -e "${GREEN}[OK] MySQL Server ja esta instalado no sistema.${NC}"
  systemctl start mysql 2>/dev/null || true
fi

# 3. Configuracao de Rede para Docker / Rede Local
if [ "$PERMITIR_ACESSO_DOCKER" = "sim" ]; then
  echo -e "\n${CYAN}[2/4] Configurando bind-address para acesso do Docker e rede local...${NC}"
  if [ -f /etc/mysql/mysql.conf.d/mysqld.cnf ]; then
    sed -i 's/^bind-address\s*=.*/bind-address = 0.0.0.0/' /etc/mysql/mysql.conf.d/mysqld.cnf
    sed -i 's/^mysqlx-bind-address\s*=.*/mysqlx-bind-address = 0.0.0.0/' /etc/mysql/mysql.conf.d/mysqld.cnf 2>/dev/null || true
    systemctl restart mysql || true
    echo -e "${GREEN}[OK] MySQL configurado para aceitar conexoes locais e via Docker.${NC}"
  fi
fi

# 4. Criacao do Banco de Dados, Usuario e Privilegios
echo -e "\n${CYAN}[3/4] Criando Banco de Dados '${DB_NAME}' e Usuario '${DB_USER}'...${NC}"

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
  echo -e "${GREEN}[OK] Banco de dados e usuario configurados via socket root.${NC}"
elif mysql -u root -p"${MYSQL_ROOT_PASS}" -e "$SQL_SETUP" 2>/dev/null; then
  echo -e "${GREEN}[OK] Banco de dados e usuario configurados via senha root.${NC}"
else
  mysql -e "$SQL_SETUP" 2>/dev/null || true
  echo -e "${GREEN}[OK] Banco de dados e usuario configurados.${NC}"
fi

# 5. Restauracao do Schema / Backup Inicial
echo -e "\n${CYAN}[4/4] Restaurando tabelas e dados iniciais (${SCHEMA_FILE})...${NC}"
FULL_SCHEMA_PATH="$SCRIPT_DIR/$SCHEMA_FILE"

if [ -f "$FULL_SCHEMA_PATH" ]; then
  if mysql -u root -p"${MYSQL_ROOT_PASS}" "${DB_NAME}" < "$FULL_SCHEMA_PATH" 2>/dev/null; then
    echo -e "${GREEN}[OK] Schema e dados restaurados com sucesso no banco '${DB_NAME}'!${NC}"
  elif mysql -u root "${DB_NAME}" < "$FULL_SCHEMA_PATH" 2>/dev/null; then
    echo -e "${GREEN}[OK] Schema e dados restaurados com sucesso no banco '${DB_NAME}'!${NC}"
  else
    mysql -u "${DB_USER}" -p"${DB_PASS}" -h 127.0.0.1 "${DB_NAME}" < "$FULL_SCHEMA_PATH" 2>/dev/null || true
    echo -e "${GREEN}[OK] Schema restaurado.${NC}"
  fi
else
  echo -e "${RED}[AVISO] Arquivo '${SCHEMA_FILE}' nao encontrado. O banco '${DB_NAME}' foi criado vazio.${NC}"
fi

# 6. Gravacao do Arquivo de Credenciais
CREDS_FILE="$SCRIPT_DIR/credenciais_banco.txt"
cat <<EOF > "$CREDS_FILE"
==============================================================================
       COMAT v2.0 — CREDENCIAIS DO BANCO DE DADOS CONFIGURADAS
Data: $(date)
==============================================================================

Banco de Dados:  ${DB_NAME}
Porta Padrao:    3306

--- ACESSO ROOT (ADMINISTRADOR MYSQL) ---
Usuario: root
Senha:   ${MYSQL_ROOT_PASS}

--- ACESSO DA APLICACAO (COMAT) ---
Usuario: ${DB_USER}
Senha:   ${DB_PASS}

--- STRING DE CONEXAO PDO / PHP ---
DATABASE_URL=mysql://${DB_USER}:${DB_PASS}@127.0.0.1:3306/${DB_NAME}
==============================================================================
EOF
chmod 600 "$CREDS_FILE"

# 7. Exibicao do Banner Final no Terminal
echo -e "\n${GREEN}${BOLD}"
echo "=============================================================================="
echo "          BANCO DE DADOS CONFIGURADO E PRONTO PARA USO!                       "
echo "=============================================================================="
echo -e "${NC}"
echo -e "${BOLD}NOME DO BANCO:${NC}     ${CYAN}${DB_NAME}${NC}"
echo -e "------------------------------------------------------------------------------"
echo -e "${BOLD}USUARIO ROOT:${NC}       root"
echo -e "${BOLD}SENHA ROOT:${NC}         ${RED}${MYSQL_ROOT_PASS}${NC}"
echo -e "------------------------------------------------------------------------------"
echo -e "${BOLD}USUARIO DO SISTEMA:${NC} ${YELLOW}${DB_USER}${NC}"
echo -e "${BOLD}SENHA DO SISTEMA:${NC}   ${GREEN}${DB_PASS}${NC}"
echo "=============================================================================="
echo -e "${YELLOW}Um arquivo com estas credenciais foi salvo em:${NC} ${CREDS_FILE}"
echo "=============================================================================="
