# 05. Configuración

Cada variable de entorno, que hace y cual es su valor por omision. La
plantillas estan en `.env.example` (raiz, `api/` y `web/`).

---

# La regla de este proyecto

**No hay ningun valor inventado, y donde hay una omision, esta escrito por
que.**

En una aplicación comercial hay una tentacion fuerte de llenar los espacios
vacios: un 5 % de merma "para que funcione", un plazo de 7 dias "porque es lo
normal". En una imprenta eso no es un detalle: es plata que se pierde sin que
nadie la vea.

Por eso:

- **No hay merma por omision.** Se calcula solo si el papel la trae cargada.
- **No hay precios.** Los campos de precio admiten `NULL` y quedan vacios.
- **No hay plazos presentados como verdad.** Los dias de espera y de aviso
  son valores por omision **declarados**, y estan marcados como tales.

---

# Variables de la aplicación

| Variable | Omision | Que hace |
|---|---|---|
| `APP_NAME` | `Imprenta Hefesto` | Nombre que aparece en la interfaz |
| `APP_ENV` | `local` | `local`, `production` |
| `APP_KEY` | (ninguna) | Cifra las cookies. **Se genera.** Sin ella no hay sesión |
| `APP_DEBUG` | `true` en local, `false` en producción | Con `true`, los errores muestran detalle interno |
| `APP_URL` | `http://localhost:8000` | URL de la API |
| `APP_WEB_URL` | `http://localhost:3000` | Donde vuelve la persona tras entrar |

**`APP_DEBUG` en producción es `false` a propósito**: un error con detalle
puede mostrar rutas internas o consultas SQL, y en esta aplicación esas
consultas incluyen nombres de clientes.

## `APP_KEY`

```
php artisan key:generate
```

Se puede perder sin romper los datos: se genera otra y las sesiones viejas
dejan de servir (nadie pierde datos, se cierra la sesión de todos). Lo que no
se puede perder es la coherencia con lo que esta cifrado: si hubiera datos
cifrados con la clave anterior, esa clave hay que conservarla.

---

# Base de datos

| Variable | Omision | Que hace |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | **No cambiar a `sqlite`** |
| `DB_DATABASE` | `imprenta` | |
| `DB_USERNAME` | `imprenta_app` | **No debe tener `BYPASSRLS` ni ser superusuario** |
| `DB_PASSWORD` | (ninguna en producción) | |
| `DB_HOST` | `postgres` | |
| `DB_PORT` | `5432` | |

## Por que `pgsql` y no `sqlite`

No es por rendimiento. Es porque **todo lo que sostiene el aislamiento esta
en PostgreSQL**:

- Row Level Security con `USING` y `WITH CHECK`.
- `FORCE ROW LEVEL SECURITY`.
- `set_config` con alcance de transacción.
- Los gatillos que impiden imprimir sin prueba aprobada.
- Los `CHECK` que obligan a que los pliegos cuadren.

En SQLite nada de eso existe. La suite de pruebas pasaria **sin comprobar
nada**, y una suite verde sobre SQLite no dice absolutamente nada del
aislamiento. Por eso `phpunit.xml` fija `DB_CONNECTION=pgsql`.

## El rol de la base es la parte critica

Un rol con `BYPASSRLS` o superusuario **ignora todas las políticas**. En ese
caso el código puede estar impecable y el aislamiento es adorno.

```
CREATE ROLE imprenta_app LOGIN PASSWORD '<una larga y unica>';
GRANT CONNECT ON DATABASE imprenta TO imprenta_app;
GRANT USAGE ON SCHEMA public TO imprenta_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO imprenta_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO imprenta_app;
```

Sin `BYPASSRLS`, sin superusuario, sin creacion de bases. Y
`php artisan imprenta:verificar-aislamiento` lo comprueba antes de levantar el
contenedor.

---

# Redis

| Variable | Omision | Que hace |
|---|---|---|
| `REDIS_HOST` | `redis` | |
| `REDIS_PORT` | `6379` | |
| `REDIS_PASSWORD` | (ninguna en producción) | **Obligatoria en producción** |
| `CACHE_STORE` | `redis` | |
| `QUEUE_CONNECTION` | `redis` | |

