import { api } from './client'
import type { Categoria, EstadoPedido, Mesa, Paginado, Pedido, Producto, TipoPedido } from './tipos'

/** Una línea del carrito, antes de convertirse en pedido. */
export type LineaCarrito = {
  /** Identifica la línea en el carrito; el mismo producto puede ir dos veces
   *  con adicionales distintos. */
  clave: string
  producto: Producto
  cantidad: number
  notas: string
  adicionales: { id: number; name: string; extra_price: number }[]
}

export type NuevoPedido = {
  type: TipoPedido
  table_id?: number | null
  customer_id?: number | null
  delivery_address?: string | null
  notes?: string | null
  items: {
    product_id: number
    quantity: number
    notes?: string | null
    additionals?: number[]
  }[]
}

export const menuApi = {
  categorias: async () =>
    (await api.get<Categoria[]>('/categories', { params: { only_active: true } })).data,

  productos: async () => (await api.get<Producto[]>('/products')).data,

  mesas: async () => (await api.get<Mesa[]>('/tables')).data,
}

export const pedidosApi = {
  crear: async (pedido: NuevoPedido) => (await api.post<Pedido>('/orders', pedido)).data,

  ver: async (id: number) => (await api.get<Pedido>(`/orders/${id}`)).data,

  listar: async (params: { status?: EstadoPedido; per_page?: number } = {}) =>
    (await api.get<Paginado<Pedido>>('/orders', { params })).data,

  cambiarEstado: async (id: number, status: EstadoPedido) =>
    (await api.patch<Pedido>(`/orders/${id}/status`, { status })).data,
}

/** Precio de una línea: el producto más sus extras, por la cantidad. */
export function precioLinea(linea: LineaCarrito): number {
  const extras = linea.adicionales.reduce((suma, a) => suma + Number(a.extra_price), 0)

  return (Number(linea.producto.price) + extras) * linea.cantidad
}

export function totalCarrito(carrito: LineaCarrito[]): number {
  return carrito.reduce((suma, linea) => suma + precioLinea(linea), 0)
}

/**
 * Dos líneas del mismo producto se funden solo si llevan exactamente los
 * mismos adicionales y la misma nota; si no, son cosas distintas para cocina.
 */
export function claveDeLinea(productoId: number, adicionales: number[], notas: string): string {
  return [productoId, [...adicionales].sort((a, b) => a - b).join('-'), notas.trim()].join('|')
}
