# 07. Seguridad

Lo que protege esta aplicación, y lo que protege cada capa. Sin adornos: si
algo no esta en esta lista, no lo esta.

---

# 1. El aislamiento entre imprentas

Lo mas importante, y lo que mas se ha roto en otras aplicaciones. Va en
detalle en `03-MULTITENANCY-Y-ROLES.md`; el resumen:

- `tenant_id` en las doce tablas de empresa.
- Row Level Security con `USING` **y** `WITH CHECK`.
- `FORCE ROW LEVEL SECURITY`, sin el cual el duena de la tabla se salta la
  política.
- Contexto con `set_config('app.tenant_id', ..., true)`, de alcance de
  transacción.
- Una función de contexto que falla cerrado con excepcion.

## La compensacion por la tabla de empresas

`tenants` no lleva RLS porque se consulta en el acceso, sin sesión. Se compensa
con la vista `vw_impresas_publicas`, de tres columnas, que el selector de
acceso consulta **de verdad**.

Que sea de verdad importa tanto como que exista: una vista creada y no
consultada es una promesa de protección que no existe. Aca la lista de empresas
sale **solo** de la vista, y una prueba comprueba que siga teniendo tres
columnas.

---

# 2. Los puertos

**En producción solo el proxy pública puertos.**

| Servicio | Puerto | Publicado |
|---|---|---|
| `caddy` | 80, 443 | **si** |
| `web` | 3000 | no |
| `api` | 8080 | no |
| `postgres` | 5432 | **no** |
| `redis` | 6379 | **no** |

PostgreSQL, Redis, la API y la web se hablan por nombre de servicio dentro de
la red de Docker. No hay forma de llegar a ellos desde internet.

Y la API **no necesita** estar expuesta: el navegador nunca habla con ella, va
a la interfaz y la interfaz reenvia. Ver `01-ARQUITECTURA.md`.

## El rol de la base

Un rol con `BYPASSRLS` o superusuario ignora todas las políticas. El
`entrypoint` corre `imprenta:verificar-aislamiento` antes de servir trafico y
**no levanta el contenedor** si falla.

Un contenedor que sirve con la RLS apagada no deja rastro en un log de exito.
Se entera uno cuando un cliente llama.

---

# 3. Las sesiones

## La cookie

```
SESSION_COOKIE=imprenta_sesion
SESSION_SECURE_COOKIE=true        (en produccion)
SESSION_HTTPONLY=1                (en php.ini)
SESSION_USE_STRICT_MODE=1         (en php.ini)
```

- `HttpOnly`: el JavaScript de la pagina no puede leerla. Sin esto, un error
  de scripting expone la sesión.
- `Secure`: solo viaja por HTTPS. Sin esto, alguien en un cafe con la misma
  red la lee en texto plano.
- `use_strict_mode`: el navegador tiene que mandar el id de sesión que le dio
  el servidor. Sin esto, un id inventado por alguien mas seria aceptado.
- `SameSite=Lax`: la cookie no viaja en peticiones iniciadas por otro sitio.

## El nombre tiene que coincidir

`SESSION_COOKIE` en la API y el nombre que lee el intermediario de Nuxt tienen
que ser iguales. Si no, la cookie se escribe en un lado y se busca en el otro,
y el sintoma es "entra y sale".

## Firma de sesión nueva

`callback()` regenera la sesión despues de autenticar:

```
$request->session()->regenerate();
```

Sin eso, un atacante que fije un id de sesión antes de que la persona entre
puede pegar su sesión a la de la victima. Es el secuestro de sesión, y el
`regenerate()` lo cierra.

Al salir se hace `invalidate()` y `regenerateToken()`.

---

# 4. La autenticación

La imprenta no guarda contrasenas. La identidad la resuelve Authentik.

## Lo que se verifica del token

En `VerificadorIdToken`:

1. **Firma** con la llave pública del proveedor. Sin esto, cualquiera
   fabricaria un token de administrador.
2. **Emisor**, que venga de donde dice decir.
3. **Audiencia**, que el token sea para esta aplicación. **Sin esto, un token
   legitimo de otra aplicación de Hefesto Studio serviria para entrar a la
   imprenta.**
4. **Tiempo**: expirado o aun no valido, no sirve.

El punto 3 es el que se olvida. El token es autentico; no es para aca.

## El enlace con la base

`VinculadorDeIdentidad` hace tres comprobaciones:

- Que el token traiga correo.
- Que la persona pertenezca al grupo de operadores de Authentik.
- **Que exista una fila `usuarios` para esa empresa y ese correo.**

La última es la importante: **esta aplicación no crea personas por su cuenta**.
Un token valido de alguien que no esta dado de alta en la imprenta no entra,
aunque el token sea impecable.

Y el alta no se hace desde la aplicación: se hace con un comando, para que
quede nombre, fecha y quien la tomo. Ver `03`.

## `state` y `nonce`

El acceso genera un `state` y un `nonce`, los guarda en la sesión y los
comprueba en el retorno. Sin ellos, un código de acceso capturado en transito
se podria reenviar.

---

# 5. La autorización

## En la API, no en la interfaz

Cada ruta con sesión pasa por una `Policy`. La interfaz decide que botones
muestra, pero eso es una cortesia: si alguien saca un boton con las
herramientas del navegador, la API responde igual.