**Redis sin contrasena es un problema real**: guarda el cache de la
aplicación, que incluye la sesión de Authentik y el documento de
descubrimiento. Es un contenedor sin puertos publicados en esta aplicación,
pero si alguien abre la red interna, entra sin esfuerzo.

---

# Sesión

| Variable | Omision | Que hace |
|---|---|---|
| `SESSION_DRIVER` | `database` | Permite revocar una sesión desde la base |
| `SESSION_COOKIE` | `imprenta_sesion` | **Tiene que coincidir en la API y en Nuxt** |
| `SESSION_SECURE_COOKIE` | `false` en local, `true` en producción | Con `true`, la cookie solo viaja por HTTPS |

El nombre de la cookie es el error clasico: si la API escribe
`imprenta_sesion` y Nuxt lee `laravel_session`, la sesión se escribe en un
lado y se busca en el otro, y el sintoma es "entra y sale".

---

# Authentik

| Variable | Omision | Que hace |
|---|---|---|
| `AUTHENTIK_ISSUER` | (ninguna) | Debe terminar en `/` |
| `AUTHENTIK_CLIENT_ID` | (ninguna) | |
| `AUTHENTIK_CLIENT_SECRET` | (ninguna) | |
| `AUTHENTIK_REDIRECT_URI` | (ninguna) | Debe coincidir exacto con el de Authentik |
| `AUTHENTIK_SCOPES` | `openid profile email` | |
| `AUTHENTIK_ALGORITMO` | `RS256` | |
| `AUTHENTIK_GRUPO_OPERADORES` | `imprenta-operadores` | Grupo que autoriza a entrar |
| `AUTHENTIK_GRUPO_ADMIN` | `imprenta-administradores` | Grupo de administración, para auditar altas |

## La barra del `issuer`

`AUTHENTIK_ISSUER` debe terminar en barra. Authentik lo pública así, y una
diferencia de una barra hace que **todos** los tokens parezcan de otro emisor.
El verificador normaliza con `rtrim` en algunos puntos y compara exacto en
otros: es mejor no depender de esa normalizacion.

## Que se verifica del token

En `VerificadorIdToken`, los cuatro controles:

1. **Firma**, con la llave pública del proveedor. Sin esto, cualquiera
   fabricaria un token de administrador.
2. **Emisor**, que venga de donde dice venir.
3. **Audiencia**, que este emitido para esta aplicación. Sin esta
   comprobacion, un token legitimo de **otra aplicación de Hefesto Studio**
   serviria para entrar a la imprenta.
4. **Tiempo**: expirado o aun no valido, no sirve.

El punto 3 es el que mas se olvida. El token es autentico, pero no es para
aca.

---

# Las reglas de la imprenta

Estan en `api/config/imprenta.php`. **Ninguna es secreta y ninguna define un
dato comercial inventado.**

| Variable | Omision | Que hace |
|---|---|---|
| `IMPRENTA_TOLERANCIA_PIEZAS` | `0` | Piezas de sobreentrega permitidas en la última entrega |
| `IMPRENTA_MERMA_AVISO_PUNTOS` | `5` | Desvio, en puntos porcentuales, que dispara un aviso de merma |
| `IMPRENTA_DIAS_PARA_VENCER_PRUEBA` | `5` | Dias que se espera la respuesta del cliente |
| `IMPRENTA_DIAS_AVISO_ATRASO` | `1` | Dias de anticipacion para avisar de un atraso |
| `IMPRENTA_BYTES_MAXIMOS_ARTE` | `52428800` | Tamano maximo de un archivo de arte |

## `IMPRENTA_TOLERANCIA_PIEZAS`

La única tolerancia que permite el sistema, y por omision es **cero**: sin
sobreentrega.

Si el papel da un sobrante de la última plancha y se quiere entregar de mas,
este es el número. Con cero, una entrega que supera el saldo se rechaza y el
pedido queda "parcialmente entregado". Ver `06-PRIMER-PEDIDO.md`.

