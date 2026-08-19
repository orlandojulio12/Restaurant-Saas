import { api } from './client'
import type {
  Mesa,
  Zona,
  Categoria,
  GrupoAdicionales,
  Paginado,
  Producto,
} from './tipos'

/** Menú: categorías, productos y grupos de adicionales. */
export const gestionMenu = {
  categorias: async () => (await api.get<Categoria[]>('/categories')).data,

  crearCategoria: async (datos: Partial<Categoria>) =>
    (await api.post<Categoria>('/categories', datos)).data,

  editarCategoria: async (id: number, datos: Partial<Categoria>) =>
    (await api.put<Categoria>(`/categories/${id}`, datos)).data,

  borrarCategoria: async (id: number) => api.delete(`/categories/${id}`),

  productos: async () => (await api.get<Producto[]>('/products')).data,

  /**
   * Crear y editar comparten forma porque la imagen obliga a multipart, y
   * Laravel no lee multipart en PUT: se envía POST con _method=PUT.
   */
  guardarProducto: async (datos: DatosProducto, id?: number) => {
    const cuerpo = new FormData()

    cuerpo.append('category_id', String(datos.category_id))
    cuerpo.append('name', datos.name)
    cuerpo.append('price', String(datos.price))
    cuerpo.append('cost', String(datos.cost ?? 0))
    cuerpo.append('preparation_time', String(datos.preparation_time ?? 0))
    cuerpo.append('is_available', datos.is_available ? '1' : '0')

    if (datos.description) cuerpo.append('description', datos.description)
    if (datos.imagen) cuerpo.append('image', datos.imagen)

    // Un array vacío también debe viajar: significa "sin grupos".
    for (const grupoId of datos.additional_group_ids ?? []) {
      cuerpo.append('additional_group_ids[]', String(grupoId))
    }
    if ((datos.additional_group_ids ?? []).length === 0) {
      cuerpo.append('additional_group_ids', '')
    }

    if (id) cuerpo.append('_method', 'PUT')

    const url = id ? `/products/${id}` : '/products'

    return (await api.post<Producto>(url, cuerpo)).data
  },

  borrarProducto: async (id: number) => api.delete(`/products/${id}`),

  grupos: async () => (await api.get<GrupoAdicionales[]>('/additional-groups')).data,

  guardarGrupo: async (datos: DatosGrupo, id?: number) => {
    const url = id ? `/additional-groups/${id}` : '/additional-groups'
    const peticion = id ? api.put : api.post

    return (await peticion<GrupoAdicionales>(url, datos)).data
  },

  borrarGrupo: async (id: number) => api.delete(`/additional-groups/${id}`),
}

export type DatosProducto = {
  category_id: number
  name: string
  price: number | string
  cost?: number | string
  description?: string | null
  preparation_time?: number
  is_available: boolean
  additional_group_ids?: number[]
  imagen?: File | null
}

export type DatosGrupo = {
  name: string
  selection_type: 'single' | 'multiple'
  is_required: boolean
  additionals: {
    id?: number
    name: string
    extra_price: number | string
    is_available: boolean
  }[]
}

/** Inventario: ingredientes, movimientos y recetas. */
export type Ingrediente = {
  id: number
  name: string
  unit: string
  stock: string
  min_stock: string
  cost_per_unit: string
  is_active: boolean
  low_stock: boolean
}

export type Movimiento = {
  id: number
  type: 'in' | 'out' | 'adjustment' | 'waste'
  quantity: string
  stock_before: string
  stock_after: string
  unit_cost: string
  reason: string | null
  order_id: number | null
  created_at: string
  ingredient?: { id: number; name: string; unit: string }
  user?: { id: number; name: string } | null
}

export type LineaReceta = {
  ingredient_id: number
  name: string
  unit: string
  quantity: number
  cost_per_unit: number
  line_cost: number
  current_stock: number
}

export type Receta = {
  product_id: number
  product_name: string
  ingredients: LineaReceta[]
  calculated_cost: number
  registered_cost: number
  cost_difference: number
}

