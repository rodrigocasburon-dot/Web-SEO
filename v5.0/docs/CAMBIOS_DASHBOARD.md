# Cambios de la pantalla de resultados (dashboard)

Rama `perfiles-resumen-dashboard`, 11/11/2026. Mejora la pantalla de la v5.0; no la reescribe.

## Orden por capas (primero las notas)

1. **Cabecera**: URL, fecha, «Abrir», «Informe (PDF)», «Hoja de ruta (PDF)», «Historial» y «Nuevo análisis».
2. **Perfil de lectura**: Marketing, Dirección o Desarrollo web (`?perfil=`, sin cookie). Cambiarlo vuelve a
   pintar el mismo resultado: no repite el análisis ni llama a PageSpeed.
3. **Tres tarjetas** (SEO, GEO, UX) con la nota de la URL, su rango (Crítico, Mejorable, Bueno, Muy bueno),
   la variación frente al análisis anterior (si hay historial) y los puntos evaluados. **Sin nota global.**
   Debajo, en gris, la media del sitio como dato secundario.
4. **Resumen ejecutivo**: el mismo que abre los dos PDF (`RadarPerfil.summary`), con las prioridades enlazadas
   a su punto (abre el bloque y lleva el foco).
5. **Detalle por bloque** (SEO, GEO, UX, Toda la web), agrupado por estado y con **filtro por gravedad**.
6. **Puntos no evaluados**, aparte: pendientes de PageSpeed, sin datos y que no aplican.
7. **Evolución**: solo con 2 análisis o más de la misma URL. SVG propio (sin CDN), con resumen en texto y tabla.
8. **Cobertura**: barra segmentada con leyenda y el mismo texto que el resumen.

El bloque «Primer análisis: la página que has escrito» deja de mostrarse (sus tarjetas incluían la nota global);
`updateScores` sigue funcionando sin pintarse, para no tocar el flujo de PageSpeed. «Resultado del sitio» ya no
repite tarjetas por área: muestra la media del sitio y remite al detalle por bloque, que incluye las páginas afectadas.

## Accesibilidad

- Estados con icono + texto + trazo (borde continuo en fallos, discontinuo en avisos), no solo color.
- Gráfico: `figure` con `figcaption`, resumen en texto y «Ver los datos en una tabla»; cada área con trazo y
  marcador distintos (continuo y círculo; discontinuo y cuadrado; punteado y triángulo).
- Selector y filtro con zonas de pulsación de 44 px y foco visible; el recuento del filtro se anuncia (`role="status"`).
- `prefers-reduced-motion`: sin transiciones ni animaciones; el gráfico no se anima.
- Sin desplazamiento horizontal a 390 px (comprobado con `scrollWidth`).

## Archivos

| Archivo | Cambio |
|---|---|
| `assets/js/dashboard.js` | Nuevo: pantalla por capas, selector de perfil, filtro, evolución y cobertura |
| `assets/js/perfiles.js` | Nuevo: modelo único, orden por perfil y resumen ejecutivo |
| `assets/js/app.js` | Cabecera con PDF e historial; sin nota global en pantalla; PDF por perfil |
| `assets/js/site.js` | Pasa los datos del sitio al modelo; media del sitio como dato secundario |
| `assets/css/componentes.css` | Estilos de perfil, tarjetas, resumen, puntos, filtro y gráfico |

## Pendiente o por decidir

- El historial no está activado en el entorno de pruebas local: el gráfico se comprobó con dos análisis
  anteriores simulados en memoria (no se guardó nada) y con las pruebas automáticas.
- CSV y JSON siguen incluyendo la nota global (no se pidió cambiarlos).