## 404 y no 403

Cuando una `Policy` rechaza, la respuesta es **404, no 403**.

Con 403 se confirma que el recurso existe pero que no se tiene permiso. Eso
permite enumerar: se prueban identificadores y las respuestas en 403 dicen que
hay algo ahi. Con 404, no se puede distinguir "no existe" de "no es tuyo".

El handler esta en `bootstrap/app.php`:

```
$exceptions->render(function (AuthorizationException $e, $request) {
    if ($request->is('api/v1/*')) {
        return response()->json(['mensaje' => 'No encontrado.'], 404);
    }
    return null;
});
```

## CSRF

Laravel protege los metodos que modifican. El token viaja en la cabecera
`X-CSRF-TOKEN`, y el intermediario de Nuxt la copia al reenviar.

La excepcion es deliberada y acotada:

```
api/v1/salud       (es una lectura)
api/v1/empresas    (es una lectura, sin sesion)
```

Las rutas de escritura de la aplicación exigibles. La excepcion para el
callback de Authentik es justamente que el proveedor devuelve un `GET` con un
código, no una escritura de sesión.

---

# 6. El diseno del cliente vive fuera

**El archivo de arte NO esta en esta aplicación.** Vive en un servidor de
archivos o en la nube.

| Que se guarda | Que no |
|---|---|
| El nombre del archivo | El archivo |
| La ruta | El contenido |
| La versión | |
| El hash SHA-256 | |

## Por que

- **Tamano.** Un archivo de imprenta a alta resolución pesa; la base de datos
  no es un almacen de blobs.
- **Versionado.** El cliente pide cambios: v1, v2, v3. El sistema guarda las
  tres y sabe cual se aprobo.
- **Evidencia.** El hash permite demostrar meses despues que el archivo
  impreso es el que el cliente aprobo.
- **Datos personales.** El diseno de un cliente puede tener sus datos:
  nombres, cargos, telefonos. Duplicarlo en la base de la aplicación amplia
  el alcance del respaldo y del acceso.

## El hash si importa

`sha256` es única y nullable. Cuando se carga, es la forma de responder a un
reclamo seis meses despues: "este es el archivo que se imprimio, y esta es su
huella".

Sin el hash, la discusion es entre dos versiones del diseno que el sistema no
puede distinguir.

---

# 7. Lo que se registra

`AuditarOperacion` registra las operaciones que mueven plata o cambian lo que
la imprenta le respondio al cliente: aprobacion de pruebas, producción,
entregas, pedidos y movimientos de libreria.

**No registra** el contenido del arte ni el del pedido. Solo la ruta, el
usuario, el rol, el metodo y el código de respuesta.

Y no registra las lecturas: un registro lleno de "abrió el pedido 001" todos
los dias es ilegible, y un registro ilegible no sirve para auditar nada.

---

# 8. El proxy

Caddy es el único que recibe peticiones del exterior.

## Cabeceras

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options:    nosniff
X-Frame-Options:           DENY
Referrer-Policy:           strict-origin-when-cross-origin
-Server
```

`nosniff` impide que el navegador adivine el tipo de un archivo, que es como
se lograria ejecutar algo subido por error. `DENY` impide que la aplicación se
muestre dentro de un marco de otro sitio (*clickjacking*: una pantalla
invisible que hace clic por el operador).

## TLS

Caddy pide y renueva el certificado solo. Por eso el dominio tiene que apuntar
a este servidor **antes** del primer arranque: si no, falla el arranque y es
mas dificil diagnosticar que configurarlo despues.

---

# 9. Lo que no se hizo, y por que

- **No hay cifrado en la base.** Los datos de los clientes (nombre, correo,
  telefono) van en claro en la base. Cifrarlos con la base cambia la pregunta
  entera: el respaldo también habria que descifrarlo. Para una PyME de esta
  escala, el control de acceso y el respaldo seguro pesan mas que el cifrado
  en reposo. Es una decisión, no un olvido.

- **No hay doble factor.** Depende de como este configurado Authentik, que es
  donde deberia resolverse.

- **No hay auditoria completa de lectura.** Se registra lo que cambia, no lo
  que se mira. Saber quien consulto el pedido de un cliente concreto es un
  requisito que depende del contrato con ese cliente.

- **No hay cifrado en los respaldos.** El respaldo sale de un servidor con
  acceso restringido a una carpeta de permisos `750`. Si se copia a un
  dispositivo externo, conviene cifrarlo alla.

---

# 10. Comprobaciones antes de ir a producción

```
php artisan imprenta:verificar-aislamiento
php artisan imprenta:verificar-aislamiento --vacio
```

Y a mano:

- Que la cookie de sesión no se pueda leer desde JavaScript.
- Que la aplicación no se pueda mostrar dentro de un iframe.
- Que `https://imprenta.ejemplo.cl:5432` **no** responda desde fuera.
- Que `/api/v1/yo` devuelva 401 sin sesión.
- Que `/api/v1/pedidos/<id-de-otra-empresa>` devuelva **404**, no 403.
- Que `APP_DEBUG` este en `false`.
- Que el `.env` real no este en el repositorio ni en la imagen.
