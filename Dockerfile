FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip gosu libonig-dev libxml2-dev \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring dom xml xmlwriter pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY public/ /opt/lead-crm-public/
COPY docker/init.sh /usr/local/bin/lead-crm-init
RUN chmod +x /usr/local/bin/lead-crm-init
CMD ["php-fpm"]
