# 08. Respaldos

Como respaldar, como verificar y como restaurar sin perder la base que
funcionaba.

Los dos guiones estan en `deploy/respaldos/`. Son el mismo patron que usan
las demas aplicaciones de Hefesto Studio, con las seis reglas obligatorias
respetadas y adaptadas al dominio.

---

# Por que en una imprenta esto importa mas que en otros sistemas

Perder la base de una imprenta no es perder una lista de productos. Es perder
**la evidencia de que el cliente aprobo un diseno antes de que saliera**.

Es la fila que dice que la maqueta se mostro el 18, que el cliente la aprobo
el 21, que el supervisor lo registro, y cual era el hash del archivo. Sin eso,
el trabajo ya impreso no tiene respuesta ante un reclamo, y no hay forma de
reconstruirlo: nadie guarda copias de los correos que mandaron.

---

# Las seis reglas, y donde esta cada una

| # | Regla | Donde |
|---|---|---|
| 1 | Manifiesto con SHA-256 de cada archivo | `respaldo.sh`, seccion 3 |
| 2 | Se marca `COMPLETO` solo al final, y queda `EN_CURSO` mientras corre | `respaldo.sh`, lineas de `EN_CURSO` y `COMPLETO` |
| 3 | Se validan las rutas y el espacio libre **antes** de escribir | `respaldo.sh`, seccion 1 |
| 4 | Destino ocupado se rechaza, salvo `--force` | `respaldo.sh`, seccion 1 |
| 5 | Nunca `DROP`, nunca sobrescritura automática | `restauracion.sh` |
| 6 | La retencion se aplica **despues** de verificar el respaldo nuevo, y avisa antes de borrar | `respaldo.sh`, seccion 4 |

## La regla 3 en detalle

El destino tiene que **existir**. El guion no hace `mkdir -p` de donde le
digieron, y esa es la parte incomoda del asunto.

Un `mkdir -p` sobre una ruta mal escrita deja el respaldo donde nadie lo busca,
y durante semanas nadie se da cuenta porque el guion salio bien. Se verifica
ademas:

- Que el destino sea un directorio.
- Que haya permiso de escritura.
- Que queden al menos 500 MB libres.

Los 500 MB son el margen del temporal y el manifiesto. Sin la comprobacion, un
respaldo de varios gigabytes falla a la mitad por falta de espacio y se pierde
el trabajo de la noche.

Crear el directorio es de una persona:

```
sudo install -d -m 750 /var/backups/imprenta
```

---

# El respaldo

## Como se corre

```
cd apps/imprenta/deploy/respaldos

DIRECCION_RESGARDOS=/var/backups/imprenta \
DB_HOST=postgres DB_PORT=5432 \
DB_DATABASE=imprenta DB_USERNAME=imprenta_app \
DB_PASSWORD=... \
./respaldo.sh
```

## Que produce

```
/var/backups/imprenta/imprenta-20261018-030000/
    datos.dump.gz     el volcado, comprimido
    manifiesto.txt    la suma SHA-256 y los metadatos
    COMPLETO          se escribe SOLO al final
```

El manifiesto se ve así:

```
# Manifiesto de respaldo de Gestion de Imprenta de Hefesto Studio
archivo: datos.dump.gz
sha256: 3f7a...  (64 caracteres)
bytes: 4819230
base: imprenta
servidor: postgres
puerto: 5432
version_pg_dump: pg_dump (PostgreSQL) 17.6
fecha: 2026-10-18T03:00:12-0300
nota: verificar con 'sha256sum -c' sobre el archivo datos.dump.gz.
nota: contiene las aprobaciones de pruebas y las huellas de los artes.
```

## El orden de las operaciones

Esto importa y es lo que suele estar al reves:

```
1. Verificar ruta, permisos y espacio          <- antes de escribir nada
2. Verificar que el destino no este ocupado    <- antes de escribir nada
3. Crear la carpeta y EN_CURSO                 <- si se muere aqui, se nota
4. Volcar con pg_dump a un archivo SIN comprimir
5. Comprimir
6. Comprobar que el .gz se puede leer (gzip -t)
7. Calcular el SHA-256 y escribir el manifiesto
8. Aplicar la retencion, avisando que borra     <- DESPUES de verificar el nuevo
9. Quitar EN_CURSO y escribir COMPLETO         <- al final
```

