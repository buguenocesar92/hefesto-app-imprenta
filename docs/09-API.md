# 09. API

Todas las rutas bajo `/api/v1`. El navegador nunca habla con la API: va a la
interfaz y la interfaz reenvia. Ver `01-ARQUITECTURA.md`.

---

# Convenciones

## Formato

JSON en las dos direcciones, `UTF-8`. Salvo la salud, que también es JSON.

## Códigos de respuesta

| Código | Cuando |
|---|---|
| 200 | Ok |
| 201 | Creado |
| 302 | El acceso redirige a Authentik |
| 401 | Sin sesión |
| 404 | **No existe, o no es tuyo** |
| 419 | Sesión expirada, hay que recargar |
| 422 | Error de regla de la imprenta, o validación |
| 502 | La API no respondio |

## Por que 404 y no 403

Cuando una `Policy` rechaza, la respuesta es **404, no 403**.

Con 403 se confirma que el recurso existe pero que no hay permiso, lo que
permite enumerar identificadores y aprender que hay algo ahi. Con 404, "no
existe" y "no es tuyo" son la misma respuesta y no se puede distinguir.

Es la única respuesta correcta cuando el recurso pertenece a otra empresa.

## La sesión

Cookie `imprenta_sesion`. Se obtiene entrando por `/api/v1/acceso`, que
redirige a Authentik.

## CSRF

Las escrituras exigen el token CSRF en la cabecera `X-CSRF-TOKEN`. Lo pone el
intermediario de Nuxt automáticamente.

---

# Rutas públicas

Sin sesión. Ninguna toca una tabla protegida.

### `GET /api/v1/salud`

Comprobacion de salud. **No es un chequeo de ruido**: distingue "el proceso
vive" de "el sistema sirve", porque son dos cosas distintas y confundirlas
deja el sistema caido con la luz en verde.

```json
{
  "estado": "ok",
  "base_de_datos": true,
  "aislamiento_activo": true,
  "detalle": null,
  "momento": "2026-10-18T03:00:12-0000"
}
```

`aislamiento_activo` comprueba que las doce tablas tengan RLS **habilitada y
forzada**. Devuelve **503** si algo falla.

### `GET /api/v1/empresas`

Empresas para el selector de acceso. **Lee la vista `vw_impresas_publicas`**,
que expone solo tres columnas y solo de empresas activas.

```json
{
  "empresas": [
    { "slug": "imprenta-demo", "nombre": "Imprenta de Ejemplo" }
  ]
}
```

Es la **única** fuente de la lista de empresas: no existe ningun `/tenants`.
Por eso la vista no es adorno.

### `GET /api/v1/acceso?empresa=<slug>`

Parte el acceso. Guarda `state` y `nonce` en la sesión y redirige a Authentik.

Sin `?empresa=`, el flujo sigue igual; es el selector el que casi siempre la
envia, porque el correo es único por empresa.

### `GET /api/v1/acceso/callback`

Retorno de Authentik. Verifica el token, vincula la identidad con un
`usuarios`, regenera la sesión y redirige a `APP_WEB_URL`.

Si algo falla, redirige a `/entrar?error=<mensaje>`.

---

# Sesión

### `GET /api/v1/yo`

Quien esta operando, con lo que su rol le permite.

```json
{
  "id": "9f2c...",
  "nombre": "Mesa de trabajo",
  "correo": "preparador@imprenta.ejemplo.cl",
  "rol": "preparador",
  "empresa": "Imprenta de Ejemplo",
  "puede_aprobar_pruebas": false,
  "puede_registrar_produccion": true,
  "puede_anular_pedidos": false,
  "puede_administrar_catalogos": false
}
```

**Lo que la interfaz muestra sale de aca, pero la autorización no**: la
deciden las `Policy`, en el servidor. Esta respuesta es para no ofrecer
botones que van a ser rechazados.

### `POST /api/v1/salir`

Cierra la sesión, invalida la cookie y regenera el token CSRF.

---

# Pedidos

### `GET /api/v1/pedidos?estado=<estado>`

