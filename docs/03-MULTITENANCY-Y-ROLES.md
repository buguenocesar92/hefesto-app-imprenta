# 03. Multitenancy y roles

Como una imprenta ve sus datos y no los de otra, y quien puede hacer que.

Este documento describe **el mismo patron que usan el resto de las
aplicaciones de Hefesto Studio**. No es una adaptacion: es el mismo. Cambiarlo
seria inventar un cuarto esquema de aislamiento, que es precisamente el
defecto que hay que evitar.

---

# El aislamiento vive en la base de datos

## Las tres piezas, y las tres hacen falta

### 1. La columna

Toda tabla de empresa lleva `tenant_id`. Es lo que la política va a comparar.

### 2. La política, con `USING` y `WITH CHECK`

```
ALTER TABLE public.pedidos ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.pedidos FORCE  ROW LEVEL SECURITY;

CREATE POLICY pedidos_aislamiento ON public.pedidos
    FOR ALL
    TO PUBLIC
    USING      (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

`USING` decide que filas se pueden **leer**. `WITH CHECK` decide que filas se
pueden **escribir**.

El `WITH CHECK` es el que se suele olvidar, y es la mitad importante: sin el,
alguien que tenga legida una fila legitima podria insertar una fila con
`tenant_id` de otra empresa. El `USING` solo protege la lectura.

### 3. `FORCE ROW LEVEL SECURITY`

Sin esta linea, la RLS **no se aplica al dueño de la tabla**. Y las
migraciones corren con el rol que creo la tabla, o sea el duena. Es decir:
sin `FORCE`, el aislamiento seria falso justo en el camino normal, que es
correr migraciones y ejecutar tareas.

Esta linea es la que se olvida. Por eso `imprenta:verificar-aislamiento` la
comprueba tabla por tabla y no solo "que haya políticas".

## El contexto: `set_config(..., true)`

```
DB::selectOne('SELECT set_config(?, ?, true)', ['app.tenant_id', $tenantId]);
```

El `true` es lo que pone el alcance de **TRANSACCIÓN**: el valor desaparece
cuando termina la transacción.

Sin el `true` (que es el omision de PostgreSQL), el valor queda pegado a la
conexión y se loeria a la siguiente peticion que use el mismo enlace del pool,
que puede ser de **otra empresa**. En un sistema con pocas consultas no se
nota; con las colas de trabajo aparece como "un cliente vio los pedidos de
otro", sin patron y sin forma de reproducir.

Por eso no es un detalle de estilo: es la garantia.

Lo escribe `ContextoTenant::ejecutarAislado()`, y el middleware
`ResolverTenant` lo llama en cada peticion con sesión.

## La función de contexto falla cerrado

```
CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid AS $$
BEGIN
    RETURN NULLIF(current_setting('app.tenant_id', true), '')::uuid;
EXCEPTION WHEN others THEN
    RAISE EXCEPTION USING
        ERRCODE = '42501',
        MESSAGE = 'No hay empresa en el contexto de la sesion.',
        HINT = 'Falta fijar app.tenant_id con set_config(..., true).';
END;
$$ LANGUAGE plpgsql STABLE;
```

**Por que lanza excepcion y no devuelve `NULL`**: con `NULL`, la política
`tenant_id = NULL` evalua a `NULL`, y en el `WHERE` de un `SELECT` eso
significa "no traer la fila". Es decir, también oculta filas.

La diferencia esta en la respuesta: con `NULL`, el sistema seguiria
respondiendo 200 con una lista vacia, y el ticket "no veo los pedidos" se
pierde mucho mas tarde que un error claro. Ante la duda, cortar.

## Las tablas protegidas

```
usuarios              papeles               artes
planchas              clientes              pruebas
                      pedidos               ordenes_produccion
                      pedido_items          entregas
