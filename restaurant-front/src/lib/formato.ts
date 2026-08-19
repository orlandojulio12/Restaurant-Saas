import type { EstadoPedido, MetodoPago, TipoPedido } from '../api/tipos'

/**
 * Dinero en pesos colombianos: sin decimales, que nadie cobra centavos.
 * Los importes llegan como string desde PHP (decimal), no como number.
 */
export function dinero(valor: string | number, moneda = 'COP'): string {
  const numero = typeof valor === 'string' ? parseFloat(valor) : valor

  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: moneda,
    maximumFractionDigits: 0,
  }).format(Number.isFinite(numero) ? numero : 0)
}

/** Minutos transcurridos en algo legible de un vistazo: "18 min", "1 h 05". */
export function transcurrido(minutos: number | null | undefined): string {
  if (minutos === null || minutos === undefined) return '—'
  if (minutos < 60) return `${minutos} min`

  const horas = Math.floor(minutos / 60)
  const resto = minutos % 60

  return `${horas} h ${String(resto).padStart(2, '0')}`
}

export function minutosDesde(iso: string | null): number | null {
  if (!iso) return null

  const diferencia = Date.now() - new Date(iso).getTime()

  return Math.max(0, Math.floor(diferencia / 60000))
}

export const ESTADOS: Record<EstadoPedido, { etiqueta: string; clase: string; punto: string }> = {
  pending: {
    etiqueta: 'Pendiente',
    clase: 'bg-pendiente-suave text-pendiente',
    punto: 'bg-pendiente',
  },
  preparing: {
    etiqueta: 'En preparación',
    clase: 'bg-preparando-suave text-preparando',
    punto: 'bg-preparando',
  },
  ready: {
    etiqueta: 'Listo',
    clase: 'bg-listo-suave text-listo',
    punto: 'bg-listo',
  },
  on_the_way: {
    etiqueta: 'En camino',
    clase: 'bg-camino-suave text-camino',
    punto: 'bg-camino',
  },
  delivered: {
    etiqueta: 'Entregado',
    clase: 'bg-entregado-suave text-entregado',
    punto: 'bg-entregado',
  },
  closed: {
    etiqueta: 'Cerrado',
    clase: 'bg-piedra-200 text-piedra-700',
    punto: 'bg-piedra-500',
  },
  cancelled: {
    etiqueta: 'Cancelado',
    clase: 'bg-cancelado-suave text-cancelado',
    punto: 'bg-cancelado',
  },
}

export const TIPOS: Record<TipoPedido, string> = {
  dine_in: 'En mesa',
  delivery: 'Domicilio',
  counter: 'Para llevar',
}

export const METODOS_PAGO: Record<MetodoPago, string> = {
  cash: 'Efectivo',
  card: 'Tarjeta',
  nequi: 'Nequi',
  daviplata: 'Daviplata',
  bancolombia: 'Bancolombia',
  transfer: 'Transferencia',
  other: 'Otro',
}

/**
 * Transiciones válidas, copiadas de OrderController::TRANSITIONS.
 *
 * El backend las valida igualmente; aquí sirven para no ofrecer botones que
 * van a devolver 422.
 */
export const SIGUIENTES: Record<EstadoPedido, EstadoPedido[]> = {
  pending: ['preparing', 'cancelled'],
  preparing: ['ready', 'cancelled'],
  ready: ['delivered', 'on_the_way'],
  on_the_way: ['delivered'],
  delivered: ['closed'],
  closed: [],
  cancelled: [],
}

/**
 * Zona horaria del restaurante.
 *
 * El backend calcula los días en la zona del local, no en UTC ni en la del
 * navegador. Si el front usara la del navegador, un dueño mirando desde otro
 * país vería las ventas de la noche caer en el día siguiente. Se fija al
 * abrir sesión.
 */
let zonaRestaurante: string | undefined

export function configurarZona(tz: string | undefined) {
  zonaRestaurante = tz || undefined
}

/** Opciones de formato con la zona del restaurante ya aplicada. */
function conZona(opciones: Intl.DateTimeFormatOptions): Intl.DateTimeFormatOptions {
  return zonaRestaurante ? { ...opciones, timeZone: zonaRestaurante } : opciones
}

/** Fecha en la zona del restaurante, para comparar días. */
function enZona(iso: string): Date {
  const d = new Date(iso)

  if (!zonaRestaurante) return d

  return new Date(d.toLocaleString('en-US', { timeZone: zonaRestaurante }))
}

/**
 * Etiqueta del día para agrupar listados: "Hoy", "Ayer" o la fecha escrita.
 *
 * Sin esto, un pedido de ayer se distingue de uno de hoy solo por un "26 h 15"
 * que nadie lee como "ayer".
 */
export function etiquetaDia(iso: string): string {
  const fecha = enZona(iso)
  const hoy = enZona(new Date().toISOString())
  const ayer = new Date(hoy)
  ayer.setDate(ayer.getDate() - 1)

  const mismoDia = (a: Date, b: Date) => a.toDateString() === b.toDateString()

  if (mismoDia(fecha, hoy)) return 'Hoy'
  if (mismoDia(fecha, ayer)) return 'Ayer'

  return new Intl.DateTimeFormat(
    'es-CO',
    conZona({
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      ...(fecha.getFullYear() !== hoy.getFullYear() ? { year: 'numeric' } : {}),
    }),
  ).format(new Date(iso))
}

/** Hora del reloj, que es como la gente ubica un pedido pasado. */
export function hora(iso: string): string {
  return new Intl.DateTimeFormat(
    'es-CO',
    conZona({ hour: 'numeric', minute: '2-digit', hour12: true }),
  ).format(new Date(iso))
}

/** Clave de agrupación por día local. */
export function diaDe(iso: string): string {
  const d = enZona(iso)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