Lista paginada, 50 por pagina. Opcionalmente filtrada por estado.

### `GET /api/v1/pedidos/{id}`

El pedido completo, con items, pruebas y entregas.

```json
{
  "numero": "PED-0007",
  "estado": "aprobado",
  "cantidad_pedida": 20000,
  "cantidad_entregada": 8000,
  "saldo": 12000,
  "aprobado_por": "Supervision de Ejemplo",
  "puede_imprimirse": true,
  "piezas_pendientes": [],
  "items": [
    {
      "descripcion": "Tarjetas de presentacion",
      "cantidad_unidades": 20000,
      "medida": "90 x 50 mm",
      "papel": "Opal 300 g/m2, plancha 70 x 100 cm",
      "tiene_prueba_aprobada": true,
      "prueba_vencida": false
    }
  ]
}
```

`puede_imprimirse` y `piezas_pendientes` vienen del servidor. La interfaz no
los deduce del estado: el estado puede ser `aprobado` y aun así quedar una
pieza sin aprobar si alguien escribio el estado a mano.

### `POST /api/v1/pedidos/{id}/aprobar`

**Solo supervisor o administrador.**

Aprueba el pedido **si y solo si** todos sus items tienen una prueba aprobada.

Si falta alguna:

```
422
{
  "mensaje": "No se puede aprobar el pedido: hay piezas sin prueba aprobada.
               \"Tarjetas de presentacion\" (version 1 de la pieza pendiente).",
  "codigo": "pedido_sin_pruebas_aprobadas"
}
```

### `POST /api/v1/pedidos/{id}/esperar-aprobacion`

Marca el pedido como a la espera de la respuesta del cliente.

### `POST /api/v1/pedidos/{id}/anular`

**Solo supervisor o administrador.**

### `GET /api/v1/items/{id}/consumo`

El desglose de consumo de papel, **paso a paso**.

Se puede pedir sin prueba aprobada: cotizar no es imprimir.

```json
{
  "unidades_pedidas": 20000,
  "unidades_por_pliego": 140,
  "orientacion": "normal",
  "columnas": 7,
  "filas": 20,
  "por_ventana": 1,
  "pliegos_netos": 143,
  "pliegos_de_merma": 8,
  "pliegos_totales": 151,
  "gramos_por_pliego": 210.0,
  "kilos_requeridos": 31.71,
  "mermas": [
    { "origen": "prueba",  "pliegos": 5, "porcentaje": 3.0, "porcentaje_real": null },
    { "origen": "plancha", "pliegos": 3, "porcentaje": 2.0, "porcentaje_real": null }
  ],
  "pasos": [
    "Plancha P70100 de 700 x 1000 mm; pieza de 90 x 50 mm.",
    "Mejor ajuste: normal, 7 columnas x 20 filas = 140 piezas por cara.",
    "Pliegos netos: 20000 piezas pedidas / 140 por pliego = 142.8571 -> 143 pliegos."
  ]
}
```

---

# Pruebas y artes

### `GET /api/v1/pruebas`

Pruebas esperando respuesta (`pendiente` o `enviada`), las mas antiguas
primero.

```json
{
  "dias_para_vencer": 5,
  "pruebas": [
    {
      "id": "7a1b...",
      "item": "Tarjetas de presentacion",
      "version": 1,
      "archivo": "tarjetas-v1.pdf",
      "estado": "enviada",
      "enviada_en": "2026-10-13T10:00:00-0000",
      "vencida": true
    }
  ]
}
```

`vencida` se trae calculada, para que la interfaz no tenga que comparar
fechas y se equivoque en un dia festivo.

### `POST /api/v1/items/{id}/versiones`

Sube una versión nueva del arte y crea su prueba.

```json
{
  "nombre_archivo": "tarjetas-v2.pdf",
  "url": "https://archivos.ejemplo.cl/clientes/ejemplo/tarjetas-v2.pdf",
  "sha256": "a3f1...64 caracteres...",
  "notas": "Cambio el color del logo segun lo pedido."
}
```