articulos_libreria    movimientos_libreria
```

---

# La tabla de empresas NO lleva RLS, a propósito

`tenants` se consulta en el acceso: cuando alguien elige su empresa en la
pantalla de entrada todavia no hay sesión, y por lo tanto no hay
`app.tenant_id` que poner. Si tuviera política, la consulta no devolveria
nada y nadie podria entrar nunca.

Es la única excepcion, y por lo tanto hay que compensarla bien.

## La compensacion: `vw_impresas_publicas`

```
CREATE OR REPLACE VIEW public.vw_impresas_publicas AS
    SELECT slug, nombre, activo
      FROM public.tenants;
```

Tres columnas. Ni una mas.

**Por que esas tres y no todas**: la vista alimenta un selector de acceso
público, sin sesión. Si expusiera el `id`, el `razon_social` o algun dato
interno, alguien podria enumerar el sistema y aprender de empresas que no son
suyas sin autenticarse. Tres columnas es lo justo para elegir a donde entrar.

**No lleva `WHERE activo = true`**: el filtro se aplica en la API, y así el
código dice exactamente lo mismo que dice la vista. Filtrar en los dos lados
es peor que filtrar en uno, porque un lector tiene que mirar los dos para
saber que pasa.

## Y la vista SE USA DE VERDAD

Esta es la parte que en otras aplicaciones quedo en promesa. Aca:

```
web  --GET /api/v1/empresas-->  API
                                  |
                                  v
                    AuthController::empresas()
                                  |
                                  v
                SELECT slug, nombre, activo
                  FROM public.vw_impresas_publicas
```

Y el resultado es lo que muestra `web/app/pages/entrar.vue` como selector de
empresa.

No hay ningun otro camino para obtener la lista de empresas: no existe un
`/tenants`, y la tabla `tenants` no se expone en ninguna otra ruta.

La prueba `la_vista_de_empresas_expone_solo_tres_columnas` en
`tests/Feature/Aislamiento/AislamientoDeDatosTest.php` consulta
`information_schema.columns` y falla si alguien le agrega una columna. Así
que la restriccion no depende de que nadie se acuerde: depende de una prueba
que se corre.

---

# Los roles

## Los cuatro

| Rol | Que puede | Que NO puede |
|---|---|---|
| `auditor` | Ver todo | Cambiar nada |
| `preparador` | Crear y editar pedidos, subir artes, registrar producción, registrar entregas, mover stock de libreria | Aprobar pruebas, anular pedidos, tocar catalogos |
| `supervisor` | Todo lo del preparador, mas registrar aprobaciones y rechazos de pruebas, y anular pedidos | Tocar catalogos |
| `administrador` | Todo | |

## Por que la aprobacion de pruebas es de supervisor

Una pregunta tipica al leerlo: "¿el supervisor aprueba los disenos?"

**No.** La aprobacion la da el **cliente**. Lo que el supervisor hace es
**registrar** la respuesta del cliente, y `pruebas.aprobada_por` guarda su
nombre.

La separacion importa porque son dos cosas distintas:

- **Aprobar** es un acto comercial con el cliente, y lo decide el cliente.
- **Registrar** es un acto interno que dice "el cliente dijo que si el dia X",
  y de eso hay que poder responder meses despues cuando el cliente diga que
  el nunca vio esa maqueta.

Que quede un solo nombre no alcanza para las dos cosas: hace falta saber
quien estaba, cuando, y sobre que versión del archivo. Las tres cosas estan.

Por eso un preparador **no** puede registrar aprobaciones: podria cerrar el
pedido por su cuenta, y entonces el sistema deja de garantizar que un humano
miró una maqueta antes de imprimir 5.000 tarjetas.

## Por que anular es de supervisor

Anular es quitarle una pieza al cliente. Es la decisión que despues genera un
reclamo, y la respuesta la tiene que dar alguien con nombre. El preparador
prepara pedidos; el supervisor responde por lo prometido.

---

# Quien puede hacer cada cosa

| Accion | Rol minimo | Política |
|---|---|---|
| Ver pedidos, papeles, clientes | cualquiera autenticado | `PedidoPolicy::view` |
| Crear y editar pedidos | preparador | `PedidoPolicy::create` |
| Aprobar un pedido | supervisor | `PedidoPolicy::aprobar` |
| Anular un pedido | supervisor | `PedidoPolicy::anular` |
| Registrar entrega | preparador | `PedidoPolicy::registrarEntrega` |
| Subir una versión de arte | preparador | `PruebaPolicy::create` |
| Registrar aprobacion o rechazo | supervisor | `PruebaPolicy::registrarAprobacion` |
| Registrar producción | preparador | `OrdenProduccionPolicy::registrar` |
| Ver y editar papeles | ver cualquiera, editar administrador | `PapelPolicy` |
| Mover stock de libreria | preparador | `ArticuloLibreriaPolicy::registrarMovimiento` |

## La autorización esta en la API, no en la interfaz

`useSesion()` decide que botones se muestran. Eso es una cortesia para no
ofrecer algo que va a ser rechazado.

**La autorización real es la `Policy`, en el servidor.** Si alguien saca un
boton con el Developer Tools, la API responde igual. Ver
`07-SEGURIDAD.md`.

---

# El acceso pregunta por la empresa antes que por la persona

`usuarios` tiene `UNIQUE (tenant_id, email)`, no `email` global. El mismo
correo puede tener N filas, una por imprenta, con roles distintos.

Por eso el acceso es:

1. Eligiendo la empresa en la pantalla de entrada (la lista sale de la vista).
2. Recien ahi se pide la cuenta corporativa de Authentik.
3. `VinculadorDeIdentidad` busca la fila con **esa** empresa y **ese** correo.

Si el acceso no preguntara, habria que adivinar a que empresa entra alguien.
Y adivinar a que empresa entra alguien es exactamente el error que el
aislamiento evita en todos los demas casos.

## Quien puede entrar, y a donde

El alta no se hace desde la aplicación. El comando es:

```
php artisan imprenta:crear-persona \
    --empresa=imprenta-demo \
    --correo=persona@ejemplo.cl \
    --nombre="Nombre Completo" \
    --rol=preparador
