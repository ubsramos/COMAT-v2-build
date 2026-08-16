#!/bin/bash
# ==============================================================================
# SCRIPT DE ATUALIZACAO RAPIDA — COMAT v2 (BUILD REPOSITORY)
# ==============================================================================
set -e

GREEN='\033[0;32m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${BLUE}==============================================================================${NC}"
echo -e "${BLUE}          ATUALIZANDO COMAT v2 A PARTIR DO GITHUB (BUILD)                    ${NC}"
echo -e "${BLUE}==============================================================================${NC}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo -e "\n${CYAN}[1/2] Baixando versao compilada mais recente...${NC}"
git pull origin main

echo -e "\n${CYAN}[2/2] Recarregando containers Docker...${NC}"
docker compose up -d --build --remove-orphans

echo -e "\n${GREEN}[OK] COMAT v2 atualizado com sucesso!${NC}\n"
