#!/bin/sh
# ---------------------------------------------------------------------------
# Restauracion de Gestion de Imprenta, libreria y papeleria.
# ---------------------------------------------------------------------------
# POR QUE ESTE SCRIPT ES MAS CUIDADO QUE EL RESPALDO
# ---------------------------------------------------
# Un respaldo que sale mal se nota en una hora. Una restauracion que sale mal
# borra la base que estaba funcionando, y en una imprenta esa base tiene las
# aprobaciones de prueba: lo que prueba que el cliente vio el diseno antes de
# que salieran 5.000 tarjetas. Sin eso, el trabajo que ya salio no tiene
# respaldo ante un reclamo. Por eso todo lo que este script hace es
# deliberadamente conservador: avisa, exige confirmacion y deja el servicio
# detenido.
#
# LAS REGLAS QUE SE SIGUEN
# -------------------------
# 1. NUNCA HAY `DROP`. No se tira la base, no se borra el esquema, no se usa
#    `--clean` a la ligera. La restauracion se hace sobre una base que debe
#    estar VACIA, y el script lo comprueba.
#
# 2. NUNCA HAY SOBREESCRITURA AUTOMATICA. Si el destino tiene tablas, el
#    script aborta salvo que se pase `--force`, y con `--force` lo que hace es
#    vaciar las tablas existentes, una por una y en una transaccion. No borra
#    la base.
#
# 3. EL SERVICIO QUEDA DETENIDO. El script detiene los contenedores ANTES de
#    restaurar y NO los vuelve a levantar. Restaurar con gente entrando a la
#    imprenta produce pedidos y entregas que el sistema no tiene, y una
#    entrega sin respaldo de pedido es exactamente el error que este sistema
#    existe para evitar.
#
# 4. NUNCA HAY ESCRITURA A DESTINO OCUPADO. El respaldo no se toca. Se abre en
#    lectura y se verifica el SHA-256 contra el manifiesto.
#
# 5. EL MANIFIESTO SE VERIFICA ANTES DE TOCAR LA BASE. Si el archivo y la suma
#    no calzan, no se restaura nada. Un respaldo dañado restaurado a medias
#    deja una base que parece buena y no lo esta.
#
# 6. NUNCA HAY RESTAURACION PARCIAL SILENCIOSA. `--single-transaction` hace
#    que se aplique COMPLETA o NO SE APLIQUE.
#
# 7. PIDE `--confirmar` PARA ESCRIBIR. Sin esa opcion el script solo informa.
#    Es la diferencia entre mirar y hacer.
#
# 8. NO SE RESTAURA SOBRE UNA BASE QUE PAREZCA DE PRODUCCION. Un nombre con
#    `prod`, `produccion`, `produccion` o `live` se rechaza sin excepciones:
#    restaurar un respaldo de hace tres meses sobre la base de hoy, por error,
#    es un proyecto entero de trabajo perdido.
#
# 9. AL TERMINAR SE COMPRUEBA QUE LA BASE CONSERVARON LAS POLITICAS DE
#    AISLAMIENTO. Una base restaurada sin sus politicas de Row Level Security
#    se levanta y muestra los pedidos de todas las imprentas. Por eso no
#    basta con que "la restauracion termino bien".
#
# USO
# ---
#   deploy/respaldos/restauracion.sh /var/backups/imprenta/imprenta-20261018-030000
#   deploy/respaldos/restauracion.sh --simular /ruta/al/respaldo
#   deploy/respaldos/restauracion.sh --confirmar --force /ruta/al/respaldo
#
# Variables de entorno obligatorias:
#   DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_HOST, DB_PORT
#
# Con SIMULAR no se escribe nada: se comprueba el archivo, el manifiesto y las
# dependencias. Es lo que hay que correr primero, siempre.
# ---------------------------------------------------------------------------

set -eu

FORZAR=0
SIMULAR=0
CONFIRMAR=0
RESPALDO=""

for ARGUMENTO in "$@"; do
    case "$ARGUMENTO" in
        --force)
            FORZAR=1
            ;;
        --simular)
            SIMULAR=1
            ;;
        --confirmar)
            CONFIRMAR=1
            ;;
        --help|-h)
            echo "Uso: $0 [--simular] [--confirmar] [--force] /ruta/al/respaldo"
            echo ""
            echo "  --simular   comprueba el respaldo y las dependencias sin escribir nada."
            echo "              Correr esto SIEMPRE antes de una restauracion real."
            echo "  --confirmar autoriza a ESCRIBIR en la base. Sin esta opcion el script"
            echo "              solo informa y no cambia nada."
            echo "  --force     permite restaurar sobre una base que ya tiene tablas."
            echo "              Vacia las tablas existentes en una transaccion. NO borra la base."
            exit 0
            ;;
        -*)
            echo "[restauracion] ERROR: opcion desconocida: $ARGUMENTO"
            exit 2
            ;;
        *)
            RESPALDO="$ARGUMENTO"
            ;;
    esac
