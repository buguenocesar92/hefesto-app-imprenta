# 02. Modelo de datos

Quince migraciones, trece tablas y una vista. Lo primero que se explica es la
conversion de unidades de papel, porque es lo que un cliente va a cuestionar
en los primeros diez minutos.

---

# La conversion de unidades de papel

## El problema, en una frase

El papel se **compra** en pliegos o en kilos. El trabajo se **entrega** en
piezas. Y entre una cosa y otra no hay un número fijo.

Si hubiera un factor guardado en el catalogo de papeles ("este papel da 15
piezas por pliego"), la aplicación se caeria en el primer trabajo real, porque
**ese mismo factor es falso para la mitad de los trabajos**.

## Conversion uno: pliego a unidad

Un pliego es la hoja entera, del tamaño de la plancha. Una pieza es la tarjeta
o la hoja que sale de esa plancha. Cuantas piezas salen depende de **las dos
medidas**.

Con una plancha de 700 x 1000 mm:

| Pieza | Columnas | Filas | Por plancha |
|---|---|---|---|
| Tarjeta 90 x 50 mm | 700/90 = 7 | 1000/50 = 20 | **140** |
| Hoja 210 x 297 mm (A4) | 700/210 = 3 | 1000/297 = 3 | **9** |
| Folder 240 x 330 mm | 700/240 = 2 | 1000/330 = 3 | **6** |

Las tres salen del **mismo papel**. Si el catalogo guardara "140 piezas por
pliego", las hojas A4 se cotizarían con un error de mas de quince veces.

Por eso `papeles` **no tiene** columna `unidades_por_pliego`, y
`pedido_items` tampoco. No es una omision: es la conclusion de la tabla
anterior.

### La orientacion cuenta

Una pieza de 900 x 650 mm no cabe en una plancha de 700 x 1000 mm: 900 es mas
ancho que 700. Pero **girada** si cabe: 650 de ancho contra 700, y 900 de alto
contra 1000.

`CalculadoraDeConsumo` prueba las dos orientaciones y se queda con la que
rinde mas, y dice en el desglose cual uso:

```
Mejor ajuste: rotada, 1 columnas x 1 filas = 1 piezas por cara.
```

Si siempre se calculara en normal, se perderia plancha en todas las piezas
verticales.

### La agrupacion cuenta

`pedido_items.n_por_ventana` es cuantas piezas del mismo trabajo se imprimen
juntas en una cara de la plancha (ganging). Con `1`, cada pieza ocupa su
lugar y se desperdicia el papel entre una y otra. Con `4`, se imprimen cuatro
tiras juntas.

En la plancha de 700 x 1000 mm, con tarjetas de 90 x 50 mm:

| Agrupacion | Piezas por plancha |
|---|---|
| 1 | 140 |
| 4 | 560 |

Para 100.000 tarjetas eso son 715 pliegos sueltas contra 179 agrupadas. No es
un detalle de maquina: es la diferencia entre tirar la plancha de sobra o no.

## Conversion dos: kilo a pliego

Esta depende de **la fisica**, y es la única parte del calculo que no es una
regla del negocio.

Un metro cuadrado de papel de 80 g/m2 pesa 80 gramos. Sin excepcion,
independientemente del proveedor o del tipo.

Con una plancha de 700 x 1000 mm, el area es 0,7 m2:

| Gramaje | Peso por pliego | Kilos que hacen falta para 25 pliegos |
|---|---|---|
| 80 g/m2 | 560 g | 14 kg |
| 120 g/m2 | 840 g | 21 kg |
| 300 g/m2 | 2.100 g | 52,5 kg |

Misma plancha, mismo trabajo, y el papel que hay que comprar se multiplica por
mas de tres entre el papel fino y el grueso. Ese es el motivo por el que el
gramaje es parte del catalogo y no un dato de la ficha del cliente.

### Redondeos

- **Pliegos**: siempre hacia arriba. Al piso le falta papel para terminar el
  trabajo, y el que lo paga es el taller.
- **Kilos**: hacia arriba, porque alguien tiene que pagar esa compra entera y
  se compra de a kilo.
- **Porcentajes de merma**: hacia arriba, por la misma razon. Al piso
  "sobraria" papel de una partida que en realidad se perdio.

Esta regla esta en `App\Support\Cantidad`, y sus pruebas estan en
`tests/Unit/CalculadoraDeMermaTest.php`.

## El desglose, no el número

`CalculadoraDeConsumo::calcular()` no devuelve un entero: devuelve un
`DesgloseDeConsumo` con la cuenta escrita, paso a paso.

```
Plancha P70100 de 700 x 1000 mm; pieza de 90 x 50 mm.
Mejor ajuste: normal, 7 columnas x 20 filas = 140 piezas por cara.
Agrupacion de 1 pieza(s) por cara: 140 unidades por pliego.
Pliegos netos: 5000 piezas pedidas / 140 por pliego = 35.7143 -> 36 pliegos.
Merma de papel de prueba: 3.00 % de 36 pliegos = 2 pliegos.
Merma de papel danado en la plancha: 2.00 % de 36 pliegos = 1 pliegos.
Total a gastar: 36 netos + 3 de merma = 39 pliegos.
Peso: 120 g/m2 x 0.7000 m2 = 84.0 g por pliego.
A comprar: 39 pliegos = 3,276 kg (4 kg redondeados hacia arriba).
```

**Por que el desglose y no el número**: porque el cliente va a preguntar "por
que 39 pliegos", y sin la cuenta esa pregunta no tiene respuesta. Con el
desglose, cada paso es discutible por separado: si el rendimiento no calza se
ve, si la agrupacion esta mal puesta se ve, si la merma del catalogo esta vieja
se ve.

Una cotizacion que el cliente no puede discutir no se firma.

## La merma: dos origenes que no se mezclan

- **Merma de prueba**: el papel gastado en sacar la maqueta. En trabajos con
  muchas correcciones es la mayor parte de la perdida, y es la que el cliente
  nunca ve.
- **Merma de plancha**: el papel danado al pasar por la maquina. Es
  impredecible de verdad, y por eso se mide por tirada en vez de suponerse.

El desglose devuelve las dos por separado, con el porcentaje que se aplico
**y** el porcentaje que se midio de verdad en esa corrida. Comparar los dos es
como se corrigen los porcentajes del catalogo.

## Y por que la merma no tiene valor por omision

`papeles.merma_prueba_porcentaje` y `merma_plancha_porcentaje` admiten `NULL` a
propósito. Un `CHECK` de PostgreSQL los acota entre 0 y 100, pero no les pone
un valor.

Si `CalculadoraDeMerma` recibiera `NULL`, **lanza excepcion** en vez de
suponer:

```
El campo "merma de prueba" no esta configurado en este papel. Sin el, no se
puede calcular cuanto papel se va a perder. Carguelo con el historial real de
la imprenta.
```

Es una decisión incomoda a propósito. Si el sistema supusiera "5 % de
merma", cada trabajo ganaria un 5 % de papel fantasma. Para una PyME que
reimprime mucho, eso no es un detalle de redondeo: es margen que desaparece
sin que nadie lo vea y sin que ninguna pantalla lo delate.

**El hueco es información. El número inventado es peor que el hueco.**

---

# Las tablas

## Sin RLS

### `tenants` — las imprentas

La única tabla sin `tenant_id` ni RLS. Se consulta en el acceso, cuando todavia
no hay sesión y por lo tanto no hay `app.tenant_id` que poner.

Se compensa con la vista `vw_impresas_publicas` (tres columnas: `slug`,
`nombre`, `activo`), que el selector de acceso consulta **de verdad**.

## Con RLS

Las doce siguientes llevan `tenant_id` y tienen política con `USING` y
`WITH CHECK`, con `FORCE ROW LEVEL SECURITY`. Ver
`03-MULTITENANCY-Y-ROLES.md`.

### `usuarios` — quien opera

Correo único **por empresa**, no global: la misma persona puede ser
administradora en una imprenta y preparadora en otra. Por eso el acceso
pregunta primero por la empresa.

El rol (`auditor`, `preparador`, `supervisor`, `administrador`) es lo que
autoriza cada accion. Un `CHECK` lo acota a esos cuatro valores.

### `planchas` — la medida de la hoja

`ancho_mm` y `alto_mm`. De aquí salen las dos conversiones: el rendimiento y
el peso.

`pliegos_por_paquete` admite `NULL`: es la cantidad de pliegos que trae un
paquete del proveedor, y cada proveedor la maneja distinto.

### `papeles` — el catalogo que define el costo

`tipo` (`offset`, `coated`, `opal`), `gramaje_gm2`, `plancha_id`,
`unidad_compra` (`pliego` o `kilo`), los dos porcentajes de merma
(nullable, sin valor por omision) y `precio_referencia_por_kg` (nullable: el
precio lo carga la imprenta).

**No tiene columna de factor de rendimiento.** Ver arriba.

### `clientes` — a quien se le imprime

Código, nombre, contacto. El código lo pone la imprenta: cada una numera a
sus clientes como quiere.

### `pedidos` — la producción comprometida

Número, cliente, `estado`, `fecha_prometida`.

**Los estados que importan:**

| Estado | Que significa |
|---|---|
| `borrador` | Se esta cotizando. No se imprime nada. |
| `esperando_aprobacion` | El cliente recibio la prueba y no ha respondido. |
| `aprobado` | Todas las pruebas estan aprobadas. Se puede imprimir. |
| `en_produccion` | Se esta imprimiendo. |
| `parcialmente_entregado` | Se entrego parte. El saldo sigue vivo. |
| `entregado` | Lo entregado cubre lo pedido. |
| `anulado` | Cancelado. |

`esperando_aprobacion` y `aprobado` son estados **distintos** y no se pueden
saltar. Si el sistema no los distinguiera, el taller reimprimiria lo que el
cliente no quiso.

**No tiene columna de cantidad entregada.** El saldo se deriva sumando
`entregas`. Ver `06-PRIMER-PEDIDO.md`.

### `pedido_items` — que se imprime

`cantidad_unidades` (piezas a entregar), `ancho_mm`, `alto_mm`, `papel_id` y
`n_por_ventana` (agrupacion). Un `CHECK` exige que cantidad, medidas y
agrupacion sean mayores que cero.

**No tiene columna de unidades por pliego.** Se calcula.

### `artes` — el diseno, versionado

El archivo vive fuera del sistema. Aca queda el registro: `version`,
`nombre_archivo`, `url`, `sha256` y quien lo subio.

Una versión nueva **no reemplaza** a la anterior. Si el cliente pidio el
cambio, el taller puede reimprimir por la versión 2, y la versión 1 sigue
ahi para demostrar que fue lo que se aprobo.

### `pruebas` — el mockup (lo que el cliente ve)

Estado (`pendiente`, `enviada`, `aprobada`, `rechazada`), que arte se mostro,
cuando se resolvio, **quien registro la respuesta** (`aprobada_por`) y el
comentario.

`aprobada_por` **no es quien aprobo**: la aprueba el cliente, ese nombre es
quien dejo constancia. Guardarlo es lo que hace auditable el pedido meses
despues, cuando el cliente diga que el nunca vio esa muestra.

Un `CHECK` obliga a que una prueba aprobada tenga autor y fecha de resolución.

### `ordenes_produccion` — la evidencia

Aquí esta la diferencia entre el papel usado y el producto entregado:

```
pliegos_netos   +   pliegos_prueba   +   pliegos_merma   =   pliegos_totales
```

Un `CHECK` obliga a que la suma cuadre. Sin el, un pliego perdido podria
quedar escondido dentro de un total inflado, y el informe de merma seria
falso.

`producto_bueno` es el **contado real** de piezas buenas de la corrida. Puede
ser menor que lo pedido, y eso no se corrige a mano: es información del
taller.

Un gatillo de PostgreSQL impide insertar una orden si el item no tiene prueba
aprobada, y otro impide registrar un papel distinto del que pide el item.

### `entregas` — las salidas

Una fila por entrega, con `cantidad_unidades`, el medio (`retiro`, `envio`,
`mostrador`) y la fecha. Un `CHECK` exige que la cantidad sea mayor que cero:
una entrega de cero deja al pedido igual y oculta que no se entrego nada.

### `articulos_libreria` — reventa

SKU, nombre, categoria, unidad y precios (ambos nullable).

**No tiene columna de stock.** Se deriva sumando `movimientos_libreria`. Si
fuera una casilla editable, en dos semanas de ventas estaria pasada y nadie
sabria en cuanto.

### `movimientos_libreria` — compras y ventas

Sentido (`entrada` o `salida`), cantidad, motivo y referencia. No se edita:
una correccion se hace con un movimiento contrario y quedan los dos en la
historia.

Un gatillo impide que el stock de libreria quede negativo, porque en un local
chico casi siempre es un error de tipeo y no una devolucion.

---

# Las tres reglas en la base de datos

Este listado es util cuando algo se rompe y hay que saber donde mirar.

```
BEFORE INSERT OR UPDATE ON ordenes_produccion
  if NOT EXISTS (SELECT 1 FROM pruebas
                  WHERE pedido_item_id = NEW.pedido_item_id
                    AND estado = 'aprobada') then
      RAISE EXCEPTION 'No se puede registrar produccion:
                       el item no tiene ninguna prueba aprobada.'
```

**Por que en la base y no solo en el código**: si la regla viviera solo en
`ServicioDeProduccion`, bastaria un reporte nuevo, un comando de consola o
una actualización de Laravel para que se perdiera sin que nadie lo notara.
Con el gatillo, no hay forma de imprimir sin que un humano mire una maqueta y
la apruebe, se llame como se llame la operación.

La contraparte en el código existe igual, y da un mensaje que el taller
entiende. Cada capa falla distinto.

## 2. El papel usado es el papel cotizado

```
BEFORE INSERT OR UPDATE ON ordenes_produccion
  if NEW.papel_id <> (SELECT papel_id FROM pedido_items
                      WHERE id = NEW.pedido_item_id) then
      RAISE EXCEPTION 'El papel de la orden de produccion
                       no es el papel del item.'
```

Sin esto se podria registrar una tirada con un papel distinto del cotizado, y
el informe de merma mediria un papel que el cliente nunca pidio.

## 3. El stock de libreria nunca queda negativo

```
BEFORE INSERT OR UPDATE ON movimientos_libreria
  saldo = SUM(entrada - salida) del articulo
  if saldo < 0 then
      RAISE EXCEPTION 'La salida dejaria el stock
                       de libreria en negativo.'
```

El stock de libreria es la suma de los movimientos, y se recalcula en cada
movimiento para que no dependa de que quien escribe estire bien la suma.

---

# Los CHECK que acotan los datos

| Tabla | Restriccion | Que evita |
|---|---|---|
| `planchas` | medidas mayores que cero | Calculos de area en cero |
| `papeles` | gramaje mayor que cero | Un papel sin peso unitario |
| `papeles` | merma entre 0 y 100, o `NULL` | Un -5 % de merma, que no es un dato |
| `papeles` | tipo en la lista cerrada | Papel escrito a mano |
| `papeles` | unidad de compra en `pliego` o `kilo` | Una tercera unidad inventada |
| `pedidos` | estado en la lista cerrada | Un estado creado por un `UPDATE` |
| `pedido_items` | cantidad y medidas mayores que cero | Un item sin piezas |
| `pedido_items` | agrupacion de 1 o mas | "0 piezas por ventana", que no significa nada |
| `artes` | versión de 1 en adelante | La versión 0 |
| `pruebas` | estado en la lista cerrada | Una prueba en un estado raro |
| `pruebas` | aprobada exige autor y fecha | Una aprobacion sin responsable |
| `ordenes_produccion` | pliegos no negativos | Papel negativo |
| `ordenes_produccion` | total = netos + prueba + merma | Un pliego escondido en el total |
| `entregas` | cantidad mayor que cero | Una entrega de cero |
| `movimientos_libreria` | cantidad mayor que cero | Un movimiento que no mueve nada |

Todos estan en las migraciones y se pueden ver en `pg_constraint`:

```
SELECT conname, pg_get_constraintdef(oid)
  FROM pg_constraint
 WHERE conrelid::regclass::text IN ('papeles', 'pedidos', 'ordenes_produccion')
 ORDER BY conname;
```
