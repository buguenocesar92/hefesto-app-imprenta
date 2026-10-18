# 10. Pruebas y estado

Este documento dice la verdad. Si hay algo que leer antes de prometer nada,
es este.

---

# La verdad corta

En la maquina donde se escribio esta aplicación **no hay PHP, ni Composer,
ni Node, ni Docker, ni PostgreSQL**.

Por lo tanto:

| | |
|---|---|
| Migraciones aplicadas | **0** |
| Pruebas corridas | **0** |
| Imagenes construidas | **0** |
| Contenedores levantados | **0** |
| Respaldos ejecutados | **0** |
| Compos validados | **2** (solo sintaxis YAML) |
| Guiones de shell validados | **2** (solo `bash -n`) |

**El código esta escrito y escrito. No esta probado.**

Nada en esta aplicación funciona hasta que alguien lo corra. Este documento
existe para que nadie asuma lo contrario.

---

# Lo que SI se verifico

Son cuatro cosas, y ninguna es que el sistema funcione.

## 1. Sintaxis YAML de los Compose

```
python tools/validar_apps.py apps/imprenta
```

Los dos archivos parsean y sus servicios se listan:

```
docker-compose.prod.yml -> servicios: ['caddy', 'postgres', 'redis', 'api', 'web']
docker-compose.dev.yml  -> servicios: ['postgres', 'redis', 'api', 'web']
```

Eso **no** dice que los Compose sean correctos. Dice que el archivo es YAML
valido. Un `depends_on` mal escrito, una variable que no existe o un volumen
con nombre equivocado se descubren levantando el contenedor, no parseando el
archivo.

## 2. Sintaxis de shell de los guiones de respaldo

```
bash -n deploy/respaldos/respaldo.sh
bash -n deploy/respaldos/restauracion.sh
```

Ambos pasan. Eso dice que no hay parentesis desbalanceados ni una palabra mal
puesta.

**No dice que el respaldo funcione.** No se ha hecho un `pg_dump`, no se ha
escrito un manifiesto, no se ha borrado nada. La rama de `--force` de la
restauración, que es la que vacia tablas, **no se ha ejecutado nunca**.

## 3. Barrido de caracteres y ortografia

```
python tools/acentuar.py --aplicar apps/imprenta
python tools/validar_apps.py apps/imprenta
```

Los dos pasan sin errores. Revisa: CJK, cirilico y otros alfabetos; palabras
acentuadas a las que les falta la tilde; secretos que parezcan reales; puertos
de datos publicados en producción; y que haya Row Level Security y
`tenant_id` en el código.

Es un control de calidad **estático**. No encuentra un error de ejecución.

## 4. Que los modelos coincidan con las migraciones

Reviso a mano, columna por columna, que cada modelo mapee una tabla y cada
atributo exista en la migración que la crea. Se hizo al escribir, no con una
herramienta.

Ademas, cada `fillable` de cada modelo nombra solo columnas que su migración
crea, y cada relacion apunta a una foreign key existente.

---

# Lo que NO se verifico, y por que importa

## 1. Las migraciones no se aplicaron nunca

**Este es el punto mas importante de todo el documento.**

Hay quince migraciones, y la mas importante es la que activa el aislamiento:

```
000014_activar_row_level_security.php
```

Y las que contienen las reglas del producto:

```
000015_gatillos_de_regla_de_imprenta.php
```

**El SQL no se ha ejecutado contra un PostgreSQL.** Y hay motivos concretos
para dudar de algunas partes:

- Los `CHECK` se crean con `$tabla->check(...)`. La firma exacta y la forma en
  que Laravel la traduce a `ALTER TABLE ADD CONSTRAINT` **cambia entre
  versiones de Laravel y de doctrine/dbal**. Si la versión instalada no acepta
  el segundo argumento con nombre, la migración falla.
- La función `public.app_tenant_id()` tiene un bloque `EXCEPTION` dentro de una
  función `LANGUAGE plpgsql STABLE`. Es valido, pero `STABLE` con una excepcion
  que escribe un mensaje de error **no se ha probado** contra el PostgreSQL de
  la versión elegida.
