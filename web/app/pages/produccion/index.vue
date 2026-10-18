<template>
  <section class="pantalla">
    <div class="encabezado">
      <div>
        <h1>Produccion</h1>
        <p class="encabezado__sub">
          Las tiradas ya registradas, con la merma de cada una. Nada entra aca
          sin que el cliente haya aprobado la prueba.
        </p>
      </div>
    </div>

    <p v-if="error" class="regla regla--error">{{ error }}</p>

    <div class="tarjeta">
      <h2>Registrar una tirada</h2>
      <p class="chico silencio">
        Elija una pieza. El sistema calcula los pliegos: usted solo cuenta
        cuantas piezas buenas salieron.
      </p>

      <div class="campo">
        <label for="pieza">Pieza</label>
        <select id="pieza" v-model="seleccionada">
          <option value="">Elija una pieza...</option>
          <option v-for="opcion in piezas" :key="opcion.id" :value="opcion.id">
            {{ opcion.descripcion }} — {{ opcion.estado }}
          </option>
        </select>
      </div>

      <p v-if="seleccionada" class="chico">
        <template v-if="seleccionada.aprobado">
          Esta lista para imprimir. Piezas a producir:
          <strong class="numero">{{ seleccionada.cantidad }}</strong>.
        </template>
        <template v-else>
          <span class="estado estado--anulado">sin prueba aprobada</span>
          No se puede registrar produccion de esta pieza. Averigue quien tiene
          que aprobar la prueba.
        </template>
      </p>

      <div v-if="seleccionada?.aprobado" class="campo">
        <label for="bueno">Piezas buenas de esta corrida (opcional)</label>
        <input
          id="bueno"
          v-model.number="productoBueno"
          type="number"
          min="0"
          placeholder="Si no lo sabe, deje el campo vacio."
        />
        <p class="chico silencio">
          Es el unico numero que se cuenta a mano. Los pliegos los calcula el
          sistema, y si se recibieran a mano el informe de merma seria tan
          inventado como la merma que dice medir.
        </p>
      </div>

      <button
        type="button"
        class="boton boton--principal"
        :disabled="!puedeRegistrar || seleccionados === null"
        @click="registrar"
      >
        Registrar tirada
      </button>

      <p v-if="!puedeRegistrar" class="chico silencio">
        Su rol no registra produccion.
      </p>
    </div>

    <div v-if="aviso" class="regla regla--ok">{{ aviso }}</div>

    <p v-if="cargando" class="silencio">Cargando...</p>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Pieza</th>
          <th class="derecha">Netos</th>
          <th class="derecha">Prueba</th>
          <th class="derecha">Merma</th>
          <th class="derecha">Total</th>
          <th class="derecha">Buenas</th>
          <th class="derecha">Merma real</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="orden in ordenes" :key="orden.id">
          <td>{{ orden.item?.descripcion ?? 'pieza' }}</td>
          <td class="numero derecha">{{ orden.pliegos_netos }}</td>
          <td class="numero derecha">{{ orden.pliegos_prueba }}</td>
          <td class="numero derecha">{{ orden.pliegos_merma }}</td>
          <td class="numero derecha"><strong>{{ orden.pliegos_totales }}</strong></td>
          <td class="numero derecha">{{ orden.producto_bueno }}</td>
          <td class="numero derecha">
            <span v-if="orden.merma_real_porcentaje !== null">
              {{ orden.merma_real_porcentaje }} %
            </span>
            <span v-else class="silencio chico">sin medir</span>
          </td>
        </tr>
        <tr v-if="ordenes.length === 0">
          <td colspan="7" class="tabla__vacia">Todavia no hay tiradas registradas.</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<script setup lang="ts">
import type { OrdenDeProduccion } from '~/tipos/api'

interface PiezaParaProducir {
  id: string
  descripcion: string
  estado: string
  cantidad: number
  aprobado: boolean
}

interface OrdenEnLaLista extends OrdenDeProduccion {
  item: { descripcion: string } | null
}

/**
 * Registro de tiradas.
 *
 * El formulario NO pide los pliegos, y eso no es una omission: si los
 * pidiera, el taller escribiria un numero y el informe de merma seria una
 * opinion. Lo unico que se cuenta a mano son las piezas buenas.
 */
const { puedeProducir } = useSesion()

const piezas = ref<PiezaParaProducir[]>([])
const ordenes = ref<OrdenEnLaLista[]>([])
const seleccionada = ref<string>('')
const productoBueno = ref<number | null>(null)
const cargando = ref(true)
const error = ref<string | null>(null)
const aviso = ref('')

const puedeRegistrar = computed(() => puedeProducir.value)
const seleccionados = computed(() => piezas.value.find((p) => p.id === seleccionada.value) ?? null)

async function cargar(): Promise<void> {
  cargando.value = true

  try {
    const respuesta = await $fetch<{ data: OrdenEnLaLista[] }>('/api/v1/produccion')

    ordenes.value = respuesta.data
  } catch {
    error.value = 'No se pudieron cargar las tiradas.'
  } finally {
    cargando.value = false
  }
}

async function registrar(): Promise<void> {
  error.value = null
  aviso.value = ''

  try {
    const respuesta = await $fetch<{
      mensaje: string
      orden: OrdenDeProduccion
    }>(`/api/v1/produccion/${seleccionada.value}`, {
      method: 'POST',
      body: { producto_bueno: productoBueno.value }
    })

    aviso.value =
      `${respuesta.mensaje} Total: ${respuesta.orden.pliegos_totales} pliegos.`

    productoBueno.value = null

    await cargar()
  } catch (fallo) {
    const cuerpo = fallo as { data?: { mensaje?: string } }

    // El mensaje de la API explica el motivo: "sin prueba aprobada" o
    // "falta la merma del papel". Se muestra tal cual.
    error.value = cuerpo.data?.mensaje ?? 'No se pudo registrar la tirada.'
  }
}

onMounted(cargar)

useHead({ title: 'Produccion' })
</script>

<style scoped>
.derecha {
  text-align: right;
}
</style>