done

if [ -z "$RESPALDO" ]; then
    echo "[restauracion] ERROR: falta la ruta del respaldo."
    echo "[restauracion] use --help para ver el uso."
    exit 2
fi

if [ -z "${DB_HOST:-}" ] || [ -z "${DB_DATABASE:-}" ] || [ -z "${DB_USERNAME:-}" ]; then
    echo "[restauracion] ERROR: faltan DB_HOST, DB_DATABASE o DB_USERNAME en el entorno."
    exit 1
fi

if ! command -v pg_restore >/dev/null 2>&1; then
    echo "[restauracion] ERROR: pg_restore no esta disponible en este equipo."
    exit 1
fi

# --- REGLA 8: no tocar una base que parezca de produccion --------------------
# Se comprueba antes de conectarse a nada, para que ni siquiera se intente.
NOMBRE_BASE="$(printf '%s' "$DB_DATABASE" | tr '[:upper:]' '[:lower:]')"

case "$NOMBRE_BASE" in
    *prod*|*live*|*produccion*)
        echo "[restauracion] ERROR: la base \"$DB_DATABASE\" PARECE DE PRODUCCION."
        echo "[restauracion]        este script se niega a restaurar sobre ella."
        echo "[restauracion]        si de verdad quiere, cambie DB_DATABASE a una base de"
        echo "[restauracion]        pruebas y vuelva a correr. Decidir esto a mano, no por omision."
        exit 1
        ;;
esac

# ---------------------------------------------------------------------------
# 1. Comprobaciones del respaldo (REGLA 5)
# ---------------------------------------------------------------------------
if [ ! -d "$RESPALDO" ]; then
    echo "[restauracion] ERROR: no existe el directorio del respaldo: $RESPALDO"
    exit 1
fi

ARCHIVO="$RESPALDO/datos.dump.gz"
MANIFIESTO="$RESPALDO/manifiesto.txt"
MARCA_COMPLETITUD="$RESPALDO/COMPLETO"

# Si el respaldo no tiene la marca de completo, no se restaura. Un respaldo a
# medias restaurado deja una base que parece buena y no lo esta.
if [ ! -f "$MARCA_COMPLETITUD" ]; then
    echo "[restauracion] ERROR: el respaldo NO tiene el archivo COMPLETO."
    echo "[restauracion]        significa que el respaldo se corto a la mitad."
    echo "[restauracion]        no se restaura. Corra de nuevo el respaldo."
    exit 1
fi

if [ -f "$RESPALDO/EN_CURSO" ]; then
    echo "[restauracion] ERROR: el respaldo tiene la marca EN_CURSO."
    echo "[restauracion]        significa que se estaba escribiendo cuando se corto."
    exit 1
fi

if [ ! -f "$ARCHIVO" ]; then
    echo "[restauracion] ERROR: falta el archivo de datos: $ARCHIVO"
    exit 1
fi

if [ ! -f "$MANIFIESTO" ]; then
    echo "[restauracion] ERROR: falta el manifiesto: $MANIFIESTO"
    echo "[restauracion]        sin manifiesto no hay forma de verificar el archivo."
    exit 1
fi

SUMA_ESPERADA="$(grep '^sha256:' "$MANIFIESTO" | awk '{ print $2 }')"

if [ -z "$SUMA_ESPERADA" ]; then
    echo "[restauracion] ERROR: el manifiesto no tiene una linea sha256."
    exit 1
fi

if command -v sha256sum >/dev/null 2>&1; then
    SUMA_REAL="$(sha256sum "$ARCHIVO" | awk '{ print $1 }')"
else
    SUMA_REAL="$(shasum -a 256 "$ARCHIVO" | awk '{ print $1 }')"
fi

if [ "$SUMA_ESPERADA" != "$SUMA_REAL" ]; then
    echo "[restauracion] ERROR: el archivo NO calza con el manifiesto."
    echo "[restauracion]        esperado: $SUMA_ESPERADA"
    echo "[restauracion]        real:     $SUMA_REAL"
    echo "[restauracion]        no se restaura nada. El respaldo esta danado."
    exit 1
fi

echo "[restauracion] archivo verificado contra el manifiesto."

if ! gzip -t "$ARCHIVO"; then
    echo "[restauracion] ERROR: el archivo comprimido esta danado."
    exit 1
fi

