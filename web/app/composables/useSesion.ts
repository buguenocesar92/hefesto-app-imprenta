import type { Usuario } from '~/tipos/api'

/**
 * Sesion de la persona que esta operando la imprenta.
 *
 * El rol decide que se ve en el menu, pero NO decide que se puede hacer: la
 * autorizacion real la hace la API. Esto es solo para no mostrar botones
 * que van a ser rechazados: es una cortesia, no una proteccion.
 *
 * En una imprenta hay una consecuencia extra de esto: si la interfaz
 * muestra "Registrar produccion" cuando la prueba no esta aprobada, el
 * operario hace clic y recibe un error. Por eso las pantallas que dependen
 * de esto piden el dato `puede_imprimirse` a la API y no lo deducen del rol.
 */
export function useSesion() {
  const usuario = useState<Usuario | null>('sesion:usuario', () => null)
  const cargado = useState<boolean>('sesion:cargada', () => false)

  async function cargar(): Promise<Usuario | null> {
    if (cargado.value) {
      return usuario.value
    }

    try {
      usuario.value = await $fetch<Usuario>('/api/v1/yo')
    } catch {
      usuario.value = null
    }

    cargado.value = true

    return usuario.value
  }

  async function salir(): Promise<void> {
    await $fetch('/api/v1/salir', { method: 'POST' })

    usuario.value = null
    cargado.value = true

    await navigateTo('/entrar')
  }

  return {
    usuario,
    cargado,
    cargar,
    salir,
    esSupervisor: computed(() => usuario.value?.puede_aprobar_pruebas === true),
    esAdministrador: computed(() => usuario.value?.puede_administrar_catalogos === true),
    puedeProducir: computed(() => usuario.value?.puede_registrar_produccion === true),
    puedeAnular: computed(() => usuario.value?.puede_anular_pedidos === true)
  }
}