Dos decisiones que se notan:

**Se vuelca sin comprimir y se comprime al final.** Si se comprime al vuelo y
falla, queda un `.gz` truncado que **parece** un respaldo y no lo es.

**La retencion va despues de verificar el nuevo.** Si el respaldo de hoy fallo,
no tiene sentido borrar el de ayer. El orden al reves deja el directorio de
respaldos con un hueco y nada que lo cubra.

## La retencion

Por omision 30 dias, configurable:

```
RETENCION_DIAS=30 ./respaldo.sh
```

Antes de borrar, **imprime cada carpeta**:

```
[respaldo] revisando respaldos de mas de 30 dias...
[respaldo]   se va a eliminar: /var/backups/imprenta/imprenta-20260801-030000
[respaldo]   se va a eliminar: /var/backups/imprenta/imprenta-20260803-030000
```

Ver que va a eliminar es parte del respaldo: si el criterio esta mal, se ve.

## Programarlo

```
sudo crontab -e
```

```
30 3 * * * cd /ruta/apps/imprenta/deploy/respaldos && DIRECCION_RESGARDOS=/var/backups/imprenta DB_HOST=... ./respaldo.sh >> /var/log/imprenta-respaldo.log 2>&1
```

## La copia externa

**El guion no copia nada a otro lado, a propósito.** Cada instalación decide
adonde va su copia (una NAS, un servicio de objetos, otro servidor) y esa
decisión no se puede tomar desde un guion generico.

Lo que si dice el guion, al final:

```
[respaldo] recordatorio: este respaldo sigue en el servidor.
[respaldo] falta copiarlo fuera y probar una restauracion completa.
```

Un respaldo en el mismo servidor que la base no es un respaldo: es una copia
de convenience. Si se cae el disco, se caen los dos.

---

# La restauración

## El orden correcto

```
1. --simular                    <- SIEMPRE primero
2. revisar que dice bien
3. detener el servicio
4. --confirmar --force          <- solo si la base tiene tablas
5. dejarla detenida
6. revisarla
7. Recien ahi levantar
```

## Simular

```
./restauracion.sh --simular /var/backups/imprenta/imprenta-20261018-030000
```

No escribe nada. Comprueba el archivo, el manifiesto, la suma y la base de
destino.

## Las cuatro condiciones para escribir

### 1. La suma tiene que calzar

El SHA-256 del archivo se compara con el del manifiesto **antes de tocar la
base**:

```
[restauracion] ERROR: el archivo NO calza con el manifiesto.
[restauracion]        esperado: 3f7a...
[restauracion]        real:     91c4...
[restauracion]        no se restaura nada. El respaldo esta danado.
```

Un respaldo dañado restaurado a medias deja una base que **parece** buena y no
lo esta.

### 2. El respaldo tiene que estar completo

Sin el archivo `COMPLETO`, no se restaura. Si ademas esta `EN_CURSO`, menos
aun: significa que se estaba escribiendo cuando se corto.

### 3. Hay que pedir `--confirmar`

Sin esa opcion, el guion **informa y sale con código 3**, sin escribir nada.

```
[restauracion] FALTA LA CONFIRMACION: no se escribio nada.
[restauracion] si quiere restaurar de verdad, repita el comando con --confirmar.
```

### 4. No se restaura sobre una base que parezca de producción

Si el nombre de la base contiene `prod`, `produccion` o `live`, el guion se
niega:

```
[restauracion] ERROR: la base "imprenta_prod" PARECE DE PRODUCCION.
[restauracion]        este script se niega a restaurar sobre ella.
```

Se comprueba **antes de conectarse**, para que ni siquiera se intente. Restaurar
un respaldo de hace tres meses sobre la base de hoy, por error, es un proyecto
entero de trabajo perdido.

## Que nunca se hace