- Los gatillos se crean con `DB::unprepared()`. Esa forma funciona, pero el
  orden de `CREATE OR REPLACE FUNCTION` y `CREATE TRIGGER` dentro de una misma
  transacción de migración no se ha comprobado en la versión concreta.
- El `CREATE OR REPLACE VIEW` con un `COMMENT ON VIEW` encadenado en el mismo
  `unprepared` tampoco se ha probado.

**Todo esto se va a saber en el primer `php artisan migrate`. Y probablemente
haya que corregir algo.**

## 2. Las pruebas no se corrieron

Hay cinco suites. **Ninguna se ejecuto.**

### Que cubren

**`tests/Unit/CalculadoraDeConsumoTest.php`** - la conversion de unidades.
Nueve pruebas. Comprueban, entre otras cosas, que el mismo papel de 300 g/m2
rinda 140 tarjetas por plancha y 9 hojas A4, y que el gramaje cambie el peso
(84 g contra 210 g por pliego de 0,7 m2) sin cambiar el rendimiento.

**`tests/Unit/CalculadoraDeMermaTest.php`** - la merma. Siete pruebas. La
importante es que **falla cerrado** cuando falta el porcentaje, y dos pruebas
que verifican que un porcentaje fuera de rango se rechaza en vez de recortarse.

**`tests/Feature/Aislamiento/AislamientoDeDatosTest.php`** - el aislamiento
entre imprentas. Nueve pruebas. Comprueban que una empresa no ve los datos de
otra, que no se puede insertar una fila con `tenant_id` ajeno, que la función
de contexto lanza excepcion sin empresa, y que la vista de empresas expone
exactamente tres columnas.

**`tests/Feature/Pruebas/SinPruebaAprobadaTest.php`** - la promesa central.
Ocho pruebas. Incluye una que escribe **directamente en la base**, saltandose
el servicio, para comprobar que el gatillo de PostgreSQL la rechaza.

**`tests/Feature/Entregas/EntregaParcialTest.php`** - las entregas parciales.
Siete pruebas. La del medio es la importante: despues de entregar 8.000 de
20.000, el estado **tiene que ser** `parcialmente_entregado`.

### Por que no se corrieron

No hay PostgreSQL ni PHP en la maquina. Y estas pruebas **no pueden correr en
otro lado**: en SQLite no hay Row Level Security, ni `CHECK`, ni gatillos.

`phpunit.xml` fija `DB_CONNECTION=pgsql` precisamente por eso. Una suite
verde sobre SQLite no comprobaria **nada** de lo que importa.

### Lo que mas probablemente falle

- Los `CHECK` con nombre (mismo problema que las migraciones).
- Las pruebas que dependen de la zona horaria para `pruebaVencida()`: usan
  `Carbon::now()` y comparan contra `now()->subDays(5)`, lo cual es sensible al
  reloj del contenedor.
- El orden de los estados en `pedidoPorEstado`, que depende de como agrupe
  `groupBy` de Eloquent.

## 3. No se construyo ninguna imagen

Se eligieron estas imagenes base, **fijadas por etiqueta y no por digest**:

```
php:8.4-fpm-alpine
node:24.22.0-alpine3.24
postgres:17.6-alpine
redis:8.2-alpine
caddy:2.10-alpine
composer:2
```

**No se comprobo que esas etiquetas existan.** Y no se fijo ninguna por
digest SHA256, que es lo que hay que hacer antes de producción: una etiqueta
puede cambiar de contenido entre la primera y la segunda vez que se tira, y un
despliegue distinto con el mismo nombre de imagen no es reproducible.

También hay dos detalles sin comprobar:

- `COPY composer.json composer.lock* ./` con `composer install` y un `||`
  como alternativa. Si `composer.lock` no existe, `composer install` se
  comporta distinto a `composer update` y la instalación puede resolver
  versiones distintas en cada construccion.
- El `HEALTHCHECK` usa `wget`, que viene en Alpine. En la imagen de la API se
  instalo `apk add` sin `curl`, así que `wget` deberia estar, pero **no se
  comprobo**.

