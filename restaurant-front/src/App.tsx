import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import type { ReactNode } from 'react'
import Layout from './components/Layout'
import Entrar from './features/auth/Entrar'
import { useSesion } from './features/auth/SesionContext'
import Mesas from './features/mesas/Mesas'
import type { Rol } from './api/tipos'

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/entrar" element={<SoloInvitados><Entrar /></SoloInvitados>} />

        <Route element={<Protegida><Layout /></Protegida>}>
          <Route index element={<Inicio />} />
          <Route path="/mesas" element={<Mesas />} />
          <Route path="/pedidos" element={<EnObra titulo="Pedidos" />} />
          <Route path="/cocina" element={<EnObra titulo="Cocina" />} />
          <Route path="/menu" element={<EnObra titulo="Menú" />} />
          <Route path="/inventario" element={<EnObra titulo="Inventario" />} />
          <Route path="/reportes" element={<EnObra titulo="Reportes" />} />
          <Route path="/finanzas" element={<EnObra titulo="Finanzas" />} />
          <Route path="/clientes" element={<EnObra titulo="Clientes" />} />
          <Route path="/ajustes" element={<EnObra titulo="Ajustes" />} />
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

function EnObra({ titulo }: { titulo: string }) {
  return (
    <div className="p-6">
      <h1 className="text-2xl font-bold text-piedra-900">{titulo}</h1>
      <p className="mt-2 text-sm text-piedra-500">Esta pantalla es la siguiente en construirse.</p>
    </div>
  )
}
