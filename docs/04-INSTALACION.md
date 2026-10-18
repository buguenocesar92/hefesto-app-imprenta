# 04. Instalación

Como se levanta la aplicación. Esta escrito para que alguien que nunca vio
el proyecto pueda seguirlo sin adivinar.

**Advertencia honesta**: nada de esto se ha ejecutado. La maquina donde se
escribio el proyecto no tiene PHP, Composer, Node, Docker ni PostgreSQL. Lo
que sigue es el procedimiento previsto, no un procedimiento verificado. Ver
`10-PRUEBAS-Y-ESTADO.md`.

---

# Requisitos

| Componente | Versión | Nota |
|---|---|---|
| PHP | 8.4 o superior | Con `pdo_pgsql`; sin ella no hay RLS |
| Composer | 2 | |
| Node.js | 22.22.3 o superior | Recomendado 24.22 |
| PostgreSQL | 17 | Necesita `CHECK`, gatillos y vistas; SQLite no sirve |
| Docker | con Compose v2 | Solo para el despliegue |

**Sobre PostgreSQL 17**: no es capricho de versión. El aislamiento depende de
Row Level Security, de las vistas y de los gatillos. En SQLite, la suite de
pruebas pasaria **sin comprobar nada**, porque esas cosas no existen ahi. Ver
`10-PRUEBAS-Y-ESTADO.md`.

---

# Instalación en desarrollo

## 1. Base y cache

```
cd apps/imprenta/deploy
docker compose -f docker-compose.dev.yml up -d postgres redis
```

El compose de desarrollo pública `5432` y `6379` **solo en 127.0.0.1**: se
puede conectar un cliente SQL desde el escritorio, pero no desde internet.

## 2. La API

```
cd apps/imprenta/api
composer install
cp .env.example .env
```

En `.env` complete lo minimo:

```
APP_KEY=            # se genera en el paso siguiente
DB_DATABASE=imprenta_dev
DB_USERNAME=imprenta_app
DB_PASSWORD=<lo que haya puesto en el compose de desarrollo>
DB_HOST=127.0.0.1
DB_PORT=5432
AUTHENTIK_ISSUER=<su instancia>
AUTHENTIK_CLIENT_ID=<su aplicacion en Authentik>
AUTHENTIK_CLIENT_SECRET=<el de esa aplicacion>
AUTHENTIK_REDIRECT_URI=http://localhost:8000/api/v1/acceso/callback
```

Generar la clave y migrar:

```
php artisan key:generate
php artisan migrate
php artisan db:seed
```

**Verificar el aislamiento antes de seguir.** Si esto falla, no continuar:

```
php artisan imprenta:verificar-aislamiento --vacio
```

## 3. La interfaz

```
cd apps/imprenta/web
npm install
cp .env.example .env
npm run dev
```

Quedaria en `http://localhost:3000`.

El navegador entra por el 3000, Nuxt reenvia al 8000. No se abre el 8000 en el
navegador.

## 4. Registrar la primera persona

La aplicación no crea personas sola. Con la empresa ya creada en la base:

```
php artisan imprenta:crear-persona \
    --empresa=imprenta-demo \
    --correo=admin@ejemplo.cl \
    --nombre="Administracion" \
    --rol=administrador
```

La contrasena no se crea aca: el acceso es con la cuenta corporativa de
Authentik, y esa persona tiene que existir también ahi.

## 5. La aplicación en Authentik

En el panel de Authentik hay que crear una aplicación tipo
"OpenID Provider" con:

| Campo | Valor |
|---|---|
| Nombre | Gestión de Imprenta |
| Tipo de cliente | público con PKCE, o confidencial con secreto |
| URI de redireccion | `https://SU-DOMINIO/api/v1/acceso/callback` |
| Flujo | el que tenga el grupo del personal autorizado |
| Premisos (scopes) | `openid profile email` |

Y dos grupos:

- `imprenta-operadores`: todo el personal que entra.
- `imprenta-administradores`: la administración de la imprenta.

Los nombres se pueden cambiar con `AUTHENTIK_GRUPO_OPERADORES` y
`AUTHENTIK_GRUPO_ADMIN`.

**Pendiente de verificación**: los nombres exactos de los campos del panel de
Authentik cambian entre versiones. Hay que confirmarlos contra la instancia
propia antes del despliegue.

---

# Despliegue en producción

## 1. El archivo de entorno, FUERA del repositorio

```
sudo install -d -m 750 /etc/hefesto
sudo cp /ruta/a/apps/imprenta/.env.example /etc/hefesto/imprenta.prod.env
sudo chmod 640 /etc/hefesto/imprenta.prod.env
```

Rellene todo. Como minimo:

```
DB_DATABASE=imprenta
DB_USERNAME=imprenta_app
DB_PASSWORD=<una larga y unica>
REDIS_PASSWORD=<otra distinta>
APP_KEY=<se genera>
APP_URL=https://imprenta.ejemplo.cl
APP_WEB_URL=https://imprenta.ejemplo.cl
AUTHENTIK_ISSUER=https://id.ejemplo.cl/application/o/imprenta/
AUTHENTIK_CLIENT_ID=<su aplicacion>
AUTHENTIK_CLIENT_SECRET=<el secreto>
AUTHENTIK_REDIRECT_URI=https://imprenta.ejemplo.cl/api/v1/acceso/callback
```

Genere la clave sin imprimirla en pantalla compartida:

```
docker run --rm php:8.4-cli sh -c \
  "php -r \"echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;\""
```

## 2. La red y el dominio

