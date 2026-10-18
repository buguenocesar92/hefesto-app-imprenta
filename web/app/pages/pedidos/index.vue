<template>
  <section class="pantalla">
    <div class="encabezado">
      <div>
        <h1>Pedidos</h1>
        <p class="encabezado__sub">
          Cada pedido es una produccion con una fecha prometida. La columna
          "imprimible" dice si el cliente ya aprobo la prueba de cada pieza.
        </p>
      </div>
    </div>

    <p v-if="error" class="regla regla--error">{{ error }}</p>

    <div class="filtros">
      <label class="chico">
        Estado
        <select v-model="estado" @change="cargar">
          <option value="">Todos</option>
          <option value="borrador">Borrador</option>
          <option value="esperando_aprobacion">Esperando aprobacion</option>
          <option value="aprobado">Aprobado</option>
          <option value="en_produccion">En produccion</option>
          <option value="parcialmente_entregado">Parcialmente entregado</option>
          <option value="entregado">Entregado</option>
          <option value="anulado">Anulado</option>
        </select>
      </label>
    </div>

    <p v-if="cargando" class="silencio">Cargando...</p>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Numero</th>
          <th>Cliente</th>
          <th>Estado</th>
          <th>Prometido</th>
          <th class="derecha">Piezas</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="pedido in pedidos" :key="pedido.id">
          <td>
            <NuxtLink :to="`/pedidos/${pedido.id}`">{{ pedido.numero }}</NuxtLink>
          </td>
          <td>{{ pedido.cliente_nombre ?? 'sin cliente' }}</td>
          <td>
            <span class="estado" :class="`estado--${pedido.estado}`">
              {{ pedido.estado }}
            </span>
          </td>
          <td class="numero">{{ formatearFecha(pedido.fecha_prometida) }}</td>
          <td class="numero derecha">
            {{ sumaPiezas(pedido.items) }}
          </td>
        </tr>
        <tr v-if="pedidos.length === 0">
          <td colspan="5" class="tabla__vacia">No hay pedidos con este filtro.</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<script setup lang="ts">
import type { EstadoPedido } from '~/tipos/api'

interface PedidoEnLaLista {
  id: string
  numero: string
  estado: EstadoPedido
  fecha_prometida: string | null
  cliente: { nombre: string } | null
  items: Array<{ cantidad_unidades: number }>
}

const estado = ref('')
const pedidos = ref<PedidoEnLaLista[]>([])
const cargando = ref(true)
const error = ref<string | null>(null)

function sumaPiezas(items: Array<{ cantidad_unidades: number }>): number {
  return items.reduce((total, item) => total + item.cantidad_unidades, 0)
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
  error.value = null

  try {
    const respuesta = await $fetch<{ data: PedidoEnLaLista[] }>('/api/v1/pedidos', {
      query: estado.value === '' ? {} : { estado: estado.value }
    })

    pedidos.value = respuesta.data
  } catch {
    error.value = 'No se pudieron cargar los pedidos.'
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

useHead({ title: 'Pedidos' })
</script>

<style scoped>
.filtros {
  margin-bottom: 1rem;
}

.filtros label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.derecha {
  text-align: right;
}
</style>
