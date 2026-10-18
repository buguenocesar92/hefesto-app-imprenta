<template>
  <section class="pantalla">
    <p v-if="cargando" class="silencio">Cargando...</p>

    <p v-else-if="error" class="regla regla--error">{{ error }}</p>

    <div v-else-if="pedido">
      <div class="encabezado">
        <div>
          <h1>Pedido {{ pedido.numero }}</h1>
          <p class="encabezado__sub">
            {{ pedido.cliente ?? 'sin cliente' }} ·
            prometido para el {{ formatearFecha(pedido.fecha_prometida) }}
          </p>
        </div>
        <span class="estado" :class="`estado--${pedido.estado}`">
          {{ pedido.estado }}
        </span>
      </div>

      <!--
        El aviso central: nada sale a imprenta sin prueba aprobada. Se
        muestra siempre que el pedido no sea imprimible, para que el taller
        sepa a quien llamar en vez de descubrirlo al subir a la plancha.
      -->
      <div v-if="!pedido.puede_imprimirse" class="regla regla--error">
        <strong>Este pedido no se puede imprimir.</strong>
        <span v-if="pedido.piezas_pendientes.length > 0">
          Falta que el cliente apruebe:
          {{ pedido.piezas_pendientes.join('; ') }}.
        </span>
        <span v-else>Todavia no tiene piezas con prueba aprobada.</span>
      </div>

      <div v-else class="regla regla--ok">
        Todas las piezas tienen prueba aprobada. Este pedido se puede imprimir.
        <span v-if="pedido.aprobado_por">
          Aprobado por {{ pedido.aprobado_por }}.
        </span>
      </div>

      <div class="rejilla">
        <div class="tarjeta">
          <h2>Piezas</h2>
          <ul class="chico lista-cifras">
            <li><span>Pedidas</span> <strong class="numero">{{ pedido.cantidad_pedida }}</strong></li>
            <li><span>Entregadas</span> <strong class="numero">{{ pedido.cantidad_entregada }}</strong></li>
            <li>
              <span>Saldo</span>
              <strong class="numero">{{ pedido.saldo }}</strong>
            </li>
          </ul>

          <NuxtLink to="/entregas" class="boton boton--chico">Ir a entregas</NuxtLink>
        </div>

        <div v-if="pedido.estado === 'aprobado' && puedeAprobar" class="tarjeta">
          <h2>Aprobar el pedido</h2>
          <p class="chico silencio">
            Se aprueba solo si todas las piezas ya tienen su prueba aprobada.
            El sistema no deja aprobar un pedido a medias.
          </p>
          <button type="button" class="boton boton--principal" @click="aprobar">
            Marcar como aprobado
          </button>
        </div>
      </div>

      <div class="tarjeta">
        <h2>Que se imprime</h2>

        <table class="tabla">
          <thead>
            <tr>
              <th>Pieza</th>
              <th>Papel</th>
              <th class="derecha">Piezas</th>
              <th>Prueba</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in pedido.items" :key="item.id">
              <td>
                {{ item.descripcion }}
                <span class="chico silencio">({{ item.medida }})</span>
              </td>
              <td class="chico">{{ item.papel ?? 'sin papel' }}</td>
              <td class="numero derecha">{{ item.cantidad_unidades }}</td>
              <td>
                <span v-if="item.tiene_prueba_aprobada" class="estado estado--aprobado">
                  aprobada
                </span>
                <span v-else-if="item.prueba_vencida" class="estado estado--anulado">
                  vencida
                </span>
                <span v-else class="estado estado--esperando_aprobacion">
                  sin aprobar
                </span>
              </td>
              <td>
                <button
                  type="button"
                  class="boton boton--chico"
                  @click="verConsumo(item.id)"
                >
                  Ver papel
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="consumo" class="tarjeta">
        <h2>Cuanto papel necesita</h2>

        <p class="chico silencio">
          La cuenta completa. El mismo papel rinde distinto segun la medida de
          la pieza, asi que esto se calcula por pieza y no es un numero fijo
          del catalogo.
        </p>

        <ul class="desglose">
          <li v-for="(paso, indice) in consumo.pasos" :key="indice">{{ paso }}</li>
        </ul>

        <h3 class="chico">Merma por origen</h3>
        <ul class="desglose">
          <li v-for="merma in consumo.mermas" :key="merma.origen">
            {{ merma.etiqueta }}: {{ merma.porcentaje }} % =
            {{ merma.pliegos }} pliegos.
            <span v-if="merma.porcentaje_real !== null" class="chico">
              Real de esta corrida: {{ merma.porcentaje_real }} %.
            </span>
          </li>
        </ul>
      </div>

      <div class="tarjeta">
        <h2>Entregas</h2>

        <table class="tabla">
          <thead>
            <tr>
              <th>Piezas</th>
              <th>Medio</th>
              <th>Fecha</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entrega in pedido.entregas" :key="entrega.id">
              <td class="numero">{{ entrega.cantidad_unidades }}</td>
              <td>{{ entrega.medio }}</td>
              <td class="numero">{{ formatearFecha(entrega.entregado_en) }}</td>
            </tr>
            <tr v-if="pedido.entregas.length === 0">
              <td colspan="3" class="tabla__vacia">Todavia no se entrego nada.</td>
            </tr>
          </tbody>
        </table>

        <p v-if="pedido.saldo > 0" class="chico silencio">
          Quedan {{ pedido.saldo }} piezas por entregar. Marcar una entrega no
          cierra el pedido: se cierra cuando lo entregado cubre lo pedido.
        </p>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { DesgloseDeConsumo, Pedido } from '~/tipos/api'

