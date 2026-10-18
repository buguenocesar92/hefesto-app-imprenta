#!/bin/sh
# ---------------------------------------------------------------------------
# Entrada del contenedor de la API.
# ---------------------------------------------------------------------------
# QUE HACE Y QUE NO
#
# Hace: esperar la base, correr migraciones, limpiar caches y arrancar.
# NO hace: resolver el dominio de una empresa en particular ni decidir datos.
# La logica es de la aplicacion, no de este guion.
#
# POR QUE ESPERA A LA BASE
#
# En un despliegue con varios contenedores, la API puede arrancar antes que
# PostgreSQL. Sin esta espera, el primer arranque falla con un error de
# conexion y el orquestador lo reinicia en bucle. Es mejor esperar con
# paciencia y avisar.
# ---------------------------------------------------------------------------

set -eu

echo "[entrada] esperando a la base de datos..."

CONTADOR=0
MAX_INTENTOS="${MAX_INTENTOS_ESPERA:-60}"

while ! pg_isready \
        --host="${DB_HOST:-postgres}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME:-imprenta_app}" \
        --dbname="${DB_DATABASE:-imprenta}" \
        >/dev/null 2>&1; do

    CONTADOR=$((CONTADOR + 1))

    if [ "$CONTADOR" -ge "$MAX_INTENTOS" ]; then
        echo "[entrada] ERROR: la base no respondio en $MAX_INTENTOS intentos."
        echo "[entrada]        revise DB_HOST y que el contenedor de PostgreSQL este arriba."
        exit 1
    fi

    [ $((CONTADOR % 5)) -eq 0 ] && echo "[entrada] todavia esperando ($CONTADOR)..."
    sleep 2
done

echo "[entrada] la base respondio."

# --- Permisos de almacenamiento ---------------------------------------------
# El usuario www-data corre sin privilegios: los directorios tienen que ser
# suyos o Laravel no puede escribir el cache ni los registros.
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

echo "[entrada] generando APP_KEY si falta..."

if [ -z "${APP_KEY:-}" ]; then
    php artisan key:generate --force --no-interaction
fi

echo "[entrada] ejecutando migraciones..."

# Las migraciones incluyen las de Row Level Security y los gatillos que
# impiden imprimir sin prueba aprobada. Son parte de la aplicacion, no un
# extra opcional.
php artisan migrate --force --no-interaction

# El cache de rutas y de configuracion no se versiona: si estuviera con
# contenido viejo, la aplicacion leeria rutas que ya no existen.
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan event:cache --no-interaction 2>/dev/null || true

# Comprobacion de aislamiento ANTES de servir trafico.
#
# Si esto falla, el contenedor NO debe levantar. Un contenedor que sirve con
# la RLS apagada muestra los pedidos de todas las imprentas, y eso no es un
# incidente recuperable: es una filtracion.
echo "[entrada] verificando el aislamiento entre imprentas..."

if ! php artisan imprenta:verificar-aislamiento; then
    echo "[entrada] ERROR: el aislamiento entre imprentas NO esta activo."
    echo "[entrada]        NO se sirve trafico con esto. Revise las migraciones y el"
    echo "[entrada]        rol de la base: un rol con BYPASSRLS ignora todas las politicas."
    exit 1
fi

echo "[entrada] listo."

exec "$@"
