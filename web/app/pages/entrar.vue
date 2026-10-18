<template>
  <section class="entrar">
    <h1>Gestion de Imprenta</h1>

    <p class="entrar__intro">
      Impresion, libreria y papeleria. Antes de imprimir, el cliente tiene que
      ver y aprobar la prueba: eso queda registrado, con nombre y fecha.
    </p>

    <p v-if="error" class="regla regla--error">{{ error }}</p>

    <div class="tarjeta">
      <h2>1. Elija la empresa</h2>

      <p class="chico silencio">
        La lista viene de la vista publica de empresas del servidor. Solo
        muestra el nombre y el identificador: ni razon social ni nada mas.
      </p>

      <p v-if="cargandoEmpresas" class="silencio">Cargando empresas...</p>

      <p v-else-if="empresas.length === 0" class="regla regla--pendiente">
        No hay empresas activas cargadas. Pida al administrador que registre
        una antes de entrar.
      </p>

      <ul v-else class="entrar__lista">
        <li v-for="empresa in empresas" :key="empresa.slug">
          <button
            type="button"
            class="boton boton--principal"
            :disabled="empresa.slug === elegida"
            @click="elegir(empresa)"
          >
            {{ empresa.nombre }}
          </button>
        </li>
      </ul>
    </div>

    <div class="tarjeta">
      <h2>2. Entre con su cuenta</h2>

      <p v-if="elegida" class="chico silencio">
        Entrando a <strong>{{ nombreElegida }}</strong>.
      </p>
      <p v-else class="chico silencio">
        Elija una empresa para continuar. Si su cuenta esta en mas de una,
        esto decide a cual entra.
      </p>

      <a
        class="boton boton--principal"
        :class="{ 'boton--inactivo': !elegida }"
        :href="urlDeAcceso"
      >
        Entrar con la cuenta corporativa
      </a>

      <p class="entrar__nota">
        El acceso se hace en el servidor de identidad de Hefesto Studio. Esta
        aplicacion no guarda contrasenas. Si no puede entrar, avisa al
        administrador: el rol que decide que puede hacer se configura alla.
      </p>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { EmpresaDelSelector } from '~/tipos/api'

/**
 * Pantalla de acceso.
 *
 * POR QUE HAY UN SELECTOR DE EMPRESA Y NO UN USUARIO Y UNA CLAVE
 *
 * El correo es unico POR EMPRESA en esta aplicacion: la misma persona puede
 * ser administradora en una imprenta y preparadora en otra, con permisos
 * distintos. Si el acceso no preguntara a cual se entra, habria que
 * adivinarla, y adivinar a que empresa entra alguien es exactamente el error
 * que el aislamiento entre imprentas evita en todos los demas casos.
 *
 * DE DONDE SALE LA LISTA
 *
 * De `GET /api/v1/empresas`, que en la API lee la vista
 * `vw_impresas_publicas`: tres columnas (slug, nombre, activo) y solo de
 * empresas activas. La vista existe porque la tabla de empresas no lleva
 * Row Level Security: se consulta antes de que exista sesion y por lo tanto
 * antes de que exista `app.tenant_id`.
 *
 * ESTA CONSULTA SE USA DE VERDAD
 *
 * No es adorno para decir que hay una vista: es la unica fuente de esta
 * pantalla. Si la API deja de devolverla, esta pagina avisa que no hay
 * empresas y no deja entrar a ciegas.
 */
const empresas = ref<EmpresaDelSelector[]>([])
const cargandoEmpresas = ref(true)
const elegida = ref<string>('')

const error = computed(() => {
  const valor = useRoute().query.error

  return typeof valor === 'string' ? valor : null
})

const nombreElegida = computed(
  () => empresas.value.find((empresa) => empresa.slug === elegida.value)?.nombre ?? ''
)

const urlDeAcceso = computed(() =>
  elegida.value === ''
    ? '#'
    : `/api/v1/acceso?empresa=${encodeURIComponent(elegida.value)}`
)

function elegir(empresa: EmpresaDelSelector): void {
  elegida.value = empresa.slug
}

onMounted(async () => {
  try {
    const respuesta = await $fetch<{ empresas: EmpresaDelSelector[] }>('/api/v1/empresas')
    empresas.value = respuesta.empresas
  } catch {
    empresas.value = []
  } finally {
    cargandoEmpresas.value = false
  }
})

useHead({ title: 'Entrar' })
</script>

<style scoped>
.entrar {
  max-width: 38rem;
  margin: 2.5rem auto;
}

.entrar__intro {
  color: var(--tinta-suave);
  line-height: 1.6;
  margin-bottom: 1.25rem;
}

.entrar__lista {
  list-style: none;
  padding: 0;
  margin: 0.75rem 0 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.entrar__nota {
  margin-top: 1.25rem;
  font-size: 0.82rem;
  color: var(--tinta-suave);
}

.boton--inactivo {
  opacity: 0.5;
  pointer-events: none;
}
</style>
