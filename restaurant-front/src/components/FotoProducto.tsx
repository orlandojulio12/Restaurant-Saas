/**
 * La foto de un producto, con un sustituto digno cuando no la hay.
 *
 * En una carta la foto es lo que hace reconocer el plato antes de leerlo, así
 * que vale la pena mostrarla en grande. Pero ningún restaurante sube las 40
 * fotos el primer día, y una cuadrícula con huecos vacíos se ve peor que una
 * sin fotos: el sustituto tiñe un color estable a partir del nombre, de modo
 * que cada plato sigue siendo distinguible de un vistazo aunque nadie haya
 * subido nada.
 */

/**
 * Colores del sustituto: elegidos, no aleatorios.
 *
 * Repartir el tono por todo el círculo cromático daba dos problemas: nombres
 * distintos caían a pocos grados —"Bandeja Paisa" y "Jugo Natural" salían del
 * mismo rosa— y el conjunto parecía sopa pastel. Estos nueve están separados a
 * propósito y conviven con el verde azulado de la marca, así que una carta
 * entera sin fotos sigue pareciendo diseñada.
 */
const TONOS = [12, 28, 45, 95, 150, 175, 210, 280, 330]

/**
 * El mismo plato sale siempre del mismo color, lo que permite reconocerlo por
 * posición y color aunque no se lea la etiqueta.
 */
function tonoDe(texto: string): number {
  let h = 2166136261

  for (let i = 0; i < texto.length; i++) {
    h ^= texto.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }

  return TONOS[(h >>> 0) % TONOS.length]
}

export default function FotoProducto({
  nombre,
  url,
  className = '',
  redondeo = 'rounded-xl',
}: {
  nombre: string
  url?: string | null
  className?: string
  redondeo?: string
}) {
  if (url) {
    return (
      <img
        src={url}
        alt=""
        loading="lazy"
        className={`${redondeo} bg-piedra-100 object-cover ${className}`}
      />
    )
  }

  const tono = tonoDe(nombre)
  const inicial = nombre.trim().charAt(0).toUpperCase() || '?'

  return (
    <span
      aria-hidden
      className={`${redondeo} flex items-center justify-center font-bold ${className}`}
      style={{
        // Saturación baja y luminosidad alta para el fondo; el mismo tono muy
        // oscuro para la letra. Así cualquier color del círculo cromático
        // mantiene el contraste sin tener que revisarlos uno a uno.
        backgroundColor: `hsl(${tono} 42% 90%)`,
        color: `hsl(${tono} 55% 26%)`,
      }}
    >
      {inicial}
    </span>
  )
}