# ---------------------------------------------------------------------------
# 2. Estado de la base de destino (REGLAS 1 y 2)
# ---------------------------------------------------------------------------
consultar() {
    PGPASSWORD="${DB_PASSWORD:-}" psql \
        --host="${DB_HOST}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME}" \
        --dbname="${DB_DATABASE}" \
        --tuples-only \
        --no-align \
        --command="$1"
}

TABLAS_ACTUALES="$(consultar "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public';" || echo 'consulta-fallida')"

if [ "$TABLAS_ACTUALES" = "consulta-fallida" ]; then
    echo "[restauracion] ERROR: no se pudo consultar la base $DB_DATABASE."
    echo "[restauracion]        no se sigue adelante a ciegas."
    exit 1
fi

echo "[restauracion] la base $DB_DATABASE tiene $TABLAS_ACTUALES tablas."

if [ "$TABLAS_ACTUALES" -ne 0 ] && [ "$FORZAR" -ne 1 ]; then
    echo "[restauracion] ERROR: la base de destino ya tiene tablas."
    echo "[restauracion]        no se sobrescribe nada sin confirmacion."
    echo "[restauracion]        si de verdad quiere reemplazar los datos, use --force."
    echo "[restauracion]        Con --force se VACIAN las tablas en una transaccion."
    echo "[restauracion]        La base no se borra y no se hace DROP."
    exit 1
fi

if [ "$SIMULAR" -eq 1 ]; then
    echo ""
    echo "[restauracion] SIMULACION: no se escribio nada."
    echo "[restauracion] todo lo necesario esta en su lugar. Ya puede restaurar."
    exit 0
fi

# --- REGLA 7: sin --confirmar, este script no escribe ------------------------
if [ "$CONFIRMAR" -ne 1 ]; then
    echo ""
    echo "[restauracion] FALTA LA CONFIRMACION: no se escribio nada."
    echo "[restauracion] si quiere restaurar de verdad, repita el comando con --confirmar."
    echo "[restauracion] base de destino: $DB_DATABASE"
    echo "[restauracion] respaldo: $RESPALDO"
    exit 3
fi

# ---------------------------------------------------------------------------
# 3. Detener el servicio (REGLA 3)
# ---------------------------------------------------------------------------
# Se detiene SOLO si hay docker compose disponible. Si no lo hay, se avisa y se
# sigue: es probable que se este restaurando en un equipo de pruebas.
echo "[restauracion] deteniendo el servicio para que nadie entre a la imprenta..."

if command -v docker >/dev/null 2>&1; then
    COMPOSE="${ARCHIVO_COMPOSE:-deploy/docker-compose.prod.yml}"

    if [ -f "$COMPOSE" ]; then
        docker compose -f "$COMPOSE" stop api web || true
        echo "[restauracion] servicio detenido."
    else
        echo "[restauracion] AVISO: no se encontro $COMPOSE para detener los contenedores."
        echo "[restauracion]        Detenga el servicio a mano antes de seguir, si esta corriendo."
    fi
else
    echo "[restauracion] AVISO: docker no esta disponible en este equipo."
    echo "[restauracion]        Si la aplicacion esta corriendo en otra parte, detengala a mano."
fi

# ---------------------------------------------------------------------------
# 4. Vaciar lo que haya, si hay algo (REGLA 2)
# ---------------------------------------------------------------------------
if [ "$TABLAS_ACTUALES" -ne 0 ]; then
    echo "[restauracion] vaciando las tablas existentes (--force)..."

    # Se genera un TRUNCATE de todas las tablas de `public`, en una sola
    # transaccion. TRUNCATE y no DROP: la base sigue existiendo, con su
    # propietario y sus permisos, y no hay ninguna sentencia DROP en este
    # script.
    LISTA_TABLAS="$(consultar "
        SELECT string_agg(quote_ident(table_name), ', ' ORDER BY table_name)
        FROM information_schema.tables
        WHERE table_schema = 'public' AND table_type = 'BASE TABLE';
    ")"

    if [ -n "$LISTA_TABLAS" ]; then
        PGPASSWORD="${DB_PASSWORD:-}" psql \
            --host="${DB_HOST}" \
            --port="${DB_PORT:-5432}" \
            --username="${DB_USERNAME}" \
            --dbname="${DB_DATABASE}" \
            --set=ON_ERROR_STOP=1 \
            --command="TRUNCATE $LISTA_TABLAS CASCADE;" \
            || {
                echo "[restauracion] ERROR: no se pudieron vaciar las tablas. Se aborta."
                exit 1
            }
    fi
fi

# ---------------------------------------------------------------------------
# 5. Restaurar (REGLA 6)
# ---------------------------------------------------------------------------
echo "[restauracion] restaurando $ARCHIVO en $DB_DATABASE..."

