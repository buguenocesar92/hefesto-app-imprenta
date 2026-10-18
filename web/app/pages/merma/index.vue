<template>
  <section class="pantalla">
    <div class="encabezado">
      <div>
        <h1>Merma</h1>
        <p class="encabezado__sub">
          Cuanto papel se gasto sin llegar a ser producto. La diferencia entre
          lo usado y lo entregado es la plata, asi que queda a la vista.
        </p>
      </div>
      <span class="chico silencio">
        Se avisa cuando el desvío pasa de {{ umbral_aviso_puntos }} puntos
        porcentuales.
      </span>
    </div>

    <p v-if="error" class="regla regla--error">{{ error }}</p>

    <div v-if="cargando" class="silencio">Cargando...</div>

    <template v-else>
      <p v-if="requierenRevision > 0" class="regla regla--aviso">
        {{ requierenRevision }} papel(es) se estan yendo de la prevision. Con
        el tiempo, esos numeros son los que hay que corregir en el catalogo:
        de ahi sale la merma real de la imprenta.
      </p>

      <p v-if="resumen.length === 0" class="tarjeta">
        Todavia no hay tiradas registradas, asi que no hay merma que medir.
        Merma real significa medida, no estimada.
      </p>

      <table v-else class="tabla">
        <thead>
          <tr>
            <th>Papel</th>
            <th class="derecha">Prevista</th>
            <th class="derecha">Real</th>
            <th class="derecha">Desvío</th>
            <th class="derecha">Pliegos perdidos</th>
            <th class="derecha">Tiradas</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="fila in resumen"
            :key="fila.codigo ?? fila.papel"
            :class="{ marcado: fila.requiere_revision }"
          >
            <td>
              {{ fila.papel ?? 'papel sin nombre' }}
              <span class="chico silencio">{{ fila.codigo }}</span>
              <span v-if="fila.requiere_revision" class="estado estado--esperando_aprobacion">
                revisar
              </span>
            </td>
            <td class="numero derecha">
              <span v-if="fila.merma_prevista_porcentaje !== null">
                {{ fila.merma_prevista_porcentaje }} %
              </span>
              <span v-else class="silencio chico">sin carga</span>
            </td>
            <td class="numero derecha">
              <span v-if="fila.merma_real_porcentaje !== null">
                {{ fila.merma_real_porcentaje }} %
              </span>
              <span v-else class="silencio chico">sin medir</span>
            </td>
            <td class="numero derecha">
              <span v-if="fila.desvio_puntos !== null">
                {{ fila.desvio_puntos > 0 ? '+' : '' }}{{ fila.desvio_puntos }}
              </span>
              <span v-else class="silencio chico">—</span>
            </td>
            <td class="numero derecha">{{ fila.pliegos_prueba + fila.pliegos_merma }}</td>
            <td class="numero derecha">{{ fila.ordenes }}</td>
          </tr>
        </tbody>
      </table>

      <div class="tarjeta explicacion">
        <h2>Prevista y real son dos cosas distintas</h2>
        <ul class="chico">
          <li>
            <strong>Prevista</strong>: el porcentaje cargado en el catalogo del
            papel. Es un supuesto con el que se cotiza.
          </li>
          <li>
            <strong>Real</strong>: lo que efectivamente se perdio, contado en
            las tiradas.
          </li>
          <li>
            <strong>De prueba</strong>: papel que se gasto en sacar la maqueta.
            En trabajos con muchas correcciones es la mayor parte de la
            perdida, y el cliente no la ve.
          </li>
          <li>
            <strong>De plancha</strong>: papel danado al pasar por la maquina.
            Es impredecible, y por eso se mide por tirada en vez de suponerse.
          </li>
        </ul>
        <p class="chico silencio">
          Cuando el desvío se mantiene, el porcentaje del catalogo esta viejo.
          Corregirlo es la forma de que la merma estimada se parezca a la real.
        </p>
      </div>
    </template>
  </section>
</template>

<script setup lang="ts">
import type { ResumenDeMerma } from '~/tipos/api'

/**
 * Informe de merma por papel.
 *
 * Responde la pregunta que mas cuesta: "adonde se fue la plata". La pantalla
 * separa la merma que la imprenta SUPUSO de la que MIDIO, porque solo mirar
 * la prevista no sirve de nada para cotizar bien.
 */
const resumen = ref<ResumenDeMerma[]>([])
const umbral_aviso_puntos = ref(5)
const total_ordenes = ref(0)
const cargando = ref(true)
const error = ref<string | null>(null)

const requierenRevision = computed(
  () => resumen.value.filter((fila) => fila.requiere_revision).length
)

onMounted(async () => {
  try {
    const respuesta = await $fetch<{
      resumen: ResumenDeMerma[]
      umbral_aviso_puntos: number
      total_ordenes: number
    }>('/api/v1/merma')

    resumen.value = respuesta.resumen
    umbral_aviso_puntos.value = respuesta.umbral_aviso_puntos
    total_ordenes.value = respuesta.total_ordenes
  } catch {
    error.value = 'No se pudo cargar el informe de merma.'
  } finally {
    cargando.value = false
  }
})

useHead({ title: 'Merma' })
</script>

<style scoped>
.marcado {
  background: var(--aviso-claro);
}

.derecha {
  text-align: right;
}

.explicacion {
  margin-top: 1.5rem;
}

.explicacion ul {
  padding-left: 1.1rem;
}

.explicacion li {
  margin-bottom: 0.3rem;
}
</style>
