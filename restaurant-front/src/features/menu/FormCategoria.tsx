import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { interpretarError } from '../../api/client'
import { gestionMenu } from '../../api/gestion'
import type { Categoria } from '../../api/tipos'
import {
  AvisoError,
  BotonPrimario,
  BotonSecundario,
  Campo,
  claseEntrada,
  Hoja,
} from '../../components/ui'

export default function FormCategoria({
  categoria,
  onCerrar,
  onGuardado,
}: {
  categoria: Categoria | null
  onCerrar: () => void
  onGuardado: () => void
}) {
  const [nombre, setNombre] = useState(categoria?.name ?? '')
  const [descripcion, setDescripcion] = useState(categoria?.description ?? '')
  const [activa, setActiva] = useState(categoria?.is_active ?? true)

  const guardar = useMutation({
    mutationFn: () => {
      const datos = { name: nombre, description: descripcion || null, is_active: activa }

      return categoria
        ? gestionMenu.editarCategoria(categoria.id, datos)
        : gestionMenu.crearCategoria(datos)
    },
    onSuccess: onGuardado,
  })

  const fallo = guardar.isError ? interpretarError(guardar.error) : null

  return (
    <Hoja
      titulo={categoria ? 'Editar categoría' : 'Nueva categoría'}
      onCerrar={onCerrar}
      pie={
        <div className="flex gap-2">
          <BotonSecundario onClick={onCerrar} className="flex-1">
            Cancelar
          </BotonSecundario>
          <BotonPrimario
            onClick={() => guardar.mutate()}
            disabled={!nombre.trim() || guardar.isPending}
            className="flex-[2]"
          >
            {guardar.isPending ? 'Guardando…' : 'Guardar'}
          </BotonPrimario>
        </div>
      }
    >
      <Campo etiqueta="Nombre" error={fallo?.campos?.name?.[0]}>
        <input
          value={nombre}
          onChange={(e) => setNombre(e.target.value)}
          placeholder="Platos fuertes"
          autoFocus
          className={claseEntrada}
        />
      </Campo>

      <Campo etiqueta="Descripción" ayuda="(opcional)">
        <textarea
          value={descripcion ?? ''}
          onChange={(e) => setDescripcion(e.target.value)}
          rows={2}
          className={`${claseEntrada} py-2.5`}
        />
      </Campo>

      <label className="flex min-h-12 items-center gap-3 rounded-xl border border-piedra-300 px-3.5">
        <input
          type="checkbox"
          checked={activa}
          onChange={(e) => setActiva(e.target.checked)}
          className="h-5 w-5 accent-marca-600"
        />
        <span className="flex-1 text-sm text-piedra-800">
          Visible en la carta
          <span className="block text-xs text-piedra-500">
            Al ocultarla, sus productos dejan de aparecer en el menú del QR.
          </span>
        </span>
      </label>

      {fallo && (
        <div className="mt-4">
          <AvisoError mensaje={fallo.mensaje} esLimite={fallo.esLimite} />
        </div>
      )}
    </Hoja>
  )
}