El `sha256` acepta solo 64 caracteres hexadecimales, o `null`.

La versión se calcula sola: el siguiente entero sobre el item. **Una versión
nueva nunca reemplaza a la anterior.**

**El archivo no se sube por aca.** Solo se registra donde vive.

### `POST /api/v1/pruebas/{id}/enviar`

Marca la prueba como mostrada al cliente, y guarda la fecha.

### `POST /api/v1/pruebas/{id}/resolver`

**Solo supervisor o administrador.**

Registra la respuesta del cliente.

```json
{ "estado": "aprobada", "comentario": "Aprobado sin cambios." }
```

o

```json
{ "estado": "rechazada", "comentario": "El logo se ve muy pequeno." }
```

**El campo `aprobada_por` lo pone el servidor**, no quien llama. Y no es
quien aprobo: es quien registro que el cliente aprobo.

---

# Papel y planchas

### `GET /api/v1/papeles?q=<texto>`

El catalogo. Cada papel trae `merma_configurada`, que es `false` cuando falta
alguno de los dos porcentajes.

### `GET /api/v1/papeles/{id}`

Un papel, con su merma, su precio y los gramos por pliego.

### `POST /api/v1/papeles`

**Solo administrador.**

### `PUT /api/v1/papeles/{id}`

**Solo administrador.** Aca se cargan los porcentajes de merma, y pueden
quedarse en `null`.

### `GET /api/v1/planchas`

Las medidas de plancha, con su area en metros cuadrados.

---

# Producción

### `GET /api/v1/produccion`

Tiradas registradas, paginadas.

### `GET /api/v1/produccion/pedido/{id}`

El estado de producción de cada item de un pedido: si esta aprobado, si es
imprimible y su consumo.

### `GET /api/v1/produccion/{item}/presupuesto`

Igual que `/api/v1/items/{id}/consumo`. Se deja el nombre explicito para quien
busca la pantalla de producción.

### `POST /api/v1/produccion/{item}`

Registra una tirada.

```json
{ "producto_bueno": 19800, "notas": "Salieron dos con la tinta corrida." }
```

**No se reciben los pliegos.** Los calcula el sistema. Lo único que se cuenta
a mano son las piezas buenas.

Respuesta 201:

```json
{
  "mensaje": "Produccion registrada.",
  "orden": {
    "pliegos_netos": 143,
    "pliegos_prueba": 5,
    "pliegos_merma": 3,
    "pliegos_totales": 151,
    "producto_bueno": 19800,
    "merma_real_porcentaje": 5.59
  }
}
```

Si el item no tiene prueba aprobada:

```
422
{
  "mensaje": "No se imprime \"Tarjetas de presentacion\": el cliente todavia
              no aprueba la prueba. Nada sale a imprenta sin que el cliente
              lo haya visto.",
  "codigo": "sin_prueba_aprobada"
}
```

Ese mensaje es lo que el taller ve. Y aunque alguien se salte la aplicación y
escriba directo en la base, el gatillo de PostgreSQL lo rechaza con un error
parecido. Las dos capas estan porque fallan distinto.

---

# Entregas

### `GET /api/v1/entregas`

Historial, paginado.

### `GET /api/v1/entregas/pendientes`

Pedidos con saldo **mayor que cero**. Es la pantalla del mostrador.

### `POST /api/v1/pedidos/{id}/entregas`

Registra una entrega y **recalcula el estado del pedido**.

```json
{
  "cantidad_unidades": 8000,
  "medio": "retiro",
  "entregado_por": "Recepcion",
  "entregado_en": "2026-10-24",
  "notas": "Se retira en dos bolsas."
}
```

Respuesta 201:

```json
{
  "mensaje": "Entrega parcial registrada. Quedan piezas por entregar.",
  "pedido": {
    "numero": "PED-0007",
    "estado": "parcialmente_entregado",
    "cantidad_pedida": 20000,
    "cantidad_entregada": 8000,
    "saldo": 12000,
    "cerrado": false
  }
}
```

Si la cantidad supera el saldo:

