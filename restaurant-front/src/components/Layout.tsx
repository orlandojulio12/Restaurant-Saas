import { useEffect, useState } from 'react'
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
  const [masAbierto, setMasAbierto] = useState(false)

  // Se cierra al navegar: si no, la hoja tapa la pantalla recién abierta.
  useEffect(() => setMasAbierto(false), [pathname])

  const visibles = MENU.filter(
    (e) => puede(e.roles) && (!e.plan || tienePlan(e.plan)),
  )

  // Cuatro en la barra y el resto tras "Más": con cinco no cabe el acceso al
  // resto y se perdían secciones enteras.
  const enBarra = visibles.length <= 5 ? visibles : visibles.slice(0, 4)
  const enMas = visibles.length <= 5 ? [] : visibles.slice(4)

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
            className="min-h-11 w-full rounded-lg px-2 text-left text-sm font-medium
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
          className="min-h-11 rounded-lg px-3 text-sm font-medium text-piedra-500"
        >
          Salir
        </button>
      </header>

      <main className="pb-24 lg:ml-60 lg:pb-0">
        <Outlet />
      </main>

      {/* Barra inferior: al alcance del pulgar, que es como se usa esto de pie
          entre mesas. En la barra solo caben cuatro, así que el resto va tras
          "Más" — antes se recortaba la lista y un administrador en tablet
          simplemente no podía llegar a reportes, finanzas, clientes ni ajustes. */}
      <nav
        className="fixed inset-x-0 bottom-0 z-20 flex border-t border-piedra-200
                   bg-white lg:hidden"
      >
        {enBarra.map((e) => (
          <NavLink
            key={e.a}
            to={e.a}
            className={({ isActive }) =>
              [
                'flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5',
                'text-[11px] font-medium',
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

        {enMas.length > 0 && (
          <button
            onClick={() => setMasAbierto(true)}
            aria-expanded={masAbierto}
            className={`flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5
                        text-[11px] font-medium ${
                          enMas.some((e) => pathname.startsWith(e.a))
                            ? 'text-marca-700'
                            : 'text-piedra-500'
                        }`}
          >
            <span aria-hidden className="text-xl leading-none">
              ⋯
            </span>
            Más
          </button>
        )}
      </nav>

      {masAbierto && (
        <div
          className="fixed inset-0 z-30 flex items-end bg-black/40 lg:hidden"
          onClick={() => setMasAbierto(false)}
        >
          <div
            role="dialog"
            aria-label="Más secciones"
            onClick={(e) => e.stopPropagation()}
            className="w-full rounded-t-2xl bg-white p-4 pb-6"
          >
            <div className="mx-auto mb-4 h-1 w-10 rounded-full bg-piedra-300" aria-hidden />

            <ul className="grid grid-cols-3 gap-2">
              {enMas.map((e) => (
                <li key={e.a}>
                  <NavLink
                    to={e.a}
                    className={({ isActive }) =>
                      [
                        'flex min-h-20 flex-col items-center justify-center gap-1.5 rounded-xl',
                        'px-2 text-center text-xs font-semibold',
                        isActive ? 'bg-marca-50 text-marca-800' : 'bg-piedra-50 text-piedra-700',
                      ].join(' ')
                    }
                  >
                    <span aria-hidden className="text-2xl leading-none">
                      {e.icono}
                    </span>
                    {e.texto}
                  </NavLink>
                </li>
              ))}
            </ul>

            <button
              onClick={() => setMasAbierto(false)}
              className="mt-3 min-h-12 w-full rounded-xl border border-piedra-300
                         font-semibold text-piedra-700"
            >
              Cerrar
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

function etiquetaRol(rol?: Rol) {
  if (!rol) return ''

  return { admin: 'Administrador', waiter: 'Mesero', kitchen: 'Cocina', cashier: 'Caja' }[rol]
}
