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
    clase: 'bg-[var(--color-pendiente-suave)] text-[var(--color-pendiente)]',
    punto: 'bg-[var(--color-pendiente)]',
  },
  preparing: {
    etiqueta: 'En preparación',
    clase: 'bg-[var(--color-preparando-suave)] text-[var(--color-preparando)]',
    punto: 'bg-[var(--color-preparando)]',
  },
  ready: {
    etiqueta: 'Listo',
    clase: 'bg-[var(--color-listo-suave)] text-[var(--color-listo)]',
    punto: 'bg-[var(--color-listo)]',
  },
  on_the_way: {
    etiqueta: 'En camino',
    clase: 'bg-[var(--color-camino-suave)] text-[var(--color-camino)]',
    punto: 'bg-[var(--color-camino)]',
  },
  delivered: {
    etiqueta: 'Entregado',
    clase: 'bg-[var(--color-entregado-suave)] text-[var(--color-entregado)]',
    punto: 'bg-[var(--color-entregado)]',
  },
  closed: {
    etiqueta: 'Cerrado',
    clase: 'bg-piedra-200 text-piedra-700',
    punto: 'bg-piedra-500',
  },
  cancelled: {
    etiqueta: 'Cancelado',
    clase: 'bg-[var(--color-cancelado-suave)] text-[var(--color-cancelado)]',
    punto: 'bg-[var(--color-cancelado)]',
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
