# 06. Primer pedido

Un pedido completo, de la cotizacion a la entrega en dos tandas. Sin jerga.

Se sigue un caso real de una imprenta chica: **20.000 tarjetas de presentacion
de 90 x 50 mm, en opal de 300 g/m2, con entrega partida en dos**.

---

# Antes que nada: el catalogo

Una imprenta no puede cotizar un papel que no tiene cargado. El catalogo son
dos cosas encadenadas: la **plancha** (la medida de la hoja entera) y el
**papel** (el gramaje y el tipo, sobre esa plancha).

## La plancha

```
Codigo:  P70100
Nombre:  Plancha 70 x 100 cm
Medida:  700 x 1000 mm
Area:    0,7 m2
```

El area es lo que despues multiplica al gramaje para saber cuanto pesa un
pliego.

## El papel

```
Codigo:          OPL300
Nombre:          Opal 300 g/m2
Tipo:            opal
Gramaje:         300 g/m2
Plancha:         70 x 100 cm
Unidad de compra: pliego
Merma de prueba:  3,00 %      <- de ejemplo, se carga con el historial real
Merma de plancha: 2,00 %      <- idem
Precio por kg:    (sin cargar)
```

Los dos porcentajes de merma se piden al guardar el papel, y **se pueden dejar
en blanco**: un papel sin merma existe, pero no se puede cotizar. La pantalla
lo muestra marcado como "pendiente" y el calculo falla diciendo por que.

Esa es una decisión incomoda a propósito. Si el sistema supusiera un 5 % de
merma, cada trabajo ganaria un 5 % de papel fantasma, y para una PyME que
reimprime mucho eso es margen que desaparece sin que nadie lo note.

**El hueco es información. El número inventado es peor que el hueco.**

---

# 1. El pedido

Se crea el pedido para el cliente, con la fecha prometida.

```
Numero:          PED-0007
Cliente:         Comercio de Ejemplo Ltda.
Estado:          borrador
Fecha prometida: 2026-10-25
```

El estado arranca en `borrador`, y desde ahi **no se puede imprimir**. Se
imprime desde `aprobado`, y para llegar ahi hay que pasar por la prueba.

## El item: que se imprime

```
Descripcion:          Tarjetas de presentacion
Papel:                Opal 300 g/m2
Piezas a entregar:    20.000
Medida:               90 x 50 mm
Agrupacion:           1 por cara
```

**Un item es una cosa concreta que se imprime.** Un pedido con tarjetas y
folders tiene dos items, cada uno con su papel y su propia prueba. Es
necesario: las tarjetas pueden estar aprobadas y los folders no, y el
sistema tiene que poder decir eso.

---

# 2. La cuenta del papel

Antes de cotizar, hay que saber cuanto papel sale. La pantalla lo muestra
paso a paso, y esta es la cuenta con esos números:

```
Plancha P70100 de 700 x 1000 mm; pieza de 90 x 50 mm.
Mejor ajuste: normal, 7 columnas x 20 filas = 140 piezas por cara.
Agrupacion de 1 pieza(s) por cara: 140 unidades por pliego.
Pliegos netos: 20000 piezas pedidas / 140 por pliego = 142.8571 -> 143 pliegos.
Merma de papel de prueba: 3.00 % de 143 pliegos = 5 pliegos.
Merma de papel danado en la plancha: 2.00 % de 143 pliegos = 3 pliegos.
Total a gastar: 143 netos + 8 de merma = 151 pliegos.
Peso: 300 g/m2 x 0.7000 m2 = 210.0 g por pliego.
A comprar: 151 pliegos = 31,710 kg (32 kg redondeados hacia arriba).
```

**Por que el mismo papel no da siempre el mismo número.** Con esta misma
plancha de 700 x 1000 mm, el mismo opal de 300 g/m2 rinde:

| Pieza | Por plancha |
|---|---|
| Tarjeta 90 x 50 mm | 140 |
| Hoja A4 210 x 297 mm | 9 |
| Folder 240 x 330 mm | 6 |

