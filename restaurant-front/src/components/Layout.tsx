import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useSesion } from '../features/auth/SesionContext'
import type { Rol } from '../api/tipos'

type Modulo = 'whatsapp' | 'inventory' | 'reports' | 'financials'

type Entrada = {
  a: string
  texto: string
  icono: string
  roles: Rol[]
  plan?: Modulo
  /** En móvil solo caben unas pocas; el resto va al menú "Más". */
  principal?: boolean
}

/*
  Cada rol ve solo lo suyo. No es cosmético: un panel con ocho opciones
  irrelevantes obliga a leerlas todas para encontrar la que sirve, y en cocina
  o en sala eso se paga en segundos por pedido.
*/
const MENU: Entrada[] = [
  { a: '/mesas', texto: 'Mesas', icono: '🪑', roles: ['admin', 'waiter', 'cashier'], principal: true },
  { a: '/pedidos', texto: 'Pedidos', icono: '🧾', roles: ['admin', 'waiter', 'cashier'], principal: true },
  { a: '/cocina', texto: 'Cocina', icono: '👨‍🍳', roles: ['admin', 'kitchen'], principal: true },
  { a: '/menu', texto: 'Menú', icono: '📖', roles: ['admin'], principal: true },
  { a: '/inventario', texto: 'Inventario', icono: '📦', roles: ['admin'], plan: 'inventory' },
  { a: '/reportes', texto: 'Reportes', icono: '📊', roles: ['admin'], plan: 'reports' },
  { a: '/finanzas', texto: 'Finanzas', icono: '💰', roles: ['admin'], plan: 'financials' },
  { a: '/clientes', texto: 'Clientes', icono: '👥', roles: ['admin', 'cashier'] },
  { a: '/ajustes', texto: 'Ajustes', icono: '⚙️', roles: ['admin'] },
]

export default function Layout() {
  const { sesion, salir, puede, tienePlan } = useSesion()
  const { pathname } = useLocation()

  const visibles = MENU.filter(
    (e) => puede(e.roles) && (!e.plan || tienePlan(e.plan)),
  )

  // La cocina va a pantalla completa: es una pantalla colgada que se mira de
  // lejos, y cualquier barra le roba sitio a lo único que importa.
  const sinMarco = pathname.startsWith('/cocina')

  if (sinMarco) {
    return <Outlet />
  }

  return (
    <div className="min-h-dvh bg-piedra-50">
      {/* Lateral en escritorio */}
      <aside
        className="fixed inset-y-0 left-0 hidden w-60 flex-col border-r border-piedra-200
                   bg-white lg:flex"
      >
        <div className="flex items-center gap-2.5 px-5 py-5">
          <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-marca-600 text-lg">
            🍽️
          </span>
          <span className="truncate font-bold text-piedra-900">
            {sesion?.restaurante.name}
          </span>
        </div>

        <nav className="flex-1 space-y-0.5 px-3">
          {visibles.map((e) => (
            <NavLink
              key={e.a}
              to={e.a}
              className={({ isActive }) =>
                [
                  'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                  isActive
                    ? 'bg-marca-50 text-marca-800'
                    : 'text-piedra-600 hover:bg-piedra-100 hover:text-piedra-900',
                ].join(' ')
              }
            >
              <span aria-hidden className="text-base">
                {e.icono}
              </span>
              {e.texto}
            </NavLink>
          ))}
        </nav>

        <div className="border-t border-piedra-200 p-3">
          <div className="px-2 pb-2">
            <p className="truncate text-sm font-semibold text-piedra-800">
              {sesion?.usuario.name}
            </p>
            <p className="text-xs text-piedra-500">{etiquetaRol(sesion?.usuario.role)}</p>
          </div>
          <button
            onClick={salir}
            className="w-full rounded-lg px-2 py-2 text-left text-sm font-medium
                       text-piedra-600 transition hover:bg-piedra-100"
          >
            Cerrar sesión
          </button>
        </div>
      </aside>

      {/* Cabecera en móvil */}
      <header
        className="sticky top-0 z-10 flex items-center justify-between border-b
                   border-piedra-200 bg-white px-4 py-3 lg:hidden"
      >
        <span className="truncate font-bold text-piedra-900">
          {sesion?.restaurante.name}
        </span>
        <button
          onClick={salir}
          className="rounded-lg px-2 py-1 text-sm font-medium text-piedra-500"
        >
          Salir
        </button>
      </header>

      <main className="pb-24 lg:ml-60 lg:pb-0">
        <Outlet />
      </main>

      {/* Barra inferior en móvil: al alcance del pulgar, que es como se usa
          esto de pie entre mesas. */}
      <nav
        className="fixed inset-x-0 bottom-0 z-10 flex border-t border-piedra-200
                   bg-white lg:hidden"
      >
        {visibles.slice(0, 5).map((e) => (
          <NavLink
            key={e.a}
            to={e.a}
            className={({ isActive }) =>
              [
                'flex flex-1 flex-col items-center gap-0.5 py-2.5 text-[11px] font-medium',
                isActive ? 'text-marca-700' : 'text-piedra-500',
              ].join(' ')
            }
          >
            <span aria-hidden className="text-xl leading-none">
              {e.icono}
            </span>
            {e.texto}
          </NavLink>
        ))}
      </nav>
    </div>
  )
}

function etiquetaRol(rol?: Rol) {
  if (!rol) return ''

  return { admin: 'Administrador', waiter: 'Mesero', kitchen: 'Cocina', cashier: 'Caja' }[rol]
}
