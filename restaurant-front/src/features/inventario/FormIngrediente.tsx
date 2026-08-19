import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { interpretarError } from '../../api/client'
import { gestionInventario, type Ingrediente } from '../../api/gestion'
import {
  AvisoError,
  BotonPrimario,
  BotonSecundario,
  Campo,
  claseEntrada,
  Hoja,
} from '../../components/ui'

const UNIDADES = ['kg', 'g', 'l', 'ml', 'und', 'docena', 'libra']

export default function FormIngrediente({
  ingrediente,
  onCerrar,
  onGuardado,
}: {
  ingrediente: Ingrediente | null
  onCerrar: () => void
  onGuardado: () => void
}) {
  const [nombre, setNombre] = useState(ingrediente?.name ?? '')
  const [unidad, setUnidad] = useState(ingrediente?.unit ?? 'kg')
  const [stock, setStock] = useState(ingrediente ? '' : '')
  const [minimo, setMinimo] = useState(
    ingrediente ? String(Number(ingrediente.min_stock)) : '',
  )
  const [costo, setCosto] = useState(
    ingrediente ? String(Number(ingrediente.cost_per_unit)) : '',
  )
  const [activo, setActivo] = useState(ingrediente?.is_active ?? true)

  const guardar = useMutation({
    mutationFn: () => {
      const datos: Record<string, unknown> = {
        name: nombre,
        unit: unidad,
        min_stock: Number(minimo || 0),
        cost_per_unit: Number(costo || 0),
        is_active: activo,
      }

      // El stock solo se fija al crear; después cambia por movimientos, para
      // que inventory_movements siga explicando cada unidad.
      if (!ingrediente) datos.stock = Number(stock || 0)

      return gestionInventario.guardarIngrediente(datos, ingrediente?.id)
    },
    onSuccess: onGuardado,
  })

  const fallo = guardar.isError ? interpretarError(guardar.error) : null

  return (
    <Hoja
      titulo={ingrediente ? 'Editar ingrediente' : 'Nuevo ingrediente'}
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
          placeholder="Arroz, carne de res, aceite…"
          autoFocus
          className={claseEntrada}
        />
      </Campo>

      <Campo etiqueta="Unidad de medida" error={fallo?.campos?.unit?.[0]}>
        <div className="flex flex-wrap gap-2">
          {UNIDADES.map((u) => (
            <button
              key={u}
              onClick={() => setUnidad(u)}
              className={`min-h-11 rounded-xl px-4 text-sm font-semibold transition ${
                unidad === u
                  ? 'bg-marca-600 text-white'
                  : 'bg-piedra-100 text-piedra-700 hover:bg-piedra-200'
              }`}
            >
              {u}
            </button>
          ))}
        </div>
      </Campo>

      {!ingrediente && (
        <Campo etiqueta="Stock inicial" ayuda={`en ${unidad}`}>
          <input
            type="number"
            inputMode="decimal"
            step="any"
            min="0"
            value={stock}
            onChange={(e) => setStock(e.target.value)}
            placeholder="0"
            className={`${claseEntrada} cifras text-right`}
          />
        </Campo>
      )}

      <div className="grid grid-cols-2 gap-3">
        <Campo etiqueta="Stock mínimo" ayuda={`en ${unidad}`}>
          <input
            type="number"
            inputMode="decimal"
            step="any"
            min="0"
            value={minimo}
            onChange={(e) => setMinimo(e.target.value)}
            placeholder="0"
            className={`${claseEntrada} cifras text-right`}
          />
        </Campo>

        <Campo etiqueta="Costo por unidad">
          <input
            type="number"
            inputMode="decimal"
            step="any"
            min="0"
            value={costo}
            onChange={(e) => setCosto(e.target.value)}
            placeholder="0"
            className={`${claseEntrada} cifras text-right`}
          />
        </Campo>
      </div>

      <p className="-mt-1 mb-4 text-xs text-piedra-500">
        Al bajar del mínimo se avisa en el panel y llega una alerta en vivo.
      </p>

      {ingrediente && (
        <p className="mb-4 rounded-xl bg-piedra-100 px-3.5 py-2.5 text-sm text-piedra-600">
          El stock actual ({Number(ingrediente.stock)} {ingrediente.unit}) no se edita aquí:
          cámbialo con un movimiento para que quede registrado quién y por qué.
        </p>
      )}

      <label className="flex min-h-12 items-center gap-3 rounded-xl border border-piedra-300 px-3.5">
        <input
          type="checkbox"
          checked={activo}
          onChange={(e) => setActivo(e.target.checked)}
          className="h-5 w-5 accent-marca-600"
        />
        <span className="flex-1 text-sm text-piedra-800">En uso</span>
      </label>

      {fallo && (
        <div className="mt-4">
          <AvisoError mensaje={fallo.mensaje} esLimite={fallo.esLimite || fallo.esPlan} />
        </div>
      )}
    </Hoja>
  )
}
