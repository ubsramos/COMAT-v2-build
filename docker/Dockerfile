# ==============================================================================
# DOCKERFILE — COMAT v2 (NGINX + PHP 8.2-FPM ALPINE OTIMIZADO)
# ==============================================================================

FROM php:8.2-fpm-alpine

# Instala dependências do sistema, Nginx e Supervisor
RUN apk update && apk add --no-cache \
    nginx \
    supervisor \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    tzdata \
    && rm -rf /var/cache/apk/*

# Configura extensões PHP (GD, PDO MySQL, Zip, OPcache, MBString)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        zip \
        mbstring \
        intl \
        opcache

# Define fuso horário do container
ENV TZ=America/Sao_Paulo
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Copia configurações personalizadas
COPY ./nginx.conf /etc/nginx/nginx.conf
COPY ./php.ini /usr/local/etc/php/conf.d/custom.ini

# Cria diretórios de trabalho e ajusta permissões
RUN mkdir -p /var/www/html/public \
    /var/www/html/backend/uploads \
    /run/nginx \
    /var/log/supervisor

# Configuração do Supervisor para gerenciar Nginx + PHP-FPM juntos
RUN echo '[supervisord]' > /etc/supervisord.conf \
    && echo 'nodaemon=true' >> /etc/supervisord.conf \
    && echo 'user=root' >> /etc/supervisord.conf \
    && echo 'logfile=/var/log/supervisor/supervisord.log' >> /etc/supervisord.conf \
    && echo 'pidfile=/var/run/supervisord.pid' >> /etc/supervisord.conf \
    && echo '' >> /etc/supervisord.conf \
    && echo '[program:php-fpm]' >> /etc/supervisord.conf \
    && echo 'command=php-fpm -F' >> /etc/supervisord.conf \
    && echo 'stdout_logfile=/dev/stdout' >> /etc/supervisord.conf \
    && echo 'stdout_logfile_maxbytes=0' >> /etc/supervisord.conf \
    && echo 'stderr_logfile=/dev/stderr' >> /etc/supervisord.conf \
    && echo 'stderr_logfile_maxbytes=0' >> /etc/supervisord.conf \
    && echo 'autorestart=true' >> /etc/supervisord.conf \
    && echo '' >> /etc/supervisord.conf \
    && echo '[program:nginx]' >> /etc/supervisord.conf \
    && echo 'command=nginx -g "daemon off;"' >> /etc/supervisord.conf \
    && echo 'stdout_logfile=/dev/stdout' >> /etc/supervisord.conf \
    && echo 'stdout_logfile_maxbytes=0' >> /etc/supervisord.conf \
    && echo 'stderr_logfile=/dev/stderr' >> /etc/supervisord.conf \
    && echo 'stderr_logfile_maxbytes=0' >> /etc/supervisord.conf \
    && echo 'autorestart=true' >> /etc/supervisord.conf

WORKDIR /var/www/html

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
