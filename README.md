# Gestión de Imprenta, libreria y papeleria

Aplicación propia de Hefesto Studio para una PyME de impresion que ademas
vende libreria y papeleria.

**Esto no es un almacen con productos.** En una imprenta lo que se vende es
una producción con una prueba aprobada y una fecha prometida, y el costo
depende del papel que se eligio. El sistema esta construido alrededor de esa
realidad, no alrededor de un carrito de compras.

---

## Que resuelve, en el orden en que importa

**1. Nada se imprime sin que el cliente haya aprobado la prueba.**
Una maqueta enviada hace tres semanas y sin respuesta NO aprueba el pedido.
El sistema nunca aprueba por silencio. La regla vive en el código
(`ServicioDeAprobacion`, `ServicioDeProduccion`) y también en la base, con un
gatillo de PostgreSQL que impide el `INSERT` aunque alguien se salte la
aplicación.

**2. La conversion de papel no es un número fijo.**
El papel se compra en pliegos o en kilos; el trabajo sale en unidades. Entre
uno y otro hay dos conversiones distintas, y ninguna es una constante: una
depende de la medida de la plancha y de la pieza, y la otra del area y del
gramaje. El factor se calcula por cada trabajo y se devuelve con el desglose
paso a paso. Ver `docs/02-MODELO-DE-DATOS.md`.

**3. La diferencia entre papel gastado y producto entregado queda visible.**
Los pliegos se parten en netos, de prueba y de merma, y un `CHECK` obliga a
que la suma cuadre. El informe separa lo previsto de lo real, que es como se
aprende donde se va la plata.

**4. Una entrega parcial no cierra un pedido.**
El saldo no se guarda: se deriva sumando las entregas. Un pedido de 20.000
entregado en dos queda "parcialmente entregado", y no por cortesia del
sistema sino porque el estado se recalcula desde los datos.

---

## Documentacion

| Documento | Que responde |
|---|---|
| [00-INDICE](docs/00-INDICE.md) | Por donde empezar |
| [01-ARQUITECTURA](docs/01-ARQUITECTURA.md) | Como se arma el sistema y por que |
| [02-MODELO-DE-DATOS](docs/02-MODELO-DE-DATOS.md) | La conversion de unidades de papel, sin rodeos |
| [03-MULTITENANCY-Y-ROLES](docs/03-MULTITENANCY-Y-ROLES.md) | Como se aislan las imprentas y quien puede hacer que |
| [04-INSTALACION](docs/04-INSTALACION.md) | Como se levanta |
| [05-CONFIGURACION](docs/05-CONFIGURACION.md) | Cada variable, que hace y cual es su omision |
| [06-PRIMER-PEDIDO](docs/06-PRIMER-PEDIDO.md) | El recorrido completo, de la maqueta a la entrega |
| [07-SEGURIDAD](docs/07-SEGURIDAD.md) | Puertos, sesiones, cookies, datos de clientes |
| [08-RESPALDOS](docs/08-RESPALDOS.md) | Como respaldar y como restaurar sin perder la base |
| [09-API](docs/09-API.md) | Cada ruta, cada código de error |
| [10-PRUEBAS-Y-ESTADO](docs/10-PRUEBAS-Y-ESTADO.md) | **Que se ejecuto y que no. Leer antes de prometer nada** |

---

## Estructura

```
apps/imprenta/
  api/         Laravel: modelos, migraciones, politicas, servicios, pruebas
  web/         Nuxt 3: interfaz de escritorio del taller y el mostrador
  deploy/      Imagenes, composes, proxy y los guiones de respaldo
  docs/        Los once documentos de arriba
```

---

## Estado real, sin adornos

**En la maquina donde se escribio esto no hay PHP, Composer, Node, Docker ni
PostgreSQL.** No se aplico ninguna migración, no se corrio ninguna prueba, no
se construyo ninguna imagen y no se levanto nada. El código esta escrito y
escrito, pero no esta probado.

La lista exacta de lo que falta ejecutar esta en
[docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md). No se presenta
nada como funcionando antes de haberlo corrido.

---

## Lo que esta marcado como pendiente, y por que

Esta aplicación **no inventa datos comerciales**:

- **No hay precios.** `papeles.precio_referencia_por_kg` y los precios de
  libreria admiten `NULL` y quedan vacios. El precio lo carga la imprenta.
- **No hay merma por omision.** `papeles.merma_prueba_porcentaje` y
  `merma_plancha_porcentaje` admiten `NULL` a propósito. Sin valor cargado,
  `CalculadoraDeMerma` falla cerrado en vez de suponer un porcentaje: una
  merma inventada es plata inventada.
- **No hay factor de conversion guardado.** Se calcula por trabajo, porque
  depende de la medida de la pieza.
- **Los plazos configurables son de ejemplo y ajustables.** El número de dias
  que se espera la respuesta a una prueba (5 por omision) es un valor por
  defecto declarado, no una verdad del rubro: cada imprenta tiene que
  ajustarlo.

Lo que el ejemplo (`php artisan db:seed`) carga lleva su marca de ejemplo en
los comentarios. Ver `docs/05-CONFIGURACION.md`.

---

## Aislar imprentas

Mismo patron que el resto de las aplicaciones de Hefesto Studio, sin
variantes: columna `tenant_id`, Row Level Security con `USING` y `WITH CHECK`,
`FORCE ROW LEVEL SECURITY`, contexto con `set_config(..., true)` de alcance de
transacción, y una función que falla cerrado con excepcion.

La tabla de empresas no lleva RLS porque se consulta en el acceso, antes de
que exista sesión. Como compensacion existe la vista
`vw_impresas_publicas` con tres columnas, y **se consulta de verdad**: es lo
que lee `AuthController::empresas()` para el selector de empresa del login.

```
php artisan imprenta:verificar-aislamiento
```

Ese comando se corre automáticamente en el arranque del contenedor. Si
falla, el contenedor NO levanta: servir trafico sin aislamiento es lo que este
sistema existe para evitar.
