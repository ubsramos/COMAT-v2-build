# Guia Operacional de Instalacao e Deploy — COMAT v2.0
## Arquitetura Gateway Reverse Proxy Multi-App (Nginx Host + Docker)

Este pacote contem todos os artefatos necessarios para implantar o sistema **COMAT v2** em um servidor **Ubuntu Server (20.04, 22.04, 24.04 ou 26.04)** utilizando a arquitetura profissional de **Gateway Reverse Proxy**.

---

## Como Funciona a Arquitetura Multi-App

1. **Nginx no Host Ubuntu:** Roda diretamente no sistema operacional escutando nas portas publicas padrao `80` (HTTP) e `443` (HTTPS), gerenciando certificados SSL e redirecionando as requisicoes com base no dominio (ex: `comat.aspa.org.br`).
2. **Container Docker em Porta Interna:** O COMAT v2 roda isolado em `127.0.0.1:8033` (protegido contra acessos externos diretos).
3. **Novas Aplicacoes Futuras:** Para subir um novo sistema no mesmo servidor, basta usar a proxima porta interna (ex: `8034`, `8035`) e adicionar um novo arquivo `.conf` no Nginx do Host.

---

## Conteudo do Pacote

- `install_server.sh`: Script mestre que instala Nginx no Host, MySQL, Docker, gera a Deploy Key, importa o banco, sobe o container na porta 8033 e configura o Reverse Proxy.
- `setup_database.sh`: Script focado apenas na instalacao e restauracao do MySQL Server.
- `reset_server.sh`: Script de expurgo/reset do servidor para testes e simulacoes do zero.
- `atualizar.sh`: Script de atualizacao rapida via Git Pull + Docker Compose.
- `auto_check_update.sh`: Script de verificacao e atualizacao automatica em segundo plano (Crontab).
- `docker-compose.yml`: Orquestrador do container COMAT (Nginx + PHP 8.2-FPM).
- `database/schema_comat.sql`: Schema completo do banco de dados MySQL com usuario admin padrao (Nivel 1 Master).
- `ssl/`: Pasta para os certificados SSL (`comat.crt` e `comat.key`).
- `app/public/`: Frontend compilado em React 19 (SPA + PWA minificado).
- `app/backend/`: API em PHP 8 nativo pronta para execucao.
- `DOCUMENTACAO_ARQUITETURA_SERVIDOR.pdf`: Documento executivo institucional de 2 paginas A4 com diagramas.

---

## Painel de Parametros (No Topo do `install_server.sh`)

Voce pode editar as primeiras linhas do arquivo `install_server.sh` antes de executar:

```bash
# ==============================================================================
# PAINEL DE CONFIGURACOES GERAIS (EDITE AQUI ANTES DE EXECUTAR)
# ==============================================================================
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
# ==============================================================================
```

---

## Como Instalar no Servidor Linux

### Opção 1: Via Pacote `.tar.gz` (Envio Direto)
```bash
# 1. Enviar o pacote do Mac para o servidor:
scp COMAT_v2_DOCKER_DISTRIB.tar.gz uli@192.168.15.4:~/

# 2. Conectar, descompactar e instalar:
ssh uli@192.168.15.4
tar -xzf COMAT_v2_DOCKER_DISTRIB.tar.gz
cd DOCKER-DISTRIB
sudo bash install_server.sh
```

### Opção 2: Via Repositório de Build no GitHub (`COMAT-v2-build`)
```bash
git clone git@github.com:ubsramos/COMAT-v2-build.git comat-build
cd comat-build
sudo bash install_server.sh
```

---

## Como Atualizar a Aplicacao no Servidor

### Atualização Manual:
```bash
cd ~/comat-build # ou ~/DOCKER-DISTRIB
bash atualizar.sh
```

### Atualização Automática Silenciosa (Crontab a cada 10 minutos):
```bash
crontab -e
# Adicione a linha:
*/10 * * * * bash /home/uli/comat-build/auto_check_update.sh >> /var/log/comat_update.log 2>&1
```

---

## Como Configurar o DNS da Rede

Para que os usuarios acessem pelo dominio amigavel (ex: `http://comat.aspa.org.br`):
- Abra o painel de DNS da instituicao (ou o servidor DNS interno da rede local / Mikrotik / pfSense / Windows Server DNS).
- Adicione um apontamento **Tipo A**:
  - **Nome:** `comat.aspa.org.br`
  - **Destino / IP:** IP do Servidor Linux (ex: `192.168.15.4`).

---

## Como Ativar Certificado SSL / HTTPS

1. Coloque o arquivo do certificado em `DOCKER-DISTRIB/ssl/comat.crt` e a chave em `DOCKER-DISTRIB/ssl/comat.key` (ou informe caminhos personalizados em `SSL_CERT_PATH` e `SSL_KEY_PATH`).
2. No `install_server.sh`, altere `USAR_SSL="sim"`.
3. Execute novamente: `sudo bash install_server.sh`.
4. O Nginx Host passara a atender em HTTPS na porta 443 e redirecionara todo o trafego HTTP da porta 80 para HTTPS automaticamente.