export const gestionInventario = {
  ingredientes: async () => (await api.get<Ingrediente[]>('/ingredients')).data,

  guardarIngrediente: async (datos: Record<string, unknown>, id?: number) => {
    const url = id ? `/ingredients/${id}` : '/ingredients'
    const peticion = id ? api.put : api.post

    return (await peticion<Ingrediente>(url, datos)).data
  },

  borrarIngrediente: async (id: number) => api.delete(`/ingredients/${id}`),

  movimiento: async (
    ingredienteId: number,
    datos: {
      type: 'in' | 'out' | 'adjustment' | 'waste'
      quantity?: number
      new_stock?: number
      reason?: string
      unit_cost?: number
    },
  ) => (await api.post(`/ingredients/${ingredienteId}/movement`, datos)).data,

  movimientos: async (params: { ingredient_id?: number; type?: string } = {}) =>
    (await api.get<Paginado<Movimiento>>('/inventory/movements', { params })).data,

  alertas: async () =>
    (await api.get<{ data: Ingrediente[]; count: number }>('/inventory/alerts')).data,

  receta: async (productoId: number) =>
    (await api.get<Receta>(`/products/${productoId}/ingredients`)).data,

  guardarReceta: async (
    productoId: number,
    ingredientes: { ingredient_id: number; quantity: number }[],
  ) => (await api.put<Receta>(`/products/${productoId}/ingredients`, { ingredients: ingredientes })).data,
}

// ── Ajustes del restaurante ────────────────────────────────────────────────

export type Ajustes = {
  restaurant: {
    id: number
    name: string
    slug: string
    phone: string | null
    whatsapp_number: string | null
    address: string | null
    city: string | null
    country: string | null
    logo_url: string | null
    currency: string
    timezone: string
  }
  plan: {
    name: string
    display_name: string
    has_whatsapp: boolean
    has_inventory: boolean
    has_reports: boolean
    has_financials: boolean
  }
  settings: {
    mode: string
    tax_percent: number
    print_kitchen: boolean
    notify_sound: boolean
    qr_confirm: boolean
  }
}

export type Usuario = {
  id: number
  name: string
  email: string
  role: 'admin' | 'waiter' | 'kitchen' | 'cashier'
  is_active: boolean
  last_login_at: string | null
}

export const gestionAjustes = {
  leer: async () => (await api.get<Ajustes>('/settings')).data,

  guardar: async (datos: Record<string, unknown>, logo?: File | null) => {
    if (!logo) {
      return (await api.put<Ajustes>('/settings', datos)).data
    }

    // Con archivo hay que ir por POST + _method: PHP no parsea multipart en PUT.
    const cuerpo = new FormData()
    cuerpo.append('_method', 'PUT')
    cuerpo.append('logo', logo)

    for (const [clave, valor] of Object.entries(datos)) {
      if (valor === null || valor === undefined) continue

      if (clave === 'settings' && typeof valor === 'object') {
        for (const [k, v] of Object.entries(valor as Record<string, unknown>)) {
          cuerpo.append(`settings[${k}]`, typeof v === 'boolean' ? (v ? '1' : '0') : String(v))
        }
        continue
      }

      cuerpo.append(clave, String(valor))
    }

    return (await api.post<Ajustes>('/settings', cuerpo)).data
  },

  zonas: async () => (await api.get<Zona[]>('/zones')).data,

  guardarZona: async (datos: { name: string; sort_order?: number }, id?: number) =>
    id
      ? (await api.put<Zona>(`/zones/${id}`, datos)).data
      : (await api.post<Zona>('/zones', datos)).data,

  borrarZona: async (id: number, forzar = false) =>
    api.delete(`/zones/${id}`, { params: forzar ? { force: true } : {} }),

  mesas: async () => (await api.get<Mesa[]>('/tables')).data,

  guardarMesa: async (
    datos: { number: string; capacity?: number; zone_id?: number | null; status?: string },
    id?: number,
  ) =>
    id
      ? (await api.put<Mesa>(`/tables/${id}`, datos)).data
      : (await api.post<Mesa>('/tables', datos)).data,

  borrarMesa: async (id: number) => api.delete(`/tables/${id}`),

  usuarios: async () => (await api.get<Usuario[]>('/users')).data,

  guardarUsuario: async (datos: Record<string, unknown>, id?: number) =>
    id
      ? (await api.put<Usuario>(`/users/${id}`, datos)).data
      : (await api.post<Usuario>('/users', datos)).data,

  borrarUsuario: async (id: number) => api.delete(`/users/${id}`),
}
