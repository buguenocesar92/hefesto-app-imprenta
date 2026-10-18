<template>
  <section class="pantalla">
    <div class="encabezado">
      <div>
        <h1>Panorama del taller</h1>
        <p class="encabezado__sub">
          Lo que hay que mirar primero: que se puede imprimir y que falta por
          entregar.
        </p>
      </div>
    </div>

    <p v-if="cargando" class="silencio">Cargando...</p>

    <div v-else>
      <p v-if="aprobaciones.vencidas > 0" class="regla regla--error">
        Hay {{ aprobaciones.vencidas }} prueba(s) esperando respuesta hace mas
        de {{ aprobaciones.dias_para_vencer }} dias. Un pedido con la prueba
        vencida NO se imprime: hay que llamar al cliente.
      </p>

      <p v-if="aprobaciones.pendientes_de_respuesta > 0" class="regla regla--pendiente">
        {{ aprobaciones.pendientes_de_respuesta }} prueba(s) esperando respuesta
        del cliente.
      </p>

      <p v-if="descuadrados.length > 0" class="regla regla--error">
        Hay {{ descuadrados.length }} pedido(s) cuyo estado no cuadra con sus
        entregas. Revise con: <code>php artisan imprenta:revisar-estados</code>.
      </p>

      <div class="rejilla">
        <div class="tarjeta">
          <h2>En cola</h2>
          <p class="cifra numero">{{ en_cola.pedidos }}</p>
          <p class="chico silencio">pedidos aprobados o en produccion</p>

          <ul class="chico lista-cifras">
            <li><span>Piezas prometidas</span> <strong class="numero">{{ en_cola.piezas_prometidas }}</strong></li>
            <li><span>Ya entregadas</span> <strong class="numero">{{ en_cola.piezas_entregadas }}</strong></li>
            <li><span>Saldo</span> <strong class="numero">{{ en_cola.piezas_saldo }}</strong></li>
          </ul>
        </div>

        <div class="tarjeta">
          <h2>Pedidos por estado</h2>
          <ul class="chico lista-estados">
            <li v-for="(cantidad, estado) in pedidos_por_estado" :key="estado">
              <span class="estado" :class="`estado--${estado}`">{{ estado }}</span>
              <span class="numero">{{ cantidad }}</span>
            </li>
            <li v-if="Object.keys(pedidos_por_estado).length === 0" class="silencio">
              No hay pedidos.
            </li>
          </ul>
        </div>
      </div>

      <div class="tarjeta">
        <h2>Lo que vence pronto</h2>

        <table class="tabla">
          <thead>
            <tr>
              <th>Pedido</th>
              <th>Cliente</th>
              <th>Fecha prometida</th>
              <th class="derecha">Saldo</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="pedido in proximo_vencimiento" :key="pedido.numero">
              <td>
                <NuxtLink :to="`/pedidos/${pedido.id}`">{{ pedido.numero }}</NuxtLink>
              </td>
              <td>{{ pedido.cliente ?? 'sin cliente' }}</td>
              <td class="numero">{{ formatearFecha(pedido.fecha_prometida) }}</td>
              <td class="numero derecha">{{ pedido.saldo }}</td>
            </tr>
            <tr v-if="proximo_vencimiento.length === 0">
              <td colspan="4" class="tabla__vacia">No hay pedidos con saldo pendiente.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
/**
 * Pantalla de inicio: el resumen del dia.
 *
 * Las tres cosas que aparecen arriba son las tres preguntas del taller:
 * que se puede imprimir, que se le entrego al cliente y que falta.
 *
 * NO CALCULA PRECIOS. La aplicacion calcula papel, no plata: los precios los
 * carga la imprenta y un numero inventado en esta pantalla haria mas dano que
 * uno que falta.
 */
interface Panorama {
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
  proximo_vencimiento: Array<{
    numero: string
    cliente: string | null
    fecha_prometida: string | null
    saldo: number
  }>
  estados_descuadrados: Array<{
    pedido: string
    estado_guardado: string
    estado_esperado: string
  }>
}

const cargando = ref(true)
const datos = ref<Panorama | null>(null)

const pedidos_por_estado = computed(() => datos.value?.pedidos_por_estado ?? {})
const en_cola = computed(() => datos.value?.en_cola ?? {
  pedidos: 0,
  piezas_prometidas: 0,
  piezas_entregadas: 0,
  piezas_saldo: 0
})
const aprobaciones = computed(() => datos.value?.aprobaciones ?? {
  pendientes_de_respuesta: 0,
  vencidas: 0,
  dias_para_vencer: 0
})
const proximo_vencimiento = computed(() => datos.value?.proximo_vencimiento ?? [])
const descuadrados = computed(() => datos.value?.estados_descuadrados ?? [])

function formatearFecha(valor: string | null): string {
  if (valor === null) {
    return 'sin fecha'
  }

  const fecha = new Date(valor)

  return Number.isNaN(fecha.getTime())
    ? valor
    : fecha.toLocaleDateString('es-CL')
}

onMounted(async () => {
  try {
    datos.value = await $fetch<Panorama>('/api/v1/reportes')
  } finally {
    cargando.value = false
  }
})

useHead({ title: 'Inicio' })
</script>

<style scoped>
.rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.cifra {
  font-size: 2.2rem;
  font-weight: 700;
  margin: 0;
  line-height: 1.1;
}

.lista-cifras,
.lista-estados {
  list-style: none;
  padding: 0;
  margin: 0.6rem 0 0;
}

.lista-cifras li,
.lista-estados li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.6rem;
  padding: 0.22rem 0;
  border-bottom: 1px solid var(--linea);
}

.lista-cifras li:last-child,
.lista-estados li:last-child {
  border-bottom: none;
}

.derecha {
  text-align: right;
}

code {
  background: #eef1f4;
  padding: 0.05rem 0.3rem;
  border-radius: 0.2rem;
  font-size: 0.85em;
}
</style>