```
422
{
  "mensaje": "No se pueden entregar 15000 piezas: solo quedan 12000 pendientes.",
  "codigo": "entrega_supera_saldo"
}
```

---

# Merma

### `GET /api/v1/merma?desde=<fecha>`

Merma por papel: la prevista contra la real.

```json
{
  "umbral_aviso_puntos": 5,
  "resumen": [
    {
      "papel": "Opal 300 g/m2",
      "codigo": "OPL300",
      "merma_prevista_porcentaje": 5.0,
      "merma_real_porcentaje": 7.2,
      "desvio_puntos": 2.2,
      "requiere_revision": false,
      "pliegos_netos": 4210,
      "pliegos_prueba": 280,
      "pliegos_merma": 23,
      "pliegos_totales": 4513,
      "producto_bueno": 588500,
      "ordenes": 31
    }
  ],
  "total_ordenes": 31
}
```

Si los pliegos netos son cero, `merma_real_porcentaje` es `null`, no cero:
decir cero seria afirmar que no se perdio nada.

---

# Libreria

### `GET /api/v1/libreria?q=<texto>`

Catalogo con el stock **derivado** de los movimientos.

### `POST /api/v1/libreria`

**Solo administrador.** Alta de articulo.

### `PUT /api/v1/libreria/{id}`

**Solo administrador.**

### `POST /api/v1/libreria/{id}/movimientos`

```json
{ "sentido": "entrada", "cantidad": 24, "motivo": "compra", "referencia": "OC-1234" }
```

Motivos: `compra`, `venta`, `merma`, `devolucion`, `regularizacion`.

El stock **no se escribe**: se suma o se resta con un movimiento. Un gatillo
impide que quede negativo.

---

# Reportes

### `GET /api/v1/reportes`

El estado de la imprenta en una pantalla: pedidos por estado, piezas en la
cola, aprobaciones pendientes y vencidas, lo que vence pronto y **los pedidos
cuyo estado no cuadra con sus entregas**.

Ese último listado no se corrige solo: sale a la vista y es de una persona
decidir, con:

```
php artisan imprenta:revisar-estados --corregir
```

---

# Los códigos de error de regla

Todos devuelven **422** con `mensaje` y `codigo`.

| Código | Que significa |
|---|---|
| `sin_prueba_aprobada` | El item no tiene prueba aprobada |
| `pedido_sin_pruebas_aprobadas` | Algun item del pedido no tiene prueba aprobada |
| `sin_permiso_aprobar` | El rol no puede registrar aprobaciones |
| `sin_permiso_producir` | El rol no puede registrar producción |
| `sin_permiso_entregar` | El rol no puede registrar entregas |
| `prueba_ya_aprobada` | Esa prueba ya estaba aprobada |
| `pedido_no_imprimible` | El pedido no esta en un estado que admita producción |
| `pedido_no_entregable` | El pedido no esta en un estado que admita entrega |
| `pedido_anulado` / `pedido_entregado` / `pedido_cerrado` | El pedido no admite esa operación |
| `entrega_supera_saldo` | La entrega pasa del saldo pendiente |
| `entrega_sin_cantidad` | La cantidad es cero o negativa |
| `merma_no_configurada` | Al papel le falta un porcentaje de merma |
| `merma_fuera_de_rango` | Un porcentaje fuera de 0 a 100 |
| `la_pieza_no_cabe_en_la_plancha` | La medida no cabe, ni normal ni rotada |
| `item_sin_papel` / `papel_sin_plancha` / `papel_sin_peso_unitario` | Catalogo incompleto |
| `papel_no_configurado` | El papel no tiene la merma cargada |
| `estado_pedido_invalido` / `estado_prueba_invalido` | Un estado que no existe |
| `tipo_papel_invalido` | Un tipo de papel que no existe |
| `unidad_compra_invalida` | Algo que no es `pliego` ni `kilo` |
| `regla_de_imprenta` | Error de regla sin código especifico |

Todos son **estables**: la interfaz puede reaccionar a cada uno sin adivinar.
