import { api } from './client'

/** Un día de la serie de ventas. */
export type DiaVentas = {
  date: string
  orders: number
  sales: number
  cost: number
  gross_profit: number
  avg_ticket: number
}

export type Totales = {
  orders: number
  sales: number
  cost: number
  gross_profit: number
  avg_ticket: number
  margin_percent?: number
}

export type ReporteDiario = {
  from: string
  to: string
  data: DiaVentas[]
  totals: Totales
}

export type ProductoVendido = {
  product_id: number | null
  product_name: string
  quantity: number
  revenue: number
  cost: number
  gross_profit: number
}

export type Resumen = {
  from: string
  to: string
  totals: Totales
  by_type: { type: 'dine_in' | 'delivery' | 'counter'; count: number; sales: number }[]
  by_payment_method: { method: string; count: number; amount: number }[]
}

export type Dashboard = {
  today: {
    date: string
    sales: number
    orders: number
    open_orders: number
    avg_ticket: number
    vs_yesterday: { sales: number; change_percent: number | null }
  }
  month: {
    sales: number
    vs_last_month: { sales: number; change_percent: number | null }
    breakeven: number
    gap_to_breakeven: number
    above_breakeven: boolean
  }
  top_products: { product_id: number; product_name: string; total_qty: string; total_revenue: string }[]
  top_payment_method: string | null
}

export type PuntoEquilibrio = {
  monthly_fixed_costs: number
  avg_ticket: number
  avg_margin_percent: number
  breakeven_revenue: number
  breakeven_customers_per_month: number
  breakeven_customers_per_day: number
  current_monthly_revenue: number
  gap: number
}

export type Proyeccion = {
  current_month_sales: number
  projected_month_sales: number
  target_monthly_revenue: number
  breakeven: number
  on_track: boolean
  daily_needed_to_reach_target: number
}

export type CostoFijo = {
  id: number
  name: string
  amount: string
  category: string
  frequency: string
  is_active: boolean
}

type Rango = { from?: string; to?: string }

export const reportesApi = {
  diario: async (rango: Rango = {}) =>
    (await api.get<ReporteDiario>('/reports/daily', { params: rango })).data,

  productos: async (rango: Rango & { limit?: number } = {}) =>
    (await api.get<{ data: ProductoVendido[] }>('/reports/products', { params: rango })).data,

  resumen: async (rango: Rango = {}) =>
    (await api.get<Resumen>('/reports/summary', { params: rango })).data,
}

export const finanzasApi = {
  dashboard: async () => (await api.get<Dashboard>('/financial/dashboard')).data,

  puntoEquilibrio: async () => (await api.get<PuntoEquilibrio>('/financial/breakeven')).data,

  proyeccion: async () => (await api.get<Proyeccion>('/financial/projection')).data,

  costosFijos: async () =>
    (await api.get<{ data: CostoFijo[]; monthly_total: number }>('/fixed-costs')).data,

  guardarMetas: async (metas: {
    target_monthly_revenue?: number
    target_profit_margin?: number
    avg_ticket_goal?: number
  }) => (await api.put('/financial/goals', metas)).data,
}

/** Rangos que de verdad se piden, en la zona del restaurante. */
export function rangoPreset(preset: 'hoy' | '7d' | '30d' | 'mes'): { from: string; to: string } {
  const hoy = new Date()
  const iso = (d: Date) => d.toISOString().slice(0, 10)

  if (preset === 'hoy') return { from: iso(hoy), to: iso(hoy) }

  if (preset === 'mes') {
    const inicio = new Date(hoy.getFullYear(), hoy.getMonth(), 1)
    return { from: iso(inicio), to: iso(hoy) }
  }

  const dias = preset === '7d' ? 6 : 29
  const desde = new Date(hoy)
  desde.setDate(desde.getDate() - dias)

  return { from: iso(desde), to: iso(hoy) }
}
