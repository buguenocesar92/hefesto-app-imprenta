<template>
  <section class="pantalla">
    <div class="encabezado">
      <div>
        <h1>Entregas</h1>
        <p class="encabezado__sub">
          Pedidos con saldo pendiente. Un pedido entregado en dos no esta
          entregado: el estado se recalcula sumando las entregas.
        </p>
      </div>
    </div>

    <p v-if="error" class="regla regla--error">{{ error }}</p>
    <p v-if="aviso" class="regla regla--ok">{{ aviso }}</p>

    <p v-if="cargando" class="silencio">Cargando...</p>

    <div v-else-if="pendientes.length === 0" class="tarjeta tarjeta--ok">
      <h2>Nada pendiente</h2>
      <p class="chico">No hay pedidos con piezas por entregar.</p>
    </div>

    <div v-else>
      <div v-for="pedido in pendientes" :key="pedido.id" class="tarjeta">
        <div class="encabezado">
          <div>
            <h2>
              <NuxtLink :to="`/pedidos/${pedido.id}`">{{ pedido.numero }}</NuxtLink>
            </h2>
            <p class="encabezado__sub">
              {{ pedido.cliente ?? 'sin cliente' }} ·
              prometido para el {{ formatearFecha(pedido.fecha_prometida) }}
            </p>
          </div>
          <span class="estado" :class="`estado--${pedido.estado}`">
            {{ pedido.estado }}
          </span>
        </div>

        <p class="numero">
          Pedidas {{ pedido.cantidad_pedida }} · entregadas
          {{ pedido.cantidad_entregada }} ·
          <strong>faltan {{ pedido.saldo }}</strong>
        </p>

        <div class="barra">
          <span :style="{ width: porcentaje(pedido) + '%' }"></span>
        </div>

        <div v-if="puedeEntregar" class="registro">
          <div class="campo">
            <label :for="`cantidad-${pedido.id}`">Piezas a entregar ahora</label>
            <input
              :id="`cantidad-${pedido.id}`"
              v-model.number="cantidades[pedido.id]"
              type="number"
              min="1"
              :max="pedido.saldo"
            />
          </div>

          <div class="campo">
            <label :for="`medio-${pedido.id}`">Medio</label>
            <select :id="`medio-${pedido.id}`" v-model="medios[pedido.id]">
              <option value="retiro">Retiro</option>
              <option value="envio">Envio</option>
              <option value="mostrador">Mostrador</option>
            </select>
          </div>

          <button
            type="button"
            class="boton boton--principal"
            :disabled="registrando === pedido.id"
            @click="registrar(pedido)"
          >
            {{ registrando === pedido.id ? 'Registrando...' : 'Registrar entrega' }}
          </button>
        </div>
      </div>
    </div>

    <div class="tarjeta explicacion">
      <h2>Por que el pedido no se cierra solo</h2>
      <p class="chico">
        El saldo no se guarda en el pedido: se deriva sumando las entregas que
        tiene registradas. Por eso marcar una entrega no puede dejarlo en
        "completo" por error: el estado se recalcula y queda en "parcialmente
        entregado" mientras falte algo.
      </p>
      <p class="chico silencio">
        El sistema tampoco acepta entregar mas piezas de las que quedan, salvo
        que un administrador haya configurado una tolerancia a proposito.
      </p>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { EstadoPedido } from '~/tipos/api'

interface PedidoConSaldo {
  id: string
  numero: string
  cliente: string | null
  estado: EstadoPedido
  fecha_prometida: string | null
  cantidad_pedida: number
  cantidad_entregada: number
  saldo: number
}

/**
 * Pantalla del mostrador.
 *
 * Muestra el saldo como saldo, en piezas, no como un porcentaje. La barra es
 * solo de apoyo visual: el numero es el que manda.
 */
const { usuario } = useSesion()

const pendientes = ref<PedidoConSaldo[]>([])
const cantidades = reactive<Record<string, number | undefined>>({})
const medios = reactive<Record<string, string>>({})
const cargando = ref(true)
const error = ref<string | null>(null)
const aviso = ref('')
const registrando = ref<string | null>(null)

const puedeEntregar = computed(() => usuario.value?.puede_registrar_produccion === true)

function porcentaje(pedido: PedidoConSaldo): number {
  if (pedido.cantidad_pedida <= 0) {
    return 0
  }

  return Math.round((pedido.cantidad_entregada / pedido.cantidad_pedida) * 100)
}

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
    const respuesta = await $fetch<{ pendientes: PedidoConSaldo[] }>(
      '/api/v1/entregas/pendientes'
    )

    pendientes.value = respuesta.pendientes

    for (const pedido of respuesta.pendientes) {
      cantidades[pedido.id] ??= pedido.saldo
      medios[pedido.id] ??= 'retiro'
    }
  } catch {
    error.value = 'No se pudo cargar la lista de entregas pendientes.'
  } finally {
    cargando.value = false
  }
}

async function registrar(pedido: PedidoConSaldo): Promise<void> {
  error.value = null
  aviso.value = ''
  registrando.value = pedido.id

  const cantidad = cantidades[pedido.id] ?? 0

  try {
    const respuesta = await $fetch<{
      mensaje: string
      pedido: { saldo: number; estado: EstadoPedido; cerrado: boolean }
    }>(`/api/v1/pedidos/${pedido.id}/entregas`, {
      method: 'POST',
      body: {
        cantidad_unidades: cantidad,
        medio: medios[pedido.id] ?? 'retiro'
      }
    })

    aviso.value = respuesta.mensaje

    await cargar()
  } catch (fallo) {
    const cuerpo = fallo as { data?: { mensaje?: string } }

    error.value = cuerpo.data?.mensaje ?? 'No se pudo registrar la entrega.'
  } finally {
    registrando.value = null
  }
}

onMounted(cargar)

useHead({ title: 'Entregas' })
</script>

<style scoped>
.barra {
  height: 0.5rem;
  background: #e4e8ed;
  border-radius: 999px;
  overflow: hidden;
  margin: 0.6rem 0 0.9rem;
}

.barra span {
  display: block;
  height: 100%;
  background: var(--principal);
}

.registro {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: 0.75rem;
  align-items: end;
}

.explicacion {
  margin-top: 1.5rem;
}
</style>
