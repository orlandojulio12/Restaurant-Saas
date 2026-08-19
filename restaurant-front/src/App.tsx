import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import type { ReactNode } from 'react'
import Layout from './components/Layout'
import Entrar from './features/auth/Entrar'
import { useSesion } from './features/auth/SesionContext'
import Cocina from './features/cocina/Cocina'
import Inventario from './features/inventario/Inventario'
import Menu from './features/menu/Menu'
import Mesas from './features/mesas/Mesas'
import Finanzas from './features/finanzas/Finanzas'
import Ajustes from './features/ajustes/Ajustes'
import Clientes from './features/clientes/Clientes'
import MenuPublico from './features/publico/MenuPublico'
import Cobrar from './features/pedidos/Cobrar'
import NuevoPedido from './features/pedidos/NuevoPedido'
import Pedidos from './features/pedidos/Pedidos'
import Reportes from './features/reportes/Reportes'
import PedidoDetalle from './features/pedidos/PedidoDetalle'
import type { Rol } from './api/tipos'

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        {/* Pública: la abre el comensal desde el QR, sin sesión ni panel. */}
        <Route path="/m/:slug" element={<MenuPublico />} />

        <Route path="/entrar" element={<SoloInvitados><Entrar /></SoloInvitados>} />

        <Route element={<Protegida><Layout /></Protegida>}>
          <Route index element={<Inicio />} />
          <Route path="/mesas" element={<Mesas />} />
          <Route path="/pedidos" element={<Pedidos />} />
          <Route path="/pedidos/nuevo" element={<NuevoPedido />} />
          <Route path="/pedidos/:id" element={<PedidoDetalle />} />
          <Route path="/pedidos/:id/cobrar" element={<Cobrar />} />
          <Route path="/cocina" element={<Cocina />} />
          <Route path="/menu" element={<Menu />} />
          <Route path="/inventario" element={<Inventario />} />
          <Route path="/reportes" element={<Reportes />} />
          <Route path="/finanzas" element={<Finanzas />} />
          <Route path="/clientes" element={<Clientes />} />
          <Route path="/ajustes" element={<Ajustes />} />
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  )
}

/** Cada rol aterriza donde trabaja, no en un panel genérico. */
function Inicio() {
  const { sesion } = useSesion()

  const destino: Record<Rol, string> = {
    admin: '/mesas',
    waiter: '/mesas',
    cashier: '/mesas',
    kitchen: '/cocina',
  }

  return <Navigate to={destino[sesion!.usuario.role]} replace />
}

function Protegida({ children }: { children: ReactNode }) {
  const { sesion, cargando } = useSesion()

  if (cargando) return <Esperando />
  if (!sesion) return <Navigate to="/entrar" replace />

  return <>{children}</>
}

function SoloInvitados({ children }: { children: ReactNode }) {
  const { sesion, cargando } = useSesion()

  if (cargando) return <Esperando />
  if (sesion) return <Navigate to="/" replace />

  return <>{children}</>
}

function Esperando() {
  return (
    <div className="flex min-h-dvh items-center justify-center bg-piedra-50">
      <span className="text-sm text-piedra-500">Cargando…</span>
    </div>
  )
}
