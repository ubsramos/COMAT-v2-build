#!/bin/bash
# ==============================================================================
# SCRIPT DE ALTERAÇÃO / RESET DE SENHA DO ADMINISTRADOR — COMAT v2
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
echo "          ALTERAÇÃO DE SENHA DO ADMINISTRADOR — COMAT v2                     "
echo "=============================================================================="
echo -e "${NC}"

# Validação de Root/Sudo
if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[ERRO] Este script precisa ser executado como ROOT ou via SUDO.${NC}"
  echo "Exemplo: sudo bash trocar_senha_admin.sh"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

USUARIO_TARGET="admin"
if [ -n "$1" ]; then
  USUARIO_TARGET="$1"
fi

echo -e "Usuário selecionado: ${CYAN}${BOLD}${USUARIO_TARGET}${NC}\n"

# Solicitação da nova senha de forma segura (sem eco no terminal)
read -s -p "Digite a nova senha desejada: " NOVA_SENHA
echo ""
if [ -z "$NOVA_SENHA" ]; then
  echo -e "${RED}[ERRO] A senha não pode ser vazia.${NC}"
  exit 1
fi

if [ ${#NOVA_SENHA} -lt 4 ]; then
  echo -e "${RED}[ERRO] A senha deve ter no mínimo 4 caracteres.${NC}"
  exit 1
fi

read -s -p "Confirme a nova senha: " CONF_SENHA
echo ""

if [ "$NOVA_SENHA" != "$CONF_SENHA" ]; then
  echo -e "${RED}[ERRO] As senhas digitadas não coincidem.${NC}"
  exit 1
fi

echo -e "\n${YELLOW}Aplicando nova senha com criptografia Bcrypt adaptativa...${NC}"

# Método 1: Tenta via Container Docker se estiver em execução
SUCESSO=0
if docker ps --format '{{.Names}}' 2>/dev/null | grep -q "comat_v2_app"; then
  echo -e "${CYAN}Atualizando via Container Docker (comat_v2_app)...${NC}"
  docker exec -i comat_v2_app php -r '
    require "/var/www/html/backend/config.php";
    $user = "'"$USUARIO_TARGET"'";
    $pass = "'"$NOVA_SENHA"'";
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $db = Config::getDb();
    $stmt = $db->prepare("UPDATE usuario SET senha = ? WHERE login = ?");
    $stmt->execute([$hash, $user]);
    if ($stmt->rowCount() > 0) {
      echo "[OK] Senha atualizada no banco.\n";
    } else {
      // Verifica se usuário existe
      $check = $db->prepare("SELECT id FROM usuario WHERE login = ?");
      $check->execute([$user]);
      if ($check->fetch()) {
        echo "[OK] Senha confirmada.\n";
      } else {
        echo "[ERRO] Usuário nao encontrado no banco.\n";
        exit(1);
      }
    }
  ' && SUCESSO=1 || true
fi

# Método 2: Se o container não estiver rodando ou falhar, executa via MySQL Local
if [ $SUCESSO -eq 0 ]; then
  echo -e "${CYAN}Container inativo ou falhou. Atualizando diretamente via MySQL Local...${NC}"
  
  if command -v php >/dev/null 2>&1; then
    HASH_BCRYPT=$(php -r "echo password_hash('$NOVA_SENHA', PASSWORD_BCRYPT);")
  else
    # Fallback se PHP não estiver instalado no host (usa python3 ou node se disponível)
    if command -v python3 >/dev/null 2>&1; then
      HASH_BCRYPT=$(python3 -c "import crypt; print(crypt.crypt('$NOVA_SENHA', crypt.mksalt(crypt.METHOD_BLOWFISH)))" 2>/dev/null || true)
    fi
  fi

  if [ -n "$HASH_BCRYPT" ]; then
    SQL_CMD="UPDATE comat_db.usuario SET senha = '$HASH_BCRYPT' WHERE login = '$USUARIO_TARGET';"
    if mysql -u root -e "$SQL_CMD" 2>/dev/null || mysql -e "$SQL_CMD" 2>/dev/null; then
      SUCESSO=1
    fi
  fi
fi

if [ $SUCESSO -eq 1 ]; then
  echo -e "\n${GREEN}${BOLD}==============================================================================${NC}"
  echo -e "${GREEN}${BOLD}     SENHA DO USUÁRIO '${USUARIO_TARGET}' ATUALIZADA COM SUCESSO!           ${NC}"
  echo -e "${GREEN}${BOLD}==============================================================================${NC}"
  echo -e "Você já pode acessar o sistema com as novas credenciais."
  echo "==============================================================================\n"
else
  echo -e "\n${RED}[ERRO] Não foi possível atualizar a senha. Verifique se o MySQL ou o container estão ativos.${NC}"
  exit 1
fi
