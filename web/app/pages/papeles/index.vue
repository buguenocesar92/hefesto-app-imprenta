<template>
  <section class="pantalla">
    <div class="encabezado">
      <div>
        <h1>Papeles</h1>
        <p class="encabezado__sub">
          El catalogo que define el costo. El rendimiento NO esta aca: depende
          de la medida de la pieza y se calcula por trabajo.
        </p>
      </div>
      <button type="button" class="boton" @click="verPlanchas = !verPlanchas">
        {{ verPlanchas ? 'Ver papeles' : 'Ver planchas' }}
      </button>
    </div>

    <p v-if="error" class="regla regla--error">{{ error }}</p>

    <!--
        Aviso sobre la merma sin cargar. No hay valor por omision: una merma
        inventada es plata inventada. Por eso el papel aparece marcado como
        pendiente en vez de calcularse con un supuesto.
    -->
    <p v-if="papelesSinMerma > 0" class="regla regla--pendiente">
      {{ papelesSinMerma }} papel(es) sin porcentajes de merma cargados. Con
      esos papeles el sistema NO calcula consumo: prefiere no responder antes
      que inventar cuanto papel se pierde. Cargalos con el historial real de
      la imprenta (un administrador lo puede hacer).
    </p>

    <p v-if="verPlanchas">
      <span v-if="cargando">Cargando...</span>
    </p>

    <table v-if="!verPlanchas && !cargando" class="tabla">
      <thead>
        <tr>
          <th>Codigo</th>
          <th>Papel</th>
          <th>Plancha</th>
          <th>Compra</th>
          <th>Merma</th>
          <th class="derecha">Precio por kg</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="papel in papeles" :key="papel.id">
          <td class="numero chico">{{ papel.codigo }}</td>
          <td>{{ papel.descripcion }}</td>
          <td class="chico">{{ papel.plancha ?? 'sin plancha' }}</td>
          <td class="chico">{{ papel.unidad_compra }}</td>
          <td>
            <span v-if="papel.merma_configurada" class="estado estado--aprobado">
              cargada
            </span>
            <span v-else class="estado estado--anulado">pendiente</span>
          </td>
          <td class="numero derecha">
            <span v-if="papel.precio_referencia_por_kg !== null">
              $ {{ papel.precio_referencia_por_kg }}
            </span>
            <span v-else class="silencio chico">sin cargar</span>
          </td>
        </tr>
        <tr v-if="papeles.length === 0">
          <td colspan="6" class="tabla__vacia">No hay papeles en el catalogo.</td>
        </tr>
      </tbody>
    </table>

    <table v-else-if="!cargando" class="tabla">
      <thead>
        <tr>
          <th>Codigo</th>
          <th>Plancha</th>
          <th class="derecha">Area</th>
          <th class="derecha">Pliegos por paquete</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="plancha in planchas" :key="plancha.id">
          <td class="numero chico">{{ plancha.codigo }}</td>
          <td>{{ plancha.medida }}</td>
          <td class="numero derecha">{{ plancha.area_m2 }} m2</td>
          <td class="numero derecha">
            <span v-if="plancha.pliegos_por_paquete !== null">
              {{ plancha.pliegos_por_paquete }}
            </span>
            <span v-else class="silencio chico">sin dato</span>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="tarjeta explicacion">
      <h2>Por que no hay un numero de piezas por pliego</h2>
      <p class="chico">
        Un papel de 300 g/m2 es el mismo para una tarjeta de 9 x 5 cm que para
        un afiche de 60 x 90 cm. En el primer caso rinde decenas de piezas por
        plancha; en el segundo, ni una. El rendimiento sale de la medida de la
        plancha, de la medida de la pieza y de cuantas piezas se agrupan en
        cada cara, asi que se calcula en cada cotizacion.
      </p>
      <p class="chico silencio">
        Lo unico que sale del catalogo es el gramaje, el tipo y la medida de la
        plancha: eso es lo que fija el costo y lo que el cliente puede pedir.
      </p>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { Papel, Plancha } from '~/tipos/api'

/**
 * Catalogo de papeles y planchas.
 *
 * La pantalla existe sobre todo para exponer lo que NO se puede calcular:
 * un papel sin merma cargada aparece marcado como pendiente, con la razon
 * escrita. Ocultarlo seria prometer un calculo que el sistema no puede hacer.
 */
const papeles = ref<Papel[]>([])
const planchas = ref<Plancha[]>([])
const cargando = ref(true)
const error = ref<string | null>(null)
const verPlanchas = ref(false)

const papelesSinMerma = computed(
  () => papeles.value.filter((papel) => !papel.merma_configurada).length
)

async function cargar(): Promise<void> {
  cargando.value = true

  try {
    const [respuestaPapeles, respuestaPlanchas] = await Promise.all([
      $fetch<{ papeles: Papel[] }>('/api/v1/papeles'),
      $fetch<{ planchas: Plancha[] }>('/api/v1/planchas')
    ])

    papeles.value = respuestaPapeles.papeles
    planchas.value = respuestaPlanchas.planchas
  } catch {
    error.value = 'No se pudo cargar el catalogo de papeles.'
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

useHead({ title: 'Papeles' })
</script>

<style scoped>
.explicacion {
  margin-top: 1.5rem;
}

.derecha {
  text-align: right;
}
</style>