## 4. No se probo la autenticación

El flujo con Authentik esta escrito siguiendo el mismo patron de las demas
aplicaciones de la casa, pero **no se ejecuto nunca** contra una instancia
real.

Pendiente de confirmar:

- Que `AUTHENTIK_ISSUER` con barra final sea exactamente como lo pública esa
  instancia.
- Los nombres de los campos del panel al crear la aplicación, que **cambian
  entre versiones** de Authentik.
- Que el grupo de operadores se devuelva en el reclamo `groups` con el
  formato que espera `VinculadorDeIdentidad`.

## 5. La interfaz no se compilo

No se corrio `npm install`, ni `npm run build`, ni `nuxt typecheck`.

Hay dos cosas que se saben que **no se han revisado** y que hay que mirar al
primer `npm run build`:

- Los tipos de las respuestas de los controladores. Los tipos en
  `app/tipos/api.ts` son **un contrato escrito a mano**, no derivado del
  esquema. Si un nombre de campo no calza, TypeScript lo va a avisar, pero solo
  al compilar.
- `PedidoController::index()` devuelve la paginacion de Eloquent cruda
  (`data` y `meta`), mientras el resto de las pantallas consume objetos
  tipados. Hay que confirmar que el formato es el que espera cada pantalla.

Y algo que no aparece en el chequeo de tipos: **en varias pantallas hay
llamadas a rutas que no existen todavia.** La pantalla de pedidos consulta
`/api/v1/pedidos` y la de producción consulta `/api/v1/produccion`, pero **no
hay ninguna pantalla para crear un pedido o un item**. El recorrido del
documento 06 empieza en el papel y termina en la entrega, pero el alta del
pedido y el alta del item no tienen formulario: hay que hacerlos a mano, con
la API o con el consola de Laravel.

Eso es un hueco conocido, no un error de tipos. Ver la seccion siguiente.

---

# Los huecos conocidos del producto

Cosas que **no estan hechas**, para que nadie las encuentre por sorpresa.

## No hay formulario de alta de pedido ni de item

La API expone la lectura de pedidos, el consumo, la aprobacion, la
anulacion, la producción, las entregas, los papeles, la libreria y los
reportes. **No expone `POST /api/v1/pedidos` ni `POST /api/v1/pedidos/{id}/items`.**

Sin eso, el primer pedido se tiene que crear a mano. El `FormRequest` de items
(`GuardarPedidoItemRequest`) esta escrito, pero **ningun controlador lo usa**.

Esto es lo primero que faltaria para que la aplicación sea operable de punta a
punta sin consola.

## No hay carga de archivos

El diseno vive fuera del sistema, a propósito (ver `07`). Pero **no hay
pantalla para registrar la ruta y la huella**: se hace con `POST
/api/v1/items/{id}/versiones`.

## No hay edicion de catalogo

`PapelController` expone crear y guardar, pero **no hay formulario**. Los
papeles se dan de alta por API o por consola.

---

# Lo que queda pendiente de verificación, y por que

## Pendiente: los CHECK con nombre

**Por que**: la firma de `$tabla->check()` y la traduccion a SQL dependen de la
versión de Laravel y de doctrine/dbal.

**Como se comprueba**: correr las migraciones en PostgreSQL 17. Si falla, ver
el SQL que Laravel genera con `php artisan migrate --pretend`.

## Pendiente: los gatillos

**Por que**: `DB::unprepared()` con PL/pgSQL y `CREATE TRIGGER` nunca se
ejecuto.

**Como se comprueba**: aplicar las migraciones e intentar insertar una orden
de producción sin prueba aprobada. La prueba
`la_base_de_datos_tambien_impide_registrar_produccion_sin_prueba` lo hace
sola.

## Pendiente: el aislamiento con un rol real

**Por que**: las pruebas se correrian con el mismo rol que creo las tablas,
y ese rol es el duena. Por eso existe `FORCE ROW LEVEL SECURITY`, pero **no se
ha comprobado que la política se le aplique a ese mismo rol**.

