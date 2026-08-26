/** Tipos que refleja la API. Espejo de los Resources y enums del backend. */

export type Rol = 'admin' | 'waiter' | 'kitchen' | 'cashier'

export type EstadoPedido =
  /** Lo armó el comensal desde el QR y espera que el mesero lo confirme. */
  | 'proposed'
  | 'pending'
  | 'preparing'
  | 'ready'
  | 'on_the_way'
  | 'delivered'
  | 'closed'
  | 'cancelled'

export type TipoPedido =
  | 'dine_in'
  | 'delivery'
  | 'counter'
  /**
   * Lo que la mesa se lleva. Conserva su `table_id` y entra en la misma cuenta
   * que el resto: no es una venta de mostrador, solo cambia cómo se empaca.
   */
  | 'takeaway'

/** Lo único que un comensal puede elegir desde el QR de su mesa. */
export type TipoPedidoQr = Extract<TipoPedido, 'dine_in' | 'takeaway'>

export type EstadoMesa = 'available' | 'occupied' | 'reserved' | 'disabled'

export type MetodoPago =
  | 'cash'
  | 'card'
  | 'nequi'
  | 'daviplata'
  | 'bancolombia'
  | 'transfer'
  | 'other'

export type Plan = {
  name: string
  display_name: string
  has_whatsapp: boolean
  has_inventory: boolean
  has_reports: boolean
  has_financials: boolean
}

export type Usuario = {
  id: number
  name: string
  email: string
  role: Rol
  avatar_url: string | null
  last_login_at: string | null
}

export type Restaurante = {
  id: number
  name: string
  slug: string
  currency: string
  timezone: string
  logo_url: string | null
  /** Hex del que la carta pública deriva su paleta. Null = paleta por defecto. */
  brand_color: string | null
  cover_url: string | null
  plan: Plan
}

export type Sesion = {
  token: string
  user: Usuario
  restaurant: Restaurante
}

export type Categoria = {
  id: number
  name: string
  description: string | null
  image_url: string | null
  sort_order: number
  is_active: boolean
  products_count?: number
}

export type Adicional = {
  id: number
  name: string
  extra_price: number
  is_available: boolean
}

export type GrupoAdicionales = {
  id: number
  name: string
  selection_type: 'single' | 'multiple'
  is_required: boolean
  additionals: Adicional[]
}

export type Producto = {
  id: number
  category_id: number
  name: string
  description: string | null
  image_url: string | null
  price: string
  cost: string
  preparation_time: number
  is_available: boolean
  sort_order: number
  additional_groups?: GrupoAdicionales[]
}

export type Zona = {
  id: number
  name: string
  sort_order: number
  tables_count?: number
}

export type Mesa = {
  id: number
  number: string
  capacity: number
  qr_code: string
  status: EstadoMesa
  /** Solo viene si el backend cargó la relación. */
  zone?: { id: number; name: string } | null
  /**
   * El pedido de la mesa que reclama atención: una propuesta sin confirmar si
   * la hay, y si no el más viejo. No es "el" pedido de la mesa — con el QR
   * cada comensal abre el suyo, así que para el dinero está `active_total`.
   */
  active_order?: {
    id: number
    status: EstadoPedido
    total: string
    items_count: number
    elapsed_min: number | null
  } | null
  /** Cuántos pedidos abiertos tiene la mesa en total. */
  active_orders_count?: number
  /** Lo que debe la mesa entera, sumando todos sus pedidos abiertos. */
  active_total?: string
}

export type AdicionalDeLinea = {
  id: number
  additional_id: number
  additional_name: string
  extra_price: string
}

export type LineaPedido = {
  id: number
  product_id: number
  product_name: string
  unit_price: string
  quantity: number
  subtotal: string
  notes: string | null
  status: 'pending' | 'preparing' | 'ready' | 'delivered' | 'cancelled'
  additionals?: AdicionalDeLinea[]
}

export type Pedido = {
  id: number
  restaurant_id: number
  type: TipoPedido
  status: EstadoPedido
  delivery_address: string | null
  delivery_notes: string | null
  notes: string | null
  /** Quién pidió. Solo lo traen los pedidos que entran por el QR. */
  diner_name: string | null
  subtotal: string
  tax_amount: string
  discount_amount: string
  total: string
  created_at: string
  confirmed_at: string | null
  preparing_at: string | null
  ready_at: string | null
  delivered_at: string | null
  closed_at: string | null
  items: LineaPedido[]
  /* Estas relaciones solo llegan si el endpoint las cargó. */
  table?: { id: number; number: string; zone: string | null } | null
  customer?: { id: number; name: string | null; phone: string | null } | null
  user?: { id: number; name: string; role: Rol } | null
  payment?: { id: number; method: MetodoPago; amount: string; status: string } | null
}

/**
 * Paginación de Laravel. Hay dos formas según cómo responda el endpoint:
 * los que devuelven un Resource envuelven los contadores en `meta`, y los que
 * paginan el modelo directo los ponen en la raíz.
 */
export type Contadores = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

/** Colección de Resources: /orders. */
export type PaginadoResource<T> = {
  data: T[]
  meta: Contadores
}

/** Paginación directa del modelo: /inventory/movements. */
export type Paginado<T> = { data: T[] } & Contadores
