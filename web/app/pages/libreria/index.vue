<template>
  <section class="pantalla">
    <div class="encabezado">
      <div>
        <h1>Libreria y papeleria</h1>
        <p class="encabezado__sub">
          La reventa que acompana al negocio de impresion. El stock sale de los
          movimientos: no se edita a mano.
        </p>
      </div>
    </div>

    <p v-if="error" class="regla regla--error">{{ error }}</p>
    <p v-if="aviso" class="regla regla--ok">{{ aviso }}</p>

    <div v-if="cargando" class="silencio">Cargando...</div>

    <div v-else>
      <table class="tabla">
        <thead>
          <tr>
            <th>Codigo</th>
            <th>Articulo</th>
            <th>Categoria</th>
            <th class="derecha">Stock</th>
            <th class="derecha">Compra</th>
            <th class="derecha">Venta</th>
            <th>Movimiento</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="articulo in articulos" :key="articulo.id">
            <td class="numero chico">{{ articulo.sku }}</td>
            <td>{{ articulo.nombre }}</td>
            <td class="chico">{{ articulo.categoria }}</td>
            <td class="numero derecha" :class="{ agotado: articulo.stock <= 0 }">
              {{ articulo.stock }} {{ articulo.unidad }}
            </td>
            <td class="numero derecha">
              <span v-if="articulo.precio_compra !== null">
                $ {{ articulo.precio_compra }}
              </span>
              <span v-else class="silencio chico">sin cargar</span>
            </td>
            <td class="numero derecha">
              <span v-if="articulo.precio_venta !== null">
                $ {{ articulo.precio_venta }}
              </span>
              <span v-else class="silencio chico">sin cargar</span>
            </td>
            <td>
              <div v-if="puedeMover" class="fila">
                <input
                  v-model.number="cantidades[articulo.id]"
                  type="number"
                  min="1"
                  step="1"
                  class="cantidad"
                  :aria-label="`Cantidad para ${articulo.nombre}`"
                />
                <button
                  type="button"
                  class="boton boton--chico"
                  :disabled="moviendo === articulo.id"
                  @click="mover(articulo, 'entrada', 'compra')"
                >
                  + entrada
                </button>
                <button
                  type="button"
                  class="boton boton--chico"
                  :disabled="moviendo === articulo.id"
                  @click="mover(articulo, 'salida', 'venta')"
                >
                  - salida
                </button>
              </div>
              <span v-else class="chico silencio">—</span>
            </td>
          </tr>
          <tr v-if="articulos.length === 0">
            <td colspan="7" class="tabla__vacia">No hay articulos en el catalogo.</td>
          </tr>
        </tbody>
      </table>

      <div class="tarjeta explicacion">
        <h2>Por que el stock no se escribe</h2>
        <p class="chico">
          El stock de cada articulo es la suma de sus movimientos: las compras
          suman y las ventas restan. Si el stock fuera una casilla que se
          corrige a mano, en dos semanas de ventas estaria pasado y nadie
          sabria en cuanto.
        </p>
        <p class="chico silencio">
          Tampoco se puede dejar en negativo: un CHECK de PostgreSQL y un
          gatillo lo impiden, porque en un local Chico casi siempre es un error
          de tipeo y no una devolucion.
        </p>
        <p class="chico silencio">
          Los precios aparecen como "sin cargar" mientras no los cargue un
          administrador. Esta aplicacion no los inventa: son datos comerciales de
          la imprenta.
        </p>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { ArticuloDeLibreria } from '~/tipos/api'

/**
 * Catalogo de libreria y papeleria.
 *
 * Es el flujo simple al lado del resto y esta bien que lo sea: un cuaderno se
 * compra y se vende, sin maqueta que aprobar ni consumo de plancha. Lo que si
 * comparte con el resto es el aislamiento por empresa.
 */
const { usuario } = useSesion()

const articulos = ref<ArticuloDeLibreria[]>([])
const cantidades = reactive<Record<string, number | undefined>>({})
const cargando = ref(true)
const error = ref<string | null>(null)
const aviso = ref('')
const moviendo = ref<string | null>(null)

const puedeMover = computed(() => usuario.value?.puede_registrar_produccion === true)

async function cargar(): Promise<void> {
  cargando.value = true

  try {
    const respuesta = await $fetch<{ articulos: ArticuloDeLibreria[] }>('/api/v1/libreria')

    articulos.value = respuesta.articulos
  } catch {
    error.value = 'No se pudo cargar el catalogo de libreria.'
  } finally {
    cargando.value = false
  }
}

async function mover(
  articulo: ArticuloDeLibreria,
  sentido: 'entrada' | 'salida',
  motivo: 'compra' | 'venta'
): Promise<void> {
  error.value = null
  aviso.value = ''
  moviendo.value = articulo.id

  const cantidad = cantidades[articulo.id] ?? 1

  try {
    const respuesta = await $fetch<{
      mensaje: string
      articulo: { stock: number }
    }>(`/api/v1/libreria/${articulo.id}/movimientos`, {
      method: 'POST',
      body: { sentido, cantidad, motivo }
    })

    aviso.value = `${respuesta.mensaje} Stock actual: ${respuesta.articulo.stock}.`

    await cargar()
  } catch (fallo) {
    const cuerpo = fallo as { data?: { mensaje?: string } }

    error.value = cuerpo.data?.mensaje ?? 'No se pudo registrar el movimiento.'
  } finally {
    moviendo.value = null
  }
}

onMounted(cargar)

useHead({ title: 'Libreria' })
</script>

<style scoped>
.derecha {
  text-align: right;
}

.agotado {
  color: var(--alerta);
  font-weight: 600;
}

.cantidad {
  width: 5rem;
  padding: 0.28rem 0.4rem;
  border: 1px solid var(--linea);
  border-radius: 0.3rem;
  font: inherit;
}

.explicacion {
  margin-top: 1.5rem;
}
</style>