**Como se comprueba**: `php artisan imprenta:verificar-aislamiento --vacio`
despues de migrar, y leyendo si el `rolbypassrls` y el `rolsuper` del rol en
uso estan en `false`.

## Pendiente: las sumas de SHA-256 de los guiones

**Por que**: `bash -n` no ejecuta nada.

**Como se comprueba**: correr `respaldo.sh` contra una base de prueba, mirar
que el manifiesto tenga una suma de 64 caracteres, y despues correr
`restauracion.sh --simular` sobre el resultado.

## Pendiente: las versiones de las imagenes

**Por que**: no se comprobo que las etiquetas existan ni su contenido.

**Como se comprueba**: `docker pull` de cada una, y despues fijar por digest
con `docker inspect --format='{{index .RepoDigests 0}}'`.

## Pendiente: el formato de la paginacion

**Por que**: `PedidoController::index()` devuelve la estructura cruda de
Eloquent y las pantallas asumen una forma.

**Como se comprueba**: levantar la aplicación, abrir `/pedidos` y `/produccion`
y ver si la tabla muestra lo esperado.

## Pendiente: la zona horaria

**Por que**: el contenedor corre en `America/Santiago` y las pruebas usan
`Carbon::now()`. Una prueba de vencimiento de pruebas puede fallar si el reloj
del contenedor y el del host difieren.

**Como se comprueba**: correr las pruebas con el contenedor de la base en la
misma zona horaria.

---

# Que se tiene que hacer, en orden

```
1. Levantar PostgreSQL 17
2. php artisan migrate --pretend        <- ver el SQL sin aplicarlo
3. php artisan migrate                  <- aqui se descubre si los CHECK y los
                                           gatillos funcionan
4. php artisan imprenta:verificar-aislamiento --vacio
5. php artisan test --testsuite=Unit    <- la conversion, sin base
6. php artisan test --testsuite=Aislamiento
7. php artisan test --testsuite=Pruebas
8. php artisan test --testsuite=Entregas
9. npm install && npm run build         <- aqui aparecen los tipos
10. docker compose up -d                 <- aqui aparecen los Compose
11. Correr el recorrido del documento 06, de punta a punta
12. Fijar las imagenes por digest
13. Probar un respaldo y una restauracion
```

Los pasos 3 y 6 son los que mas probablemente necesiten correccion, y los dos
giran alrededor de lo mismo: SQL que nunca se ejecuto.

---

# Una nota sobre lo que no se invento

Para lo que no se pudo verificar:

- **No hay precios.** Los campos de precio admiten `NULL` y quedan vacios. La
  pantalla los muestra como "sin cargar".
- **No hay merma por omision.** Los porcentajes se piden al guardar el papel y
  pueden quedar en `NULL`; mientras esten en `NULL`, el calculo falla con un
  mensaje que dice que falta cargarlos.
- **No hay factores de conversion guardados.** Se calculan por trabajo.
- **Los plazos de la configuración son valores declarados**, no hechos del
  rubro, y estan marcados como tales en el código.

Lo que si son constantes verificables son las del calculo: el area de una
plancha en metros cuadrados, y el peso que sale de multiplicarla por el
gramaje. Eso es fisica, no una regla del negocio, y se puede comprobar con una
calculadora.

Los gramajes de ejemplo (80, 120 y 300 g/m2) son los que menciona el encargo
como de uso corriente en una PyME. Los porcentajes de merma del seeder estan
marcados como **de ejemplo** en el propio archivo.

---

# Lo que hay que decir si alguien pregunta

> "¿Funciona?"

No se ha ejecutado. El código esta completo y revisado a mano, pero en la
maquina donde se escribio no hay PHP, Docker, Node ni PostgreSQL, así que no
hay migraciones aplicadas, ni pruebas corridas, ni imagenes construidas. La
lista de lo que falta esta arriba y en `00-INDICE.md`.

Decir que funciona seria falso, y en una aplicación cuyo producto es "nada se
imprime sin que el cliente lo haya aprobado", decir que algo que no se probo
funciona es exactamente el error que este sistema existe para evitar.
