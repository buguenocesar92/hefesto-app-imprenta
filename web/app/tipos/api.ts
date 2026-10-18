/**
 * Tipos que viajan entre la interfaz y la API.
 *
 * ES UN CONTRATO ESCRITO, NO UNA COPIA AUTOMATICA DE LA BASE.
 *
 * Al escribirlo a mano, el nombre del campo queda con un error a la vista y
 * se corrige en los dos lados. Si ademas se derivara del esquema, un cambio
 * de columna se llevaria por delante una pantalla sin avisar.
 *
 * Cuando la API cambia una respuesta, este archivo y el controlador que la
 * produce cambian juntos.
 */

/** Persona que esta operando la imprenta. */
export interface Usuario {
  id: string
  nombre: string
  correo: string
  rol: 'auditor' | 'preparador' | 'supervisor' | 'administrador'
  empresa: string | null
  puede_aprobar_pruebas: boolean
  puede_registrar_produccion: boolean
  puede_anular_pedidos: boolean
  puede_administrar_catalogos: boolean
}

/** Empresa que se ofrece en el selector de acceso. */
export interface EmpresaDelSelector {
  slug: string
  nombre: string
}

/**
 * Estado de un pedido.
 *
 * `esperando_aprobacion` y `aprobado` son estados distintos y no se
 * saltan: esa diferencia es el producto.
 */
export type EstadoPedido =
  | 'borrador'
  | 'esperando_aprobacion'
  | 'aprobado'
  | 'en_produccion'
  | 'parcialmente_entregado'
  | 'entregado'
  | 'anulado'

/** Estado de una prueba (mockup). */
export type EstadoPrueba = 'pendiente' | 'enviada' | 'aprobada' | 'rechazada'

export type TipoPapel = 'offset' | 'coated' | 'opal'

export type UnidadCompraPapel = 'pliego' | 'kilo'

/** Papel del catalogo. */
export interface Papel {
  id: string
  codigo: string
  nombre: string
  tipo: TipoPapel
  gramaje_gm2: number
  plancha: string | null
  unidad_compra: UnidadCompraPapel
  descripcion: string
  merma_configurada: boolean
  precio_referencia_por_kg: number | null
  activo: boolean
}

/** Plancha: la hoja entera con su medida. */
export interface Plancha {
  id: string
  codigo: string
  nombre: string
  medida: string
  area_m2: number
  pliegos_por_paquete: number | null
  activo: boolean
}

/** Desglose de merma de un origen. */
export interface DesgloseDeMerma {
  origen: 'prueba' | 'plancha'
  etiqueta: string
  pliegos: number
  porcentaje: number
  porcentaje_real: number | null
}

/**
 * Desglose de consumo de papel.
 *
 * `pasos` es la cuenta escrita, en el orden en que se hizo. Se muestra en
 * pantalla porque el cliente va a preguntar por que estan esos pliegos, y sin
 * la cuenta la pregunta no tiene respuesta.
 */
export interface DesgloseDeConsumo {
  unidades_pedidas: number
  unidades_por_pliego: number
  orientacion: 'normal' | 'rotada'
  columnas: number
  filas: number
  por_ventana: number
  pliegos_netos: number
  pliegos_de_merma: number
  pliegos_totales: number
  gramos_por_pliego: number
  kilos_requeridos: number
  kilos_por_pliego: number
  mermas: DesgloseDeMerma[]
  pasos: string[]
}

/** Item de un pedido. */
export interface ItemDePedido {
  id: string
  descripcion: string
  cantidad_unidades: number
  medida: string
  n_por_ventana: number
  papel: string | null
  tiene_prueba_aprobada: boolean
  prueba_vencida: boolean
}

/** Pedido completo. */
export interface Pedido {
  id: string
  numero: string
  estado: EstadoPedido
  fecha_prometida: string | null
  descripcion: string | null
  cliente: string | null
  cantidad_pedida: number
  cantidad_entregada: number
  saldo: number
  aprobado_en: string | null
  aprobado_por: string | null
  puede_imprimirse: boolean
  piezas_pendientes: string[]
  items: ItemDePedido[]
  entregas: Entrega[]
}

export interface Entrega {
  id: string
  cantidad_unidades: number
  medio: string
  entregado_en: string | null
}

/** Prueba que espera respuesta. */
export interface PruebaEnEspera {
  id: string
  item: string | null
  version: number
  archivo: string | null
  estado: EstadoPrueba
  enviada_en: string | null
  vencida: boolean
}

/** Orden de produccion registrada. */
export interface OrdenDeProduccion {
  id: string
  pliegos_netos: number
  pliegos_prueba: number
  pliegos_merma: number
  pliegos_totales: number
  producto_bueno: number
  merma_real_porcentaje: number | null
}

/** Resumen de merma por papel: lo previsto contra lo real. */
export interface ResumenDeMerma {
  papel: string | null
  codigo: string | null
  merma_prevista_porcentaje: number | null
  merma_real_porcentaje: number | null
  desvio_puntos: number | null
  requiere_revision: boolean
  pliegos_netos: number
  pliegos_prueba: number
  pliegos_merma: number
  pliegos_totales: number
  producto_bueno: number
  ordenes: number
}

/** Articulo de libreria o papeleria. */
export interface ArticuloDeLibreria {
  id: string
  sku: string
  nombre: string
  categoria: string
  unidad: string
  stock: number
  precio_compra: number | null
  precio_venta: number | null
  activo: boolean
}

/** Error de regla de la imprenta, con su codigo estable. */
export interface ErrorDeRegla {
  mensaje: string
  codigo: string
}

/** Una pagina de resultados. */
export interface Pagina<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}