El dominio tiene que apuntar a este servidor **antes** del primer arranque:
Caddy pide el certificado TLS solo, y si el dominio no apunta todavia, falla
el arranque y es mas dificil diagnosticar.

## 3. Levantar

```
cd apps/imprenta/deploy
docker compose --env-file /etc/hefesto/imprenta.prod.env \
    -f docker-compose.prod.yml up -d --build
```

## 4. Verificar, en este orden

```
docker compose -f docker-compose.prod.yml logs -f api
```

Debe terminar con:

```
[entrada] la base respondio.
[entrada] ejecutando migraciones...
[entrada] el aislamiento entre imprentas esta activo.
[entrada] listo.
```

Despues, desde otro equipo:

```
curl -I https://imprenta.ejemplo.cl/entrar
```

Y dentro del contenedor:

```
docker compose -f docker-compose.prod.yml exec api \
    php artisan imprenta:verificar-aislamiento
```

## 5. Los respaldos, antes de que haya datos

Instale el respaldo **antes** de cargar el primer pedido real:

```
sudo install -d -m 750 /var/backups/imprenta
```

Y prográmelo con una tarea de `cron`. Ver `08-RESPALDOS.md`.

---

# Que corre en cada contenedor

## API

El `entrypoint.sh` hace, en orden:

1. Espera a que PostgreSQL acepte conexiones (hasta 60 intentos).
2. Prepara los permisos de `storage` y `bootstrap/cache`.
3. Genera `APP_KEY` si falta.
4. Corre las migraciones.
5. Cachea configuración, rutas y eventos.
6. **Verifica el aislamiento** y no levanta si falla.
7. Ejecuta lo que le pasaron.

Los pasos 4 y 6 son los importantes. El 4 incluye las migraciones de Row
Level Security y los gatillos; el 6 es lo que impide que el contenedor sirva
trafico con la RLS apagada.

## Web

`npm run build` en tiempo de construccion y despues solo el servidor
independiente de Nuxt (`.output/`). El contenedor final no lleva Node, ni el
código fuente, ni las dependencias.

## Caddy

Único contenedor con puertos publicados. Pide el certificado TLS solo, aplica
las cabeceras de seguridad y manda todo al contenedor `web`.

---

# Comandos utiles

```
# Estado de las migraciones
php artisan migrate:status

# Verificar el aislamiento (tambien se corre al arrancar)
php artisan imprenta:verificar-aislamiento
php artisan imprenta:verificar-aislamiento --vacio

# Revisar que el estado de cada pedido cuadre con sus entregas
php artisan imprenta:revisar-estados
php artisan imprenta:revisar-estados --corregir

# Revisar el consumo de un item
php artisan tinker
>>> $item = App\Models\PedidoItem::first();
>>> app(App\Services\Papel\CalculadoraDeConsumo::class)->calcular($item)->pasos
```

## Las pruebas

```
php artisan test
php artisan test --testsuite=Aislamiento
php artisan test --testsuite=Pruebas
php artisan test --testsuite=Entregas
php artisan test --testsuite=Unit
```

Las suites estan separadas porque no pesan lo mismo. Si el aislamiento falla,
todo lo demas da igual.

**Requieren PostgreSQL**, no SQLite. Ver `phpunit.xml` y
`10-PRUEBAS-Y-ESTADO.md`.

---

# Problemas frecuentes

## "No hay empresa en el contexto de la sesión"

Una peticion esta tocando una tabla protegida sin pasar por
`ContextoTenant`. Casi siempre es una ruta que quedo fuera del grupo `auth`,
o una tarea de cola que no fija el contexto.

Las tareas en cola que tocan tablas de empresa **tienen** que fijar el
contexto dentro de la transacción. No se puede confiar en que el valor siga
puesto desde la peticion que las lanzo: la cola corre en otro proceso.

## "El rol tiene BYPASSRLS o es superusuario"

El rol de la base puede saltarse todas las políticas. El aislamiento esta
apagado aunque el código este perfecto.

Solucion: crear un rol de aplicación sin esos privilegios y darle solo lo que
necesita:

```
CREATE ROLE imprenta_app LOGIN PASSWORD '<una larga y unica>';
GRANT CONNECT ON DATABASE imprenta TO imprenta_app;
GRANT USAGE ON SCHEMA public TO imprenta_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO imprenta_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO imprenta_app;
```

Sin `BYPASSRLS` y sin superusuario. `imprenta:verificar-aislamiento` lo
comprueba y se niega a levantar si no se cumple.

## "La cookie no llega al navegador"

Casi siempre es el nombre: `SESSION_COOKIE=imprenta_sesion` tiene que
coincidir en la API y en el intermediario de Nuxt. Si en uno es
`imprenta_sesion` y en otro `laravel_session`, la cookie se escribe en una
parte y se lee en la otra.

## El certificado TLS no se emite

El dominio no apunta a este servidor, o el puerto 80 esta cerrado. Caddy
necesita ambos: el 80 para el desafio de verificación y el 443 para servir.

---

# Lo que NO se ha verificado

Para que nadie asuma lo contrario:

- **Ninguna migración fue aplicada.** No hay forma de saber si el SQL es
  valido en la versión de PostgreSQL elegida hasta correrla.
- **Ninguna imagen fue construida.** Las versiones de las imagenes base estan
  fijadas por etiqueta, no por digest, y no se comprobo que existan.
- **Ningun Compose fue levantado.** Los nombres de los servicios y los
  `depends_on` son coherentes en el papel.
- **El flujo con Authentik no se probó** contra una instancia real. Los
  nombres de los campos del panel pueden variar entre versiones.
- **Los guiones de respaldo no se ejecutaron** contra una base real.
