<template>
  <section class="pantalla">
    <div class="encabezado">
      <div>
        <h1>Reportes</h1>
        <p class="encabezado__sub">
          El estado de la imprenta en una pantalla. Esta aplicacion calcula
          papel, no precios: el precio lo carga la imprenta.
        </p>
      </div>
      <span v-if="momento" class="chico silencio">Actualizado: {{ momento }}</span>
    </div>

    <p v-if="error" class="regla regla--error">{{ error }}</p>

    <div v-if="cargando" class="silencio">Cargando...</div>

    <div v-else class="rejilla">
      <div class="tarjeta">
        <h2>Aprobaciones</h2>
        <ul class="chico lista">
          <li>
            <span>Esperando respuesta</span>
            <strong class="numero">{{ aprobaciones.pendientes_de_respuesta }}</strong>
          </li>
          <li>
            <span>Vencidas</span>
            <strong class="numero" :class="{ malo: aprobaciones.vencidas > 0 }">
              {{ aprobaciones.vencidas }}
            </strong>
          </li>
          <li>
            <span>Plazo configurado</span>
            <strong class="numero">{{ aprobaciones.dias_para_vencer }} dias</strong>
          </li>
        </ul>
        <p class="chico silencio">
          Una prueba vencida NO aprueba el pedido. Se llama al cliente.
        </p>
      </div>

      <div class="tarjeta">
        <h2>Piezas en la calle</h2>
        <ul class="chico lista">
          <li>
            <span>Pedidos</span>
            <strong class="numero">{{ en_cola.pedidos }}</strong>
          </li>
          <li>
            <span>Prometidas</span>
            <strong class="numero">{{ en_cola.piezas_prometidas }}</strong>
          </li>
          <li>
            <span>Saldo</span>
            <strong class="numero">{{ en_cola.piezas_saldo }}</strong>
          </li>
        </ul>
      </div>

      <div class="tarjeta">
        <h2>Pedidos por estado</h2>
        <ul class="chico lista">
          <li v-for="(cantidad, estado) in pedidos_por_estado" :key="estado">
            <span class="estado" :class="`estado--${estado}`">{{ estado }}</span>
            <strong class="numero">{{ cantidad }}</strong>
          </li>
          <li v-if="Object.keys(pedidos_por_estado).length === 0" class="silencio">
            Sin pedidos.
          </li>
        </ul>
      </div>
    </div>

    <div v-if="estados_descuadrados.length > 0" class="tarjeta tarjeta--alerta">
      <h2>Pedidos con el estado desalineado</h2>
      <p class="chico">
        El estado guardado no corresponde a la suma de las entregas. El estado
        se deriva de los datos, asi que esto deberia ser imposible; si aparece,
        alguien escribio el estado a mano o hay una carga vieja.
      </p>

      <table class="tabla">
        <thead>
          <tr>
            <th>Pedido</th>
            <th>Estado guardado</th>
            <th>Estado que corresponde</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="fila in estados_descuadrados" :key="fila.pedido">
            <td>{{ fila.pedido }}</td>
            <td>{{ fila.estado_guardado }}</td>
            <td><strong>{{ fila.estado_esperado }}</strong></td>
          </tr>
        </tbody>
      </table>

      <p class="chico silencio">
        Para corregirlo:
        <code>php artisan imprenta:revisar-estados --corregir</code>
      </p>
    </div>

    <div class="tarjeta">
      <h2>Que no calcula esta pantalla</h2>
      <p class="chico">
        No calcula cuanto se cobra ni cuanto se gana. La aplicacion sabe
        cuanto papel se gasta y cuanto se pierde, que es la parte que depende
        de datos y de reglas. El precio depende de la relacion con cada
        cliente y lo carga la imprenta.
      </p>
      <p class="chico silencio">
        Los precios de referencia del papel y de los articulos de libreria
        aparecen como "sin cargar" mientras no se informen: esta aplicacion no
        los inventa.
      </p>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { EstadoPedido } from '~/tipos/api'

interface Reporte {
  pedidos_por_estado: Record<string, number>
  en_cola: {
    pedidos: number
    piezas_prometidas: number
    piezas_entregadas: number
    piezas_saldo: number
  }
  aprobaciones: {
    pendientes_de_respuesta: number
    vencidas: number
    dias_para_vencer: number
  }
  estados_descuadrados: Array<{
    pedido: string
    estado_guardado: string
    estado_esperado: string
  }>
  momento: string
}

/**
 * Reportes de la imprenta.
 *
 * Los estados descuadrados salen a la vista en vez de corregirse solos: un
 * reporte que repara cosas por detras es un reporte que esconde el problema
 * hasta que ya no lo arregla.
 */
const pedidos_por_estado = ref<Record<string, number>>({})
const en_cola = ref({ pedidos: 0, piezas_prometidas: 0, piezas_entregadas: 0, piezas_saldo: 0 })
const aprobaciones = ref({ pendientes_de_respuesta: 0, vencidas: 0, dias_para_vencer: 0 })
const estados_descuadrados = ref<Reporte['estados_descuadrados']>([])
const momento = ref('')
const cargando = ref(true)
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    const respuesta = await $fetch<Reporte>('/api/v1/reportes')

    pedidos_por_estado.value = respuesta.pedidos_por_estado
    en_cola.value = respuesta.en_cola
    aprobaciones.value = respuesta.aprobaciones
    estados_descuadrados.value = respuesta.estados_descuadrados
    momento.value = new Date(respuesta.momento).toLocaleString('es-CL')
  } catch {
    error.value = 'No se pudo cargar el reporte.'
  } finally {
    cargando.value = false
  }
})

useHead({ title: 'Reportes' })
</script>

<style scoped>
.rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(17rem, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.lista {
  list-style: none;
  padding: 0;
  margin: 0 0 0.5rem;
}

.lista li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.6rem;
  padding: 0.25rem 0;
  border-bottom: 1px solid var(--linea);
}

.lista li:last-child {
  border-bottom: none;
}

.malo {
  color: var(--alerta);
}

code {
  background: #eef1f4;
  padding: 0.05rem 0.3rem;
  border-radius: 0.2rem;
  font-size: 0.85em;
}
</style>
