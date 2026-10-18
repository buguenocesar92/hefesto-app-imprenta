# ---------------------------------------------------------------------------
# Imagen de la API (Laravel + php-fpm + nginx interno)
# ---------------------------------------------------------------------------
# Aplicacion propia de Hefesto Studio: NO es un despliegue de una herramienta
# de terceros.
#
# La imagen esta fijada por tag exacto. Falta fijarla tambien por digest
# SHA256 antes de produccion (docs/10-PRUEBAS-Y-ESTADO.md).
# ---------------------------------------------------------------------------

FROM php:8.4-fpm-alpine

# Herramientas del sistema.
RUN apk add --no-cache \
        git \
        unzip \
        postgresql-client \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        nginx \
        supervisor \
        tzdata \
    && cp /usr/share/zoneinfo/America/Santiago /etc/localtime \
    && echo "America/Santiago" > /etc/timezone

# Extensiones de PHP que necesita la aplicacion.
# pdo_pgsql es obligatoria: sin ella no hay RLS, y sin RLS no hay aislamiento.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip

# Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Se copian primero los manifiestos para reutilizar la capa de dependencias.
COPY composer.json composer.lock* ./

# Las dependencias de DESARROLLO no van en la imagen final: no hacen falta para
# servir peticiones y multiplican el tamaño y la superficie de ataque.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-progress \
        --prefer-dist \
        --no-interaction \
    || composer install \
        --no-dev \
        --no-scripts \
        --no-progress \
        --prefer-dist \
        --no-interaction

COPY . .

# El usuario que trae la imagen corre sin privilegios.
RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/imprenta.ini
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/www/html/public/uploads

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD wget --no-verbose --tries=1 --spider http://127.0.0.1:8080/api/v1/salud || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