Si el catalogo guardara "140 piezas por pliego", las hojas A4 se cotizarian
con un error de mas de quince veces. Por eso no hay ningun factor guardado en
ningun lado: se calcula por trabajo.

**Y por que el desglose y no el número.** El cliente va a preguntar "por que
151 pliegos". Sin la cuenta esa pregunta no tiene respuesta. Con el
desglose, cada paso se puede discutir por separado: si el rendimiento no calza
se ve, si la agrupacion esta mal puesta se ve, si la merma del catalogo esta
vieja se ve.

Una cotizacion que el cliente no puede discutir no se firma.

---

# 3. El arte y la maqueta

## El archivo vive fuera

El diseno no se sube a esta aplicación. Esta en el servidor de archivos o en
la nube. El sistema guarda **el registro**:

```
Version:         1
Archivo:         tarjetas-v1.pdf
Ubicacion:       https://archivos.ejemplo.cl/clientes/ejemplo/tarjetas-v1.pdf
SHA-256:         (la huella del archivo)
Subido por:      Mesa de trabajo
```

La huella es lo que permite demostrar meses despues que el archivo que se
imprimio es el mismo que el cliente aprobo.

## La maqueta

Al subir la versión, se crea una prueba en estado `pendiente`. Cuando el taller
se la muestra al cliente, pasa a `enviada` y queda la fecha:

```
Estado:     enviada
Enviada:    2026-10-18
```

Ahora el pedido pasa a `esperando_aprobacion`. **Desde aca no se imprime nada.**

---

# 4. La espera

Aquí es donde el sistema se pone incomodo a propósito.

La maqueta se envio el 18 de octubre. El plazo por omision son 5 dias, así que
el 23 de octubre la prueba aparece **vencida** en la pantalla.

**Vencida no es aprobada.** El pedido sigue sin poder imprimirse.

Hay que llamar al cliente. El sistema no tiene ninguna opcion de "dar por
aprobado": no existe un boton que saltee la espera, y en la base hay un gatillo
que impide registrar producción sin una prueba aprobada.

Que una prueba se venza es una molestia. Que se impriman 20.000 tarjetas que el
cliente nunca vio es un costo que no se recupera.

---

# 5. La aprobacion

El cliente responde que si. Un supervisor registra la respuesta:

```
Estado:        aprobada
Resuelta:      2026-10-21
Registrado por: Supervision de Ejemplo
```

**`Registrado por` no es quien aprobo.** La aprueba el cliente, ese nombre es
quien dejo constancia. Se guardan las tres cosas: que aprobo, cuando, y sobre
que versión del archivo (la versión 1).

## Aprobar el pedido

Con la prueba aprobada, el supervisor aprueba el pedido:

```
Estado del pedido:  aprobado
Aprobado por:       Supervision de Ejemplo
Aprobado el:        2026-10-21
```

Solo ahora el sistema permite imprimir. Y solo ahora tiene sentido hablar de
papel.

## Y si el cliente rechaza

La prueba queda `rechazada`, con el comentario del cliente. El pedido **no**
se aprueba. Hay que corregir el arte, subir la **versión 2** y mandar otra
maqueta.

La versión 1 no se borra: queda como prueba de que hubo una correccion y de
que se pidio una. Si el cliente vuelve a decir que el nunca vio la maqueta,
esta el registro.

---

# 6. La producción

Ahora si. El taller sube la plancha.

## El formulario NO pide los pliegos

El formulario pide **una sola cosa**: cuantas piezas buenas salieron.

```
Piezas buenas de esta corrida: (opcional)
```

Los pliegos los calcula el sistema con el desglose de mas arriba. Si el
formulario los pidiera y aceptara cualquier número, el informe de merma seria
una opinion escrita por el operador, no una cuenta.

La única excepcion: si el operador **si** cuenta las piezas buenas, se registra
la diferencia. Un `producto_bueno` menor que lo pedido es información real del
taller, no un error que se corrige.

## Lo que queda registrado

```
Pedido item:          Tarjetas de presentacion
Papel:                Opal 300 g/m2
Pliegos netos:        143
Pliegos de prueba:    5
Pliegos de merma:     3
Pliegos totales:     151
Producto bueno:       20.000
Merma real:           5,59 %
```