## `IMPRENTA_MERMA_AVISO_PUNTOS`

**Es un aviso, no un bloqueo.** Cuando la merma realmente registrada supera
la prevista por mas que esta diferencia en puntos porcentuales, el informe de
merma marca la fila para revisar.

No bloquea nada a propósito: el taller tiene que poder seguir imprimiendo
aunque una tirada salga mala. Si el calculo le impediera trabajar, la gente
buscaria la forma de pasarlo por alto, y esa forma existe siempre.

## `IMPRENTA_DIAS_PARA_VENCER_PRUEBA`

Dias que se espera la respuesta del cliente a una maqueta enviada. Al vencer,
la prueba figura **vencida** en la pantalla.

**Vencida NO es aprobada.** El pedido sigue sin poder imprimirse y hay que
llamar al cliente. Un sistema que aprueba por silencio es un sistema que
reimprime.

El valor de 5 dias es **un valor por omision declarado, no una verdad del
rubro**. El tiempo que un cliente tarda en responder depende de a quien se le
imprime. Hay que ajustarlo por imprenta.

## Lo que NO se configura aquí, y por que

| Dato | Donde vive | Por que no en el entorno |
|---|---|---|
| **Merma** | `papeles.merma_*_porcentaje`, por papel | Es distinto en cada papel y sale del historial de cada tirada. En el entorno seria un número único que no es cierto para nadie. |
| **Factor de rendimiento** | Se calcula por trabajo | Depende de la medida de la pieza, no del papel. Ver `02`. |
| **Precios** | `papeles.precio_referencia_por_kg`, en la base | Es dato comercial de la imprenta y cambia. |
| **Gramajes** | `papeles.gramaje_gm2`, en la base | Son datos del catalogo, no parámetros del sistema. |

Que la merma viva en el catalogo y no en el entorno es lo que hace que se
pueda corregir por papel según lo que se fue observando, que es la única
forma de que un porcentaje de merma signifique algo.

---

# La interfaz

| Variable | Omision | Que hace |
|---|---|---|
| `NUXT_API_BASE` | `http://localhost:8000` | **Privada.** Donde esta la API |
| `NUXT_PUBLIC_BASE` | `http://localhost:3000` | Pública. La direccion del sitio |

`NUXT_API_BASE` es config **privada** a propósito: solo la usa el servidor de
Nuxt para reenviar. El navegador nunca la ve, y por eso **nunca** se escribe
como `NUXT_PUBLIC_*`.

Exponer la direccion de la API seria darle al visitante la direccion interna
del servicio y de paso eludir el intermediario, que es quien lleva la cookie
de sesión.

---

# Lo que el ejemplo carga, y su marca

`php artisan db:seed` crea datos de ejemplo. Trae su marca en el código, y
esto es lo que trae:

| Cosa | Valor | Estado |
|---|---|---|
| Plancha 70 x 100 cm | `700 x 1000 mm` | Medida de uso corriente, fijada para probar |
| Plancha 66 x 94 cm | `660 x 940 mm` | Idem |
| Offset 80 g/m2 | `offset`, 80 | De los tres pedidos en este encargo |
| Offset 120 g/m2 | `offset`, 120 | Idem |
| Opal 300 g/m2 | `opal`, 300 | Idem |
| Merma de prueba | `3,00 %` | **EJEMPLO, no recomendado** |
| Merma de plancha | `2,00 %` | **EJEMPLO, no recomendado** |
| Pliegos por paquete | `NULL` | Cada proveedor es distinto |
| Precios | `NULL` | Los carga la imprenta |
| Stock de libreria | `0` | Sin movimientos, no hay stock |

Los gramajes de 80, 120 y 300 g/m2 son los que menciona este proyecto como de
uso corriente en una PyME. Los dos porcentajes de merma son **números de
partida para probar el calculo**, y estan marcados como tales en el seeder. No
son una recomendacion: el valor real sale de medir las propias tiradas, que
es justo lo que hace el informe de merma.

Los datos de ejemplo **no sirven para producción**. La empresa del seeder se
llama `imprenta-demo` y es obvio que hay que borrarla.
