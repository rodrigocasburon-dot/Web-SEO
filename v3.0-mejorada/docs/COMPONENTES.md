# Componentes visuales del informe

Implementación de `docs/DESIGN.md` para el informe de resultados. Código en `assets/js/rw-componentes.js`
(objeto global `RadarComponents`) y estilos en `assets/css/tokens.css`, `componentes.css` y `graficos.css`
(clases con prefijo `rw-`, aplicadas dentro de un contenedor `.rw`).

Para verlos sin lanzar un análisis: `docs/muestra-componentes.html` (datos de ejemplo).

## Reglas comunes

- Reciben datos ya calculados (Scorer, Report, historial) y devuelven nodos DOM. **No calculan notas.**
- Todo el texto se escribe con `textContent`: los datos de la web analizada quedan escapados. Sin `innerHTML`.
- Los anchos se fijan con `element.style` (CSSOM), compatible con la CSP (`style-src 'self'`).
- Ningún componente sabe de qué bloque viene el dato: los usan SEO, Preparación para IA y Accesibilidad.
- Colores solo de `tokens.css`. Estados con muestra + icono + texto (nunca solo color).

### Desviación respecto a DESIGN.md (decidida con el usuario)

DESIGN.md pide gráficos en SVG generado en PHP. El informe de la v3.0 lo pinta el navegador (`app.js`), así que
los componentes son JavaScript que crea HTML (y SVG en el minigráfico). Consecuencia: los resultados necesitan
JavaScript, como antes. Las texturas usan CSS con `forced-color-adjust: none` y `print-color-adjust: exact`
para mantenerse con colores forzados y al imprimir.

## Estados

| Valor de `estado` | Clave visual | Muestra | Icono | Texto por defecto |
|---|---|---|---|---|
| `CUMPLE` | `ok` | negro sólido (compacto: punto de 6 px) | ✓ | Sin errores detectables automáticamente |
| `AVISO` | `warn` | rayas rojas a 45° (2 px, paso 4 px) | ! | Aviso |
| `NO_CUMPLE` | `fail` | rojo sólido | ✕ | Fallo |
| `SIN_DATOS` | `nodata` | gris línea con borde gris marca | – | Sin datos |
| `NA` (no aplica) | `na` | sin relleno, borde gris marca | ○ | No aplica |

Los textos por defecto son los de `lang/es.php` (el CLAUDE.md prima sobre el «Cumple» de DESIGN.md). Un estado
desconocido se dibuja como «sin datos»: nunca como éxito ni como fallo.

## API

| Función | Entrada | Devuelve |
|---|---|---|
| `status(estado, {texto, compacto})` | estado del motor o `'NA'` | `span.rw-status` |
| `severityChip(severidad, texto)` | `BAJO`, `MEDIO`, `ALTO`, `CRITICO` | `span.rw-chip` o `null` |
| `meter(valor, {etiqueta})` | 0–100 | `span.rw-meter` (decorativo salvo con `etiqueta`) |
| `table({titulo, columnas, filas})` | `columnas: [{texto, num}]`, `filas: [[celda, …]]` | `div.rw-table-wrap` |
| `figure({titulo, resumen, visual, tabla, clave})` | `titulo` = conclusión; `tabla` = `{columnas, filas}` | `figure.rw-figure` |
| `empty(titulo, motivo)` | textos | `div.rw-empty` («Sin datos suficientes: …») |
| `segmentedBar(segmentos, {baja})` | `[{clave, texto, valor}]` (máx. 5 con valor > 0) | `{node, total, segmentos}` |
| `rankedBars(items, {max})` | `[{texto, valor, valorTexto}]` (máx. 5, de mayor a menor) | `ul.rw-bars` |
| `bullet(m)` | `{nombre, valor, valorTexto, bueno, deficiente, maximo, franja}` | `div.rw-bullet` |
| `matrix(spec)` | `{titulo, columnas: [{id, texto}], filas: [{texto, enlace, celdas: {id: estado}}], textos}` | `div.rw-matrix-block` |
| `delta(actual, anterior)` | dos notas | `span.rw-delta` o `null` |
| `sparkline(valores, {etiqueta})` | notas del más antiguo al más reciente (≥ 3) | `svg.rw-spark` o `null` |

Claves de `segmentedBar`: de estado (`ok`, `warn`, `fail`, `nodata`, `na`) o de gravedad (`sev-baja`,
`sev-media`, `sev-alta`, `sev-critica`).

`figure()` oculta el visual a los lectores de pantalla (`aria-hidden`), porque el título, el resumen y la tabla
equivalente contienen la misma información.

## Dónde se usan

| Lugar | Componentes | Datos |
|---|---|---|
| Notas (`app.js`, `updateScores`) | `meter`, `delta`, `sparkline` | `puntuacion.areas`, `informe.cobertura`, `historial.anteriores` |
| Resumen ejecutivo · «Qué arreglar primero» | `figure`, `rankedBars` | `informe.prioridades[].penalizacion` (Report, con las constantes del Scorer) |
| Cabecera de cada bloque | `meter`, `segmentedBar` (estados) | recuento de estados del bloque |
| Bloque SEO | `figure`, `bullet` (Core Web Vitals), `segmentedBar` (gravedad) | C14 `valor_medido`, gravedad de los fallos |
| Lista de puntos (los tres bloques) | `status`, `severityChip` | `checks[]` |
| Informe de sitio (`site.js`) | `matrix` (puntos SEO, ≥ 5 páginas) | estado de cada punto en cada página |