El pedido pasa a `en_produccion`.

## Si no hubiera prueba aprobada

Intentar registrar esa misma producción da:

```
422
"No se imprime "Tarjetas de presentacion": el cliente todavia no aprueba la
prueba. Nada sale a imprenta sin que el cliente lo haya visto."
codigo: sin_prueba_aprobada
```

Y si alguien se salta la aplicación y escribe directo en la base, el gatillo
de PostgreSQL lo rechaza:

```
ERROR:  No se puede registrar produccion: el item no tiene ninguna prueba
        aprobada.
HINT:   Registre la aprobacion del cliente en pruebas antes de imprimir.
```

---

# 7. Las entregas parciales

El cliente pide retirar la mitad ahora y el resto la semana que viene. Es lo
normal en una imprenta que entrega por tandas.

## Primera entrega: 8.000 piezas

```
Cantidad:  8.000
Medio:     retiro
Fecha:     2026-10-24
```

Lo que devuelve la pantalla:

```
Entrega parcial registrada. Quedan piezas por entregar.

Pedido PED-0007
Estado:             parcialmente_entregado
Piezas pedidas:    20.000
Piezas entregadas:  8.000
Saldo:             12.000
Cerrado:           no
```

**El pedido no se cerro.** Y no porque alguien se acordo de no cerrarlo: el
estado **no se toca a mano**, se recalcula desde la suma de las entregas.

```
saldo = piezas pedidas - SUM(entregas.cantidad_unidades)
```

Si el saldo fuera una columna, un error de tipeo al registrar la entrega
dejaria el pedido en "completo" y el cliente receberia mil piezas donde aumento
veinte mil. Como el saldo no esta guardado, eso no puede pasar.

## Segunda entrega: 12.000 piezas

```
Cantidad:  12.000
Medio:     envio
Fecha:     2026-10-29
```

```
Entrega registrada. El pedido quedo entregado.

Estado:             entregado
Piezas entregadas:  20.000
Saldo:              0
Cerrado:            si
```

## Entregar de mas

Si alguien intenta registrar 15.000 cuando quedaban 12.000:

```
422
"No se pueden entregar 15000 piezas: solo quedan 12000 pendientes."
codigo: entrega_supera_saldo
```

La tolerancia por sobreentrega es **cero** por omision. Si una imprenta quiere
admitirla, se configura con `IMPRENTA_TOLERANCIA_PIEZAS`, y queda escrito en
el mensaje de error.

---

# 8. Lo que queda para revisar

## El informe de merma

Tres meses despues, con otras 60 tarjetas:

```
Papel              Prevista   Real     Desvio
Opal 300 g/m2      5,00 %     7,20 %   +2,20
Offset 120 g/m2    5,00 %     4,10 %   -0,90
```

El papel cuyo desvio pasa los 5 puntos queda marcado para revisar. Y esa es la
tarea que hace que los porcentajes del catalogo dejen de ser un supuesto: se
miden, se comparan y se ajustan.

## El estado de las pruebas

La pantalla de inicio muestra, arriba:

- Pruebas esperando respuesta.
- Pruebas **vencidas**: esas son las que tienen un pedido parado.

---

# El recorrido en una linea

```
Pedido en borrador
  -> item con su papel y su medida
  -> calculo de consumo con el desglose
  -> version 1 del arte, maqueta pendiente
  -> maqueta enviada ......... el pedido espera, no se imprime
  -> (5 dias sin respuesta) .. vencida, sigue sin aprobar
  -> cliente aprueba ......... aprobado por un supervisor
  -> aprobacion del pedido ... ahora si se puede imprimir
  -> tirada registrada ....... pliegos calculados, merma visible
  -> entrega de 8.000 ........ parcialmente entregado, saldo 12.000
  -> entrega de 12.000 ....... entregado, saldo 0
```

En ningun punto de ese recorrido se puede imprimir sin que un humano haya
mirado una maqueta y un supervisor haya registrado que el cliente la aprobo.