/**
 * Ficha del pedido.
 *
 * Esta pantalla es la que evita el error caro del rubro: muestra, ANTES de
 * imprimir, si el pedido tiene la prueba aprobada o no. Y cuando dice que si,
 * muestra por que el papel alcanza: el desglose paso a paso.
 */
const ruta = useRoute()
const { esSupervisor } = useSesion()

const pedido = ref<Pedido | null>(null)
const consumo = ref<DesgloseDeConsumo | null>(null)
const cargando = ref(true)
const error = ref<string | null>(null)
const aviso = ref<string | null>(null)

const puedeAprobar = computed(() => esSupervisor.value)

async function cargar(): Promise<void> {
  cargando.value = true

  try {
    pedido.value = await $fetch<Pedido>(`/api/v1/pedidos/${ruta.params.id}`)
  } catch {
    error.value =
      'No se encontro ese pedido. Si el numero es correcto, quiza la empresa elegida al entrar no es la duena.'
  } finally {
    cargando.value = false
  }
}

async function verConsumo(itemId: string): Promise<void> {
  try {
    consumo.value = await $fetch<DesgloseDeConsumo>(`/api/v1/items/${itemId}/consumo`)
  } catch (fallo) {
    consumo.value = null
    aviso.value =
      fallo instanceof Error
        ? fallo.message
        : 'No se pudo calcular el consumo de esta pieza.'
  }
}

async function aprobar(): Promise<void> {
  aviso.value = null

  try {
    await $fetch(`/api/v1/pedidos/${ruta.params.id}/aprobar`, { method: 'POST' })
    await cargar()
  } catch (fallo) {
    const cuerpo = fallo as { data?: { mensaje?: string } }

    aviso.value =
      cuerpo.data?.mensaje ?? 'No se pudo aprobar el pedido. Revise las pruebas.'
  }
}

function formatearFecha(valor: string | null): string {
  if (valor === null) {
    return 'sin fecha'
  }

  const fecha = new Date(valor)

  return Number.isNaN(fecha.getTime()) ? valor : fecha.toLocaleDateString('es-CL')
}

onMounted(cargar)

useHead({ title: 'Pedido' })
</script>

<style scoped>
.rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(17rem, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.lista-cifras {
  list-style: none;
  padding: 0;
  margin: 0 0 0.75rem;
}

.lista-cifras li {
  display: flex;
  justify-content: space-between;
  padding: 0.2rem 0;
  border-bottom: 1px solid var(--linea);
}

.lista-cifras li:last-child {
  border-bottom: none;
}

.derecha {
  text-align: right;
}
</style>