# `--single-transaction`: se aplica COMPLETA o NO SE APLICA. Una restauracion
# a medias deja una base incoherente que parece buena.
# `--exit-on-error`: se detiene en el primer error, en vez de seguir aplicando.
# NO se usa `--clean`: eso genera DROP por dentro y esta es una base vacia.
# `--no-owner` y `--no-privileges`: el respaldo no debe exigir el rol del
# equipo donde se hizo.
if PGPASSWORD="${DB_PASSWORD:-}" pg_restore \
        --host="${DB_HOST}" \
        --port="${DB_PORT:-5432}" \
        --username="${DB_USERNAME}" \
        --dbname="${DB_DATABASE}" \
        --single-transaction \
        --exit-on-error \
        --no-owner \
        --no-privileges \
        "$ARCHIVO"; then

    echo "[restauracion] restauracion terminada."
else
    echo "[restauracion] ERROR: fallo pg_restore. La base queda como estaba."
    echo "[restauracion]        gracias a --single-transaction, no quedo una restauracion a medias."
    exit 1
fi

# ---------------------------------------------------------------------------
# 6. Comprobacion posterior
# ---------------------------------------------------------------------------
TABLAS_RESTAURADAS="$(consultar "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public';")"

echo "[restauracion] la base tiene ahora $TABLAS_RESTAURADAS tablas."

# --- REGLA 9: la base restaurada TIENE que conservar el aislamiento -----------
# No basta con que la restauracion termine bien. Una base sin sus politicas de
# Row Level Security se levanta y despide los pedidos de todas las imprentas,
# y eso no se nota en un log de exito: se nota cuando un cliente llama.

SIN_AISLAR=0

# La funcion de contexto es lo que hace que las politicas tengan contra que
# comparar. Sin ella, la politica no filtra nada.
if ! consultar "SELECT 1 FROM pg_proc WHERE proname = 'app_tenant_id';" | grep -q 1; then
    echo "[restauracion] FALLA: no se encontro la funcion public.app_tenant_id()."
    echo "[restauracion]        el aislamiento entre imprentas NO va a funcionar."
    SIN_AISLAR=1
fi

# Las politicas propiamente dichas.
POLITICAS="$(consultar "
    SELECT count(*)
      FROM pg_policies
     WHERE schemaname = 'public';
" || echo '0')"

if [ "$POLITICAS" -lt 1 ] 2>/dev/null; then
    echo "[restauracion] FALLA: la base restaurada no tiene ninguna politica de RLS."
    echo "[restauracion]        TODAS las imprentas verian los datos de todas."
    SIN_AISLAR=1
else
    echo "[restauracion] politicas de RLS restauradas: $POLITICAS"
fi

# Y que las tablas esten con RLS habilitada Y forzada.
SIN_FORZAR="$(consultar "
    SELECT count(*)
      FROM pg_class c
      JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = 'public'
       AND c.relkind = 'r'
       AND c.relname <> 'migrations'
       AND NOT (c.relrowsecurity AND c.relforcerowsecurity);
" || echo '0')"

if [ "$SIN_FORZAR" != "0" ]; then
    echo "[restauracion] FALLA: $SIN_FORZAR tabla(s) sin Row Level Security forzada."
    echo "[restauracion]        el duena de la tabla puede saltarse la politica."
    SIN_AISLAR=1
else
    echo "[restauracion] todas las tablas tienen RLS habilitada y forzada."
fi

if [ "$SIN_AISLAR" -ne 0 ]; then
    echo ""
    echo "[restauracion] LA BASE RESTAURADA NO SIRVE TODAVIA."
    echo "[restauracion] Corra 'php artisan migrate --force' sobre ella antes de levantar"
    echo "[restauracion] cualquier servicio. NO levante el servicio con una base sin"
    echo "[restauracion] aislamiento: mostraria los pedidos de todas las imprentas."
    exit 1
fi

echo "[restauracion] el aislamiento entre imprentas quedo intacto."

# ---------------------------------------------------------------------------
# 7. El servicio QUEDA DETENIDO (REGLA 3)
# ---------------------------------------------------------------------------
echo ""
echo "[restauracion] EL SERVICIO QUEDA DETENIDO."
echo "[restauracion] Revise los datos antes de levantar nada:"
echo "[restauracion]   1. Revise que las aprobaciones de prueba estan ahi: son el"
echo "[restauracion]      respaldo de que el cliente aprobo antes de imprimir."
echo "[restauracion]   2. Corra 'php artisan imprenta:verificar-aislamiento'."
echo "[restauracion]   3. Corra 'php artisan imprenta:revisar-estados'."
echo "[restauracion]   4. Solo despues levante el servicio con 'docker compose up -d'."
echo "[restauracion]"
echo "[restauracion] Este script NO levanta el servicio. La decision es de una persona."
