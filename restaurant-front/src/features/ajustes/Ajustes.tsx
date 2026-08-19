import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { gestionAjustes } from '../../api/gestion'
import { Pestanas } from '../../components/ui'
import { useSesion } from '../auth/SesionContext'
import PanelLocal from './PanelLocal'
import PanelMesas from './PanelMesas'
import PanelUsuarios from './PanelUsuarios'

/**
 * Ajustes del restaurante.
 *
 * Es la pantalla que desbloquea a un local nuevo: sin mesas no se puede tomar
 * un solo pedido. Por eso, cuando aún no hay ninguna, la sección de mesas se
 * abre primero aunque no sea la primera pestaña.
 */
type Seccion = 'local' | 'mesas' | 'usuarios'

export default function Ajustes() {
  const { sesion } = useSesion()

  const mesas = useQuery({ queryKey: ['mesas'], queryFn: gestionAjustes.mesas })
  const sinMesas = !mesas.isLoading && (mesas.data?.length ?? 0) === 0

  const [seccion, setSeccion] = useState<Seccion | null>(null)
  const actual: Seccion = seccion ?? (sinMesas ? 'mesas' : 'local')

  return (
    <div className="p-4 lg:p-6">
      <header className="mb-4">
        <h1 className="text-2xl font-bold text-piedra-900">Ajustes</h1>
        <p className="text-sm text-piedra-500">
          {sesion?.restaurante.name} · plan {sesion?.restaurante.plan.display_name}
        </p>
      </header>

      {sinMesas && actual === 'mesas' && (
        <div className="mb-4 rounded-2xl border border-atencion bg-white p-4">
          <p className="font-semibold text-piedra-900">Empieza por aquí</p>
          <p className="mt-1 text-sm text-piedra-600">
            Sin mesas no se pueden tomar pedidos en el local. Créalas con el número que ya tienen
            pintado o pegado, para que el mesero encuentre la misma que ve delante.
          </p>
        </div>
      )}

      <Pestanas
        actual={actual}
        onCambio={setSeccion}
        opciones={[
          { valor: 'local', texto: 'El local' },
          { valor: 'mesas', texto: 'Mesas y zonas', contador: mesas.data?.length },
          { valor: 'usuarios', texto: 'Personal' },
        ]}
      />

      {actual === 'local' && <PanelLocal />}
      {actual === 'mesas' && <PanelMesas />}
      {actual === 'usuarios' && <PanelUsuarios />}
    </div>
  )
}
