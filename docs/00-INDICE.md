# 00. Indice

Aplicación propia de Hefesto Studio para una PyME de impresion, libreria y
papeleria.

---

## Por donde empezar, según para que vengas

**Si quieres ver si esto sirve** → `06-PRIMER-PEDIDO.md`. Recorre un pedido
completo, de la maqueta hasta la entrega en dos tandas, sin jerga.

**Si vas a instalar** → `04-INSTALACION.md` y despues
`05-CONFIGURACION.md`. Sin las dos, la aplicación no levanta.

**Si te pregunto como calcula el papel** → `02-MODELO-DE-DATOS.md`. La
conversion de unidades es lo que va a cuestionar el primer cliente.

**Si te toca la seguridad** → `03-MULTITENANCY-Y-ROLES.md` y
`07-SEGURIDAD.md`. El aislamiento esta en la base de datos, no en el código
de la aplicación.

**Si vas a respaldar** → `08-RESPALDOS.md`, y lee los dos guiones antes de
correrlos en serio.

**Si vas a integrar** → `09-API.md`, que lista cada ruta y cada código de
error.

**Si vas a decir que funciona** → `10-PRUEBAS-Y-ESTADO.md`. **Primero.**
Ahi esta exactamente que se ejecuto y que no.

---

## Los once documentos

| # | Documento | Que resuelve |
|---|---|---|
| 00 | Este indice | Por donde empezar |
| 01 | `01-ARQUITECTURA.md` | Como se arma el sistema y por que el navegador no habla con la API |
| 02 | `02-MODELO-DE-DATOS.md` | Las tablas, y sobre todo la conversion de unidades de papel |
| 03 | `03-MULTITENANCY-Y-ROLES.md` | Como se aislan las imprentas y quien puede hacer que |
| 04 | `04-INSTALACION.md` | Como se levanta, en desarrollo y en producción |
| 05 | `05-CONFIGURACION.md` | Cada variable de entorno: que hace y cual es su valor por omision |
| 06 | `06-PRIMER-PEDIDO.md` | El recorrido completo de un pedido |
| 07 | `07-SEGURIDAD.md` | Puertos, sesiones, cookies, y por que el diseno vive fuera |
| 08 | `08-RESPALDOS.md` | Respaldo, verificación y restauración |
| 09 | `09-API.md` | Cada ruta y cada código de error |
| 10 | `10-PRUEBAS-Y-ESTADO.md` | Que se ejecuto y que no. La verdad. |

---

## Las cuatro reglas del producto

Si alguien cambia algo de la aplicación y rompe una de estas, el cambio esta
mal, aunque las pruebas sigan en verde.

1. **Nada se imprime sin prueba aprobada.**
   Y "aprobada" significa que el cliente la aprobo. Vencida no es aprobada.
   Nunca se aprueba por silencio.

2. **La conversion de papel se calcula, no se configura.**
   Un factor guardado en el catalogo de papeles seria falso: el mismo papel
   rinde 140 tarjetas por plancha o 9 hojas membretadas, según la pieza.

3. **El saldo de un pedido no se guarda: se deriva.**
   Por eso una entrega parcial no puede cerrar el pedido por accidente.

4. **La merma no tiene valor por omision.**
   Sin porcentaje cargado, el calculo falla cerrado. Es preferible no
   responder antes que inventar cuanto papel se pierde.

---

## Una advertencia sobre los números

Esta aplicación **no inventa datos comerciales**. No hay precios, no hay
merma por omision y no hay plazos presentados como verdad.

Lo que si trae son constantes de calculo que se pueden verificar: el area de
una plancha en metros cuadrados y el peso que sale de multiplicarla por el
gramaje son fisica, no reglas del negocio.

Los valores de ejemplo del seeder llevan su marca de ejemplo en el código.
Lo que no se pudo verificar esta listado como pendiente en
`10-PRUEBAS-Y-ESTADO.md`.
