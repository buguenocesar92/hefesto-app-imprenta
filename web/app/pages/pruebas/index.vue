<template>
  <section class="pantalla">
    <div class="encabezado">
      <div>
        <h1>Pruebas y aprobaciones</h1>
        <p class="encabezado__sub">
          Lo que el cliente tiene que ver antes de que se imprima. Vencida NO
          es aprobada: una prueba sin respuesta deja el pedido sin imprimir.
        </p>
      </div>
      <span class="chico silencio">Plazo: {{ dias_para_vencer }} dias</span>
    </div>

    <p v-if="error" class="regla regla--error">{{ error }}</p>

    <div v-if="enviandoA !== null" class="regla regla--ok">{{ aviso }}</div>

    <p v-if="cargando" class="silencio">Cargando...</p>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Pieza</th>
          <th>Archivo</th>
          <th>Version</th>
          <th>Estado</th>
          <th>Enviada</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="prueba in pruebas" :key="prueba.id">
          <td>{{ prueba.item ?? 'pieza eliminada' }}</td>
          <td class="chico">{{ prueba.archivo ?? 'sin archivo' }}</td>
          <td class="numero">{{ prueba.version }}</td>
          <td>
            <span v-if="prueba.vencida" class="estado estado--anulado">vencida</span>
            <span v-else class="estado estado--esperando_aprobacion">
              {{ prueba.estado }}
            </span>
          </td>
          <td class="numero chico">{{ formatearFecha(prueba.enviada_en) }}</td>
          <td>
            <div v-if="puedeAprobar" class="fila">
              <button
                type="button"
                class="boton boton--chico boton--principal"
                :disabled="enviandoA !== null"
                @click="resolver(prueba.id, 'aprobada')"
              >
                Aprobar
              </button>
              <button
                type="button"
                class="boton boton--chico"
                :disabled="enviandoA !== null"
                @click="resolver(prueba.id, 'rechazada')"
              >
                Rechazar
              </button>
            </div>
            <span v-else class="chico silencio">
              Solo un supervisor registra la respuesta del cliente.
            </span>
          </td>
        </tr>
        <tr v-if="pruebas.length === 0">
          <td colspan="6" class="tabla__vacia">
            No hay pruebas esperando respuesta. Buen trabajo.
          </td>
        </tr>
      </tbody>
    </table>

    <div class="tarjeta explicacion">
      <h2>Que significa cada cosa</h2>
      <ul class="chico">
        <li>
          <strong>Pendiente</strong>: se pidio la prueba, todavia no se mostro
          al cliente.
        </li>
        <li>
          <strong>Enviada</strong>: el cliente ya la vio y no ha respondido.
          Pasados los dias del plazo, figura vencida.
        </li>
        <li>
          <strong>Aprobada</strong>: el cliente la aprobo. Es lo unico que
          habilita imprimir.
        </li>
        <li>
          <strong>Rechazada</strong>: hay que corregir el arte y mandar una
          version nueva. La anterior queda como prueba de que se corrigio.
        </li>
      </ul>
      <p class="chico silencio">
        El sistema nunca aprueba por silencio. Vencido el plazo, el pedido
        sigue sin aprobar: hay que llamar al cliente.
      </p>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { PruebaEnEspera } from '~/tipos/api'

/**
 * Cola de pruebas.
 *
 * Es la pantalla con la que se decide a quien llamar primero: las vencidas
 * van primero porque son las que tienen a un pedido parado.
 *
 * Aprobar NO lo hace el supervisor por decision propia: lo que se registra
 * aca es la respuesta del cliente. Guardar el nombre de quien la registro es
 * lo que hace auditable el pedido meses despues.
 */
const { esSupervisor } = useSesion()

const pruebas = ref<PruebaEnEspera[]>([])
const dias_para_vencer = ref(0)
const cargando = ref(true)
const error = ref<string | null>(null)
const aviso = ref('')
const enviandoA = ref<string | null>(null)

const puedeAprobar = computed(() => esSupervisor.value)

function formatearFecha(valor: string | null): string {
  if (valor === null) {
    return 'sin fecha'
  }

  const fecha = new Date(valor)

  return Number.isNaN(fecha.getTime()) ? valor : fecha.toLocaleDateString('es-CL')
}

async function cargar(): Promise<void> {
  cargando.value = true

  try {
    const respuesta = await $fetch<{
      dias_para_vencer: number
      pruebas: PruebaEnEspera[]
    }>('/api/v1/pruebas')

    dias_para_vencer.value = respuesta.dias_para_vencer
    pruebas.value = respuesta.pruebas
  } catch {
    error.value = 'No se pudo cargar la cola de pruebas.'
  } finally {
    cargando.value = false
  }
}

async function resolver(pruebaId: string, estado: 'aprobada' | 'rechazada'): Promise<void> {
  error.value = null
  aviso.value = ''
  enviandoA.value = pruebaId

  try {
    const respuesta = await $fetch<{ mensaje: string }>(
      `/api/v1/pruebas/${pruebaId}/resolver`,
      { method: 'POST', body: { estado } }
    )

    aviso.value = respuesta.mensaje

    await cargar()
  } catch (fallo) {
    const cuerpo = fallo as { data?: { mensaje?: string } }

    error.value = cuerpo.data?.mensaje ?? 'No se pudo registrar la respuesta.'
  } finally {
    enviandoA.value = null
  }
}

onMounted(cargar)

useHead({ title: 'Pruebas' })
</script>

<style scoped>
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
