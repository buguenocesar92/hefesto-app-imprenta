# 01. Arquitectura

Aplicación propia de Hefesto Studio. Tres piezas y un proxy, como el resto de
las aplicaciones de la casa, sin variantes.

---

## Las piezas

```
Internet
   |
   |  HTTPS (443) y HTTP (80) para redirigir
   v
+-----------------------+
|  caddy                |   el unico que publica puertos
|  deploy/caddy/Caddyfile|
+-------+---------------+
        |  red interna de Docker
        v
+-----------------------+
|  web  (Nuxt 3)        |   puerto 3000, NO publicado
|  interfaz + BFF       |
+-------+---------------+
        |  http://api:8080, con la cookie de sesion
        v
+-----------------------+
|  api  (Laravel)       |   puerto 8080, NO publicado
|  PHP 8.4 + php-fpm    |
+-------+---------------+
        |
        +---- postgres:5432  (NO publicado)
        |
        +---- redis:6379      (NO publicado)

Ademas:  Authentik, fuera de esta red. La API sale a Internet para hablar con
el proveedor de identidad y recibe el retorno en /api/v1/acceso/callback.
```

---

## POR QUE EL NAVEGADOR NO HABLA CON LA API

Es la decisión de arquitectura mas importante, y hay tres razones.

**1. La sesión vive en el servidor.** El rol con el que se autoriza cada
accion esta en una fila de `usuarios`, y la sesión es una cookie firmada por
el servidor de Laravel. Si el navegador hablara directo con la API, la cookie
no viajaria y ademas habria que resolver el problema del origen: dos
dominios, CORS y credenciales.

**2. La direccion de la API no se expone.** `NUXT_API_BASE` es config
privada del servidor de Nuxt. El navegador no la ve nunca. No se pública en
el HTML ni en el JavaScript del cliente.

**3. El contexto de empresa no lo elige el navegador.** El "intermediario"
(`server/api/[...ruta].ts`) copia **solo** la cookie de sesión y el token
CSRF. No copia ninguna cabecera de empresa, y a propósito: si el navegador
pudiera mandar "quiero ver los datos de la imprenta X", el aislamiento de la
base seria decorativo. La empresa la saca la API de la sesión.

Como consecuencia, la API no necesita estar publicada: no hay forma de
llegarle desde internet salvo por el proxy.

---

## Por que las reglas viven donde viven

El error caro del rubro de la imprenta es reimprimir lo que el cliente no
aprobo. Esa regla no puede depender de que cada pantalla la recuerde.

| Regla | Donde vive | Por que ahi |
|---|---|---|
| No se aprueba un pedido sin pruebas | `ServicioDeAprobacion` (código) | Da un mensaje que el taller entiende |
| No se registra producción sin prueba | `ServicioDeProduccion` (código) | Idem, y calcula los pliegos |
| No se inserta producción sin prueba | Gatillo PostgreSQL (base) | El código se puede saltar; el gatillo no |
| El saldo no se puede volver "completo" | Estado derivado, no columna | Un saldo guardado se desactualiza |
| Una empresa no ve los datos de otra | Row Level Security | Vive junto a los datos |
| El rol de la base no puede evadir la RLS | Verificación al arrancar | Con BYPASSRLS todo lo anterior es adorno |

La duplicación del gatillo no es desconfianza del código: es que cada capa
falla distinto. El código da un mensaje util; la base impide el rodeo.

---

## Como se mueve una peticion

```
1. El navegador pide /api/v1/pedidos
2. Caddy recibe y aplica cabeceras de seguridad
3. Nuxt (BFF) recibe, copia la cookie de sesion, reenvia a http://api:8080
4. Laravel corre el middleware:
     a. ResolverTenant  -> con la sesion, fija app.tenant_id con
                           set_config(..., true) DENTRO de una transaccion
     b. AuditarOperacion -> registra la operacion si cambia algo
     c. auth            -> exige sesion
5. El controlador autoriza contra la Policy
     - si la Policy rechaza, se responde 404, no 403
6. La consulta sale a PostgreSQL, donde la politica filtra por tenant_id
7. La respuesta vuelve por Nuxt al navegador
```

El paso 6 es el que importa: aunque el controlador se olvidara de filtrar,
la base no devuelve filas de otra empresa.

Ver `03-MULTITENANCY-Y-ROLES.md`.

---

## Estructura de carpetas

```
api/
  app/
    Console/Commands/     verificacion de aislamiento, alta de personas,
                          revision de estados
    Enums/                estados de pedido y de prueba, roles, tipo de papel
    Exceptions/           ErrorDeImprenta
    Http/
      Controllers/        una por area del negocio
      Middleware/         ResolverTenant, AuditarOperacion
      Requests/           validacion de entrada
    Jobs/                 recalculo diferido del estado de un pedido
    Models/               uno por tabla
    Policies/             quien puede hacer que
    Services/
      Auth/               enlace con Authentik
      Entregas/           servicio de entregas y saldo
      Papel/              calculo de consumo y de merma (el corazon)
      Pedidos/            servicio de aprobacion
      Produccion/         servicio de produccion
      Tenancy/            ContextoTenant
    Support/              Cantidad: redondeos del taller
  database/
    migrations/           15 migraciones
    factories/ seeders/
  routes/                 web.php, console.php
  tests/                  Unit, Feature

web/
  app/
    assets/css/           estilos propios
    composables/          useSesion
    layouts/              default.vue
    middleware/           auth.ts
    pages/                entrar, index, pedidos, pruebas, papeles,
                          produccion, entregas, merma, libreria, reportes
    tipos/                contrato escrito de la API
  server/api/             BFF

deploy/
  api/                    Dockerfile y configuracion de php-fpm, nginx, entrypoint
  web/                    Dockerfile
  caddy/                  Caddyfile
  respaldos/              respaldo.sh, restauracion.sh
  docker-compose.dev.yml
  docker-compose.prod.yml
```

---

## La cola y lo que se difiere

Hay un solo trabajo diferido: `ActualizarEstadoPedidoJob`, que recalcula el
estado de un pedido.

Es deliberadamente **secundario**. El estado se recalcula de forma sincrona
al registrar cada entrega, porque el mostrador tiene que ver el estado
al instante: si se difiere, el que entrega ve un estado viejo y puede
creer que el pedido quedo cerrado.

El Job existe para los casos en que el recalculo viene de otro lado (una
correccion masiva, una restauración) y se encola para que no bloquee a quien
lo lanzo. Es el mecanismo de respaldo, no el camino normal.

---

## Una decisión que se nota: el diseno no esta aca

El archivo del cliente (el arte) vive FUERA del sistema, en un servidor de
archivos o en la nube. Aca se guarda el nombre, la ruta, la versión y el
hash SHA-256.

Razones:

- **Tamano.** 5.000 tarjetas a alta resolución pesan; la base de datos no es
  un almacen de blobs.
- **Versionado.** El cliente pide cambios: el arte v1, v2, v3. El sistema
  guarda las tres y sabe cual se aprobo.
- **Evidencia.** El hash permite demostrar meses despues que el archivo
  impreso es el que el cliente aprobo.

Ver `07-SEGURIDAD.md`.

---

## Diagramas

Todos los diagramas de esta documentacion usan ASCII puro. Los caracteres de
dibujo Unicode rompen el guardado en algunos editores de consola y no
aparecen en la salida de un log: se ven como cuadros vacios justo cuando uno
esta intentando leer un error.