```

Que sea un comando y no un boton es a propósito: el alta de una persona con
acceso a los pedidos de los clientes es una decisión administrativa que tiene
que dejar nombre, fecha y quien la tomo. Un boton es facil de dar y facil de
abusear.

Y esta aplicación **no crea personas por su cuenta**: si el correo no existe
para esa empresa, el acceso se rechaza, aunque el token sea valido.

---

# Verificar que todo esto esta activo

```
php artisan imprenta:verificar-aislamiento
php artisan imprenta:verificar-aislamiento --vacio
```

El comando comprueba, en este orden:

1. Que el rol de la conexión **no** tenga `BYPASSRLS` ni sea superusuario.
   Con cualquiera de las dos, PostgreSQL ignora todas las políticas y el
   aislamiento es adorno, por muy impecable que este el código.
2. Que las doce tablas tengan RLS **habilitada y forzada**.
3. Que exista al menos una política de RLS en el esquema `public`.

Con `--vacio` ademas intenta leer con un `tenant_id` que no corresponde a
nadie y verifica que devuelva cero filas.

## Se corre solo al arrancar el contenedor

`deploy/api/docker/entrypoint.sh` lo corre despues de las migraciones y
**antes** de servir trafico:

```
php artisan imprenta:verificar-aislamiento || exit 1
```

Si falla, el contenedor no levanta. Es deliberado: un contenedor que sirve con
la RLS apagada muestra los pedidos de todas las imprentas, y eso no se
detecta en un log de exito. Se detecta cuando un cliente llama.

También corre **todos los dias** a las 06:30, desde `routes/console.php`. Una
migración que se agregue despues y se olvide de activar la RLS es un silencio
peligroso, y la tarea de las 06:30 lo delata.
