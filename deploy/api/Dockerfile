# ---------------------------------------------------------------------------
# Imagen de la API (Laravel + php-fpm + nginx interno)
# ---------------------------------------------------------------------------
# Aplicacion propia de Hefesto Studio: NO es un despliegue de una herramienta
# de terceros.
#
# La imagen esta fijada por tag exacto. Falta fijarla tambien por digest
# SHA256 antes de produccion (docs/10-PRUEBAS-Y-ESTADO.md).
# ---------------------------------------------------------------------------

# =============================================================================
# CVE-2026-109618 / CVE-2026-109619 (PHP2Shell, 2026-10-22)
# =============================================================================
# La imagen se construye con PHP 8.4.27, que es la version PARCHEADA
# de la serie 8.4. Las versiones parcheadas de PHP son
# 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12.
#
# Verificado el 2026-10-22 en el servidor de desarrollo, con el tag exacto:
#
#     $ docker run --rm php:8.4-fpm-alpine php -v
#     PHP 8.4.27 (cli) (built: Oct 21 2026 22:52:58)
#
# El tag `php:8.4-fpm-alpine` es FLOTANTE: no se cambia, se deja constancia.
# El tag resuelve hoy a PHP 8.4.27, que es la version parcheada de la serie
# 8.4. Se deja escrito porque un tag flotante puede mudar sin que nadie lo
# note: la comprobacion va en el candado (api/tests/Unit/), que falla si el
# tag vuelve a quedar por debajo de la version parcheada.
#
# OJO: LA IMAGEN DESPLIEGADA HAY QUE RECONSTRUIRLA. El parche de PHP entra en
# el contenedor por la IMAGEN, no por este repositorio: mientras no se
# reconstruya y se redespliegue, el contenedor sigue con el PHP viejo. El
# endurecimiento de mas abajo (el ini y el pool de php-fpm) es la red de seguridad
# para ese mientras, y lo que sobrevive a un redeploy.
FROM php:8.4-fpm-alpine

# Herramientas del sistema.
RUN apk update \
    && apk upgrade \
    && apk add --no-cache \
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
COPY api/composer.json api/composer.lock* ./

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

COPY api/ ./

# El usuario que trae la imagen corre sin privilegios.
RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY deploy/api/docker/nginx.conf /etc/nginx/nginx.conf
COPY deploy/api/docker/php.ini /usr/local/etc/php/conf.d/imprenta.ini

# Endurecimiento PHP2Shell (CVE-2026-109618 / CVE-2026-109619):
# la via CONFIRMADA (session.upload_progress) apagada, y los
# trabajadores de php-fpm reciclados cada 100 peticiones.
COPY deploy/api/docker/99-php2shell-hardening.ini /usr/local/etc/php/conf.d/99-php2shell-hardening.ini
COPY deploy/api/docker/zz-php2shell-fpm.conf /usr/local/etc/php-fpm.d/zz-php2shell-hardening.conf
COPY deploy/api/docker/supervisord.conf /etc/supervisord.conf
COPY deploy/api/docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/www/html/public/uploads

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD wget --no-verbose --tries=1 --spider http://127.0.0.1:8080/api/v1/salud || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
