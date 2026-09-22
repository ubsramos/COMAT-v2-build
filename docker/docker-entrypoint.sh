#!/bin/sh
# ==============================================================================
# DOCKER ENTRYPOINT — COMAT v2
# Executa migrações automáticas de banco na inicialização do container
# antes de subir o Nginx e PHP-FPM
# ==============================================================================
set -e

echo "[COMAT DOCKER] Inicializando container COMAT v2..."

# Tenta executar as migrações no startup do container
if [ -f "/var/www/html/backend/db_migrate.php" ]; then
    echo "[COMAT DOCKER] Verificando e aplicando migrações de banco de dados..."
    php /var/www/html/backend/db_migrate.php || true
elif [ -f "/var/www/html/backend/api/db_migrate.php" ]; then
    echo "[COMAT DOCKER] Verificando e aplicando migrações de banco de dados..."
    php /var/www/html/backend/api/db_migrate.php || true
fi

echo "[COMAT DOCKER] Iniciando Nginx e PHP-FPM via Supervisord..."
exec "$@"
