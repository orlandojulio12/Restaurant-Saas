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

type Tipo = 'in' | 'out' | 'adjustment' | 'waste'

const TIPOS: { valor: Tipo; texto: string; explica: string }[] = [
  { valor: 'in', texto: 'Entrada', explica: 'Llegó mercancía del proveedor.' },
  { valor: 'out', texto: 'Salida', explica: 'Se usó fuera de un pedido.' },
  { valor: 'waste', texto: 'Merma', explica: 'Se dañó, se venció o se derramó.' },
  { valor: 'adjustment', texto: 'Conteo', explica: 'Corregir según lo que hay en bodega.' },
]

/**
 * Movimiento manual de stock.
 *
 * El ajuste por conteo no pregunta cuánto entra o sale, sino **cuánto hay**:
 * es como se cuenta de verdad en una bodega, y pedir la diferencia obligaría al
 * encargado a restar de cabeza justo donde se cometen los errores.
 */
export default function FormMovimiento({
  ingrediente,
  onCerrar,
  onGuardado,
}: {
  ingrediente: Ingrediente
  onCerrar: () => void
  onGuardado: () => void
}) {
  const [tipo, setTipo] = useState<Tipo>('in')
  const [cantidad, setCantidad] = useState('')
  const [motivo, setMotivo] = useState('')

  const actual = Number(ingrediente.stock)
  const valor = Number(cantidad || 0)
  const esConteo = tipo === 'adjustment'

  const resultante = esConteo
    ? valor
    : tipo === 'in'
      ? actual + valor
      : Math.max(0, actual - valor)

  const guardar = useMutation({
    mutationFn: () =>
      gestionInventario.movimiento(ingrediente.id, {
        type: tipo,
        ...(esConteo ? { new_stock: valor } : { quantity: valor }),
        reason: motivo || undefined,
      }),
    onSuccess: onGuardado,
  })

  const fallo = guardar.isError ? interpretarError(guardar.error) : null
  const valido = cantidad !== '' && (esConteo ? valor >= 0 : valor > 0)

  return (
    <Hoja
      titulo={`Movimiento · ${ingrediente.name}`}
      bajada={`Hay ${actual} ${ingrediente.unit} registrados.`}
      onCerrar={onCerrar}
      pie={
        <div className="flex gap-2">
          <BotonSecundario onClick={onCerrar} className="flex-1">
            Cancelar
          </BotonSecundario>
          <BotonPrimario
            onClick={() => guardar.mutate()}
            disabled={!valido || guardar.isPending}
            className="flex-[2]"
          >
            {guardar.isPending ? 'Registrando…' : 'Registrar'}
          </BotonPrimario>
        </div>
      }
    >
      <Campo etiqueta="¿Qué pasó?">
        <div className="grid grid-cols-2 gap-2">
          {TIPOS.map((t) => (
            <button
              key={t.valor}
              onClick={() => setTipo(t.valor)}
              className={`min-h-14 rounded-xl px-3 py-2 text-left text-sm font-semibold transition ${
                tipo === t.valor
                  ? 'bg-marca-600 text-white'
                  : 'bg-piedra-100 text-piedra-700 hover:bg-piedra-200'
              }`}
            >
              {t.texto}
              <span
                className={`block text-xs font-normal ${
                  tipo === t.valor ? 'text-white/80' : 'text-piedra-500'
                }`}
              >
                {t.explica}
              </span>
            </button>
          ))}
        </div>
      </Campo>

      <Campo
        etiqueta={esConteo ? `¿Cuánto hay en bodega?` : `¿Cuánto ${tipo === 'in' ? 'entró' : 'salió'}?`}
        ayuda={`en ${ingrediente.unit}`}
        error={fallo?.campos?.quantity?.[0] ?? fallo?.campos?.new_stock?.[0]}
      >
        <input
          type="number"
          inputMode="decimal"
          step="any"
          min="0"
          value={cantidad}
          onChange={(e) => setCantidad(e.target.value)}
          placeholder="0"
          autoFocus
          className={`${claseEntrada} cifras text-right text-2xl font-bold`}
        />
      </Campo>

      {cantidad !== '' && (
        <div className="mb-4 flex items-center justify-between rounded-xl bg-piedra-100 px-4 py-3">
          <span className="text-sm text-piedra-600">Quedará en</span>
          <span className="cifras text-xl font-bold text-piedra-900">
            {resultante} {ingrediente.unit}
          </span>
        </div>
      )}

      {!esConteo && tipo !== 'in' && valor > actual && (
        <div className="mb-4">
          <AvisoError mensaje={`Solo hay ${actual} ${ingrediente.unit}. El stock quedará en 0, no en negativo.`} esLimite />
        </div>
      )}

      <Campo etiqueta="Motivo" ayuda="(opcional)">
        <input
          value={motivo}
          onChange={(e) => setMotivo(e.target.value)}
          placeholder={esConteo ? 'Conteo del lunes' : 'Factura 1234, se venció…'}
          className={claseEntrada}
        />
      </Campo>

      {fallo && (
        <AvisoError mensaje={fallo.mensaje} esLimite={fallo.esLimite || fallo.esPlan} />
      )}
    </Hoja>
  )
}