| Nunca | Por que |
|---|---|
| `DROP DATABASE` | No se tira la base |
| `DROP SCHEMA` | No se borra el esquema |
| `pg_restore --clean` | Genera `DROP` por dentro |
| Sobrescribir sin `--force` | La base de destino tiene que estar vacia |
| Dejar el servicio levantado | Se levanta a mano, despues de revisar |

Con `--force` lo que se hace es un `TRUNCATE` de todas las tablas en **una
transacción**, no un borrado de la base. La base sigue existiendo, con su
propietario y sus permisos.

Y la restauración va con `--single-transaction`: se aplica **completa o no se
aplica**. Una restauración a medias deja una base incoherente.

---

# Lo que hace el guion al terminar

## Deja el servicio detenido

El guion **no lo levanta**. Se detiene al principio y se queda detenido:

```
[restauracion] EL SERVICIO QUEDA DETENIDO.
[restauracion] Revise los datos antes de levantar nada:
[restauracion]   1. Revise que las aprobaciones de prueba estan ahi: son el
[restauracion]      respaldo de que el cliente aprobo antes de imprimir.
[restauracion]   2. Corra 'php artisan imprenta:verificar-aislamiento'.
[restauracion]   3. Corra 'php artisan imprenta:revisar-estados'.
[restauracion]   4. Solo despues levante el servicio.
```

## Y comprueba que la base conserva el aislamiento

Este paso es el que hace esta restauración distinta de una copia cualquiera.
**No basta con que la restauración termine bien.**

Una base restaurada sin sus políticas de Row Level Security se levanta y
muestra los pedidos de todas las imprentas. Y eso **no se nota en un log de
exito**: se nota cuando un cliente llama.

El guion comprueba tres cosas:

```
public.app_tenant_id() existe        <- sin ella, las politicas no filtran
hay politicas de RLS en public       <- sin ellas, no hay filtro
todas las tablas con RLS forzada      <- sin FORCE, el duena se salva
```

Y si falta alguna, **sale con código 1** y avisa:

```
[restauracion] LA BASE RESTAURADA NO SIRVE TODAVIA.
[restauracion] Corra 'php artisan migrate --force' sobre ella antes de levantar
[restauracion] cualquier servicio. NO levante el servicio con una base sin
[restauracion] aislamiento: mostraria los pedidos de todas las imprentas.
```

---

# Probar un respaldo

**Un respaldo que no se ha restaurado nunca no es un respaldo: es un archivo.**

Hacerlo, al menos una vez por trimestre:

```
1. Levantar un PostgreSQL descartable
2. Crear una base vacia:  imprenta_prueba
3. ./restauracion.sh --simular /ruta/al/respaldo
4. ./restauracion.sh --confirmar /ruta/al/respaldo
5. Verificar:
     - la base tiene las tablas
     - las aprobaciones de prueba estan ahi
     - php artisan imprenta:verificar-aislamiento
     - php artisan imprenta:revisar-estados
6. Borrar la base de prueba
```

El paso 5 es el importante: si el respaldo se hizo antes de que existiera una
tabla, o con un `pg_dump` de otra versión, la restauración "funciona" y deja
la base incompleta. Solo al mirar los datos uno se entera.

---

# Lo que falta

- **Los guiones no se ejecutaron** contra una base real. Su sintaxis se
  comprueba con `bash -n`, que no es lo mismo que haber respaldado algo.
- **La retencion se probara con 30 dias por omision**, no con una carpeta de
  respaldo reales de mas de 30 dias.
- **La restauración contra una base con tablas** (la rama de `--force`) no se
  probo.
- **No hay cifrado de los respaldos.** Si el destino es un medio extraible,
  convendria cifrarlo alla.

---

# Como progelo queda, escrito para no olvidarlo

- Un respaldo **diario**, completo.
- Una copia **fuera del servidor**, diaria.
- Una **restauración probada**, al menos trimestral.
- La retencion, en 30 dias, configurable.
- El destino con permisos `750`, creado a mano, nunca por el guion.

Si falta cualquiera de las cuatro, no hay respaldo. Hay archivos.
