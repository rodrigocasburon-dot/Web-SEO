# Radar web v5.0: SEO, GEO y UX (Proconsi)

Herramienta web interna en PHP (sin framework ni Composer) que analiza una URL y genera un
informe de SEO, preparación para IA y accesibilidad. La evaluación es automática y parcial:
no sustituye una auditoría manual ni certifica el cumplimiento de WCAG ni de ninguna ley.

Estado: **v5.0** = el motor de la v3.0-mejorada dentro de la v4.0, con resultado simplificado, página de requisitos, PDF que se descargan e imagen de Proconsi (11/11/2026). La v4.0 es la v3.0 (v2.0 + lo mejor de la v1.0) con buscador único, sitio entero, vista por departamento, comparar auditorías y pistas para la revisión manual (10/11/2026). Las fases y reglas están en `CLAUDE.md`.

Se abre en `http://localhost/Web-SEO/v5.0/`. Instalación igual que la v3.0: base de datos `radar_web` con `install.sql` (la v5.0 usa la misma tabla) y `config/config.php` a partir de `config/config.example.php`.

## v5.0: motor unificado e imagen corporativa (11/11/2026)

- Motor: el de la v3.0-mejorada (C25, C29, C30 y C31, fiabilidad, registro de errores, guardado atómico) con la orquestación del sitio de la v4.0.
- Resultado más simple: tarjeta de la URL con su primera nota, progreso del sitio y la nota del sitio en tres tarjetas (SEO, GEO, UX) que se despliegan con lo que falla o falta. La lista de páginas va solo en el CSV.
- Nueva página **Requisitos** (`requisitos.php`) con todas las comprobaciones y su PDF.
- Los PDF (informe, hoja de ruta y requisitos) se descargan, generados en el navegador por `assets/js/pdf.js`; los CSV llevan cabecera corporativa.
- Identidad Proconsi (`assets/css/proconsi.css`). Se quitan de la pantalla los departamentos y las pruebas con Chrome.

## v4.0: buscador único y web entera (10/11/2026)

Solo hay **dos pantallas**:

- **Buscador** (`index.php`): se escribe la URL y se eligen dos cosas:
  - **Tipo de análisis.** *Rápido* (por defecto): la portada y 49 páginas principales repartidas por secciones
    (proconsi.com: 41 s). *Profundo*: **todas** las páginas que se encuentren (sitemaps y, después, los enlaces de cada
    página analizada), de 6 en 6, hasta el tope `site_max_pages` (5.000; máx. 20.000). proconsi.com entero: 394 páginas
    en 2 min 25 s. «Terminar ya» corta y muestra lo analizado.
  - **Departamento** (`assets/js/focus.js`). *Quitado de la pantalla el 11/11/2026 hasta decidir cómo hacerlo*; se
    conserva el código. Así funcionaba: Informe completo, Marketing y contenidos, Desarrollo web, Diseño y UX o
    Dirección. Añade arriba «Para tu departamento»: lo que le toca a ese equipo, explicado a su medida («Por qué importa»
    en llano para Marketing, Diseño y Dirección; lo medido para Desarrollo), y a quién pasar el resto. En el sitio, la
    tabla de fallos lleva la columna «Quién lo arregla» y pone primero lo de ese equipo. **No cambia ninguna nota.** Se
    puede cambiar con el informe en pantalla, sin volver a analizar. Equipo y explicación de cada comprobación: `lang/es.php` 'enfoque'.

  Primero sale el informe completo de esa página (con PageSpeed, tono del contenido, plan de acción, descargas…) y debajo, el del sitio.
- **Historial** (`historial.php`): una fila por búsqueda; «Ver informe» abre la página y el sitio tal como estaban.
  **Comparar auditorías:** se marcan dos filas y «Comparar los dos marcados» (`index.php?comparar[]=A&comparar[]=B`), o
  «Comparar» en «Análisis anteriores de esta URL» dentro del informe. Enseña notas, comprobaciones que han cambiado y, si
  las dos tienen el sitio, las medias y el % de páginas que fallan cada comprobación (mejora / empeora).

**Pistas para la revisión manual** (`src/ManualHints.php`, sin IA, no puntúan): en RM-01 se señalan los textos alternativos
que seguro no describen la imagen (nombre de archivo, palabra genérica, una letra, más de 150 caracteres, el mismo en 3 o más
imágenes distintas, «imagen de…»); en RM-09, la legibilidad con el índice de Fernández-Huerta (solo español), las frases de
más de 30 palabras y los párrafos de más de 120. Salen en «Revisiones manuales», en «Análisis complementario» y, por páginas, en
el informe del sitio y en el CSV. No sustituyen la revisión: dicen por dónde empezar.

Las áreas se llaman **SEO, GEO y UX** (antes SEO, Preparación para IA y Accesibilidad): mismas comprobaciones, pesos y fórmula.
`sitio.php` ya no es una pantalla: redirige al buscador (los enlaces antiguos `sitio.php?informe=ID` siguen funcionando).

Cómo se recorre el sitio sin abrir un «proxy»: `api/site.php` crea un identificador de análisis y firma cada página con él
(`SiteToken::pageScope`); `api/site_page.php` solo analiza páginas firmadas y devuelve los enlaces nuevos de esa página ya
firmados para el mismo análisis. `api/site_record.php` solo guarda resultados de ese análisis y los añade a la fila de la
URL buscada (`History::attachSite`). Los resultados se guardan 4 h en `storage/runs/` (`RunStore::TTL`).

**Control en el servidor (`src/SiteRun.php`, 11/11/2026).** El modo y el tope de páginas se fijan al crear el análisis y
no los puede cambiar el navegador: `api/site_page.php` cuenta las páginas analizadas y las firmadas, y no analiza ni firma
ninguna más allá del tope (en el rápido no firma enlaces nuevos). Entre una página y la siguiente del mismo análisis pasa
al menos `site_min_interval_ms` (250 ms) o el `Crawl-delay` de robots.txt, si es mayor (máx. 10 s). Si el sitio responde
429 o 503, el análisis se detiene y el informe lo indica.

Ojo en webs grandes: a unas 3 páginas por segundo (lo medido con proconsi.com), 5.000 páginas son unos 30 minutos y
muchas peticiones al servidor analizado.

## v3.0: versión unificada

Parte de la v2.0 (seguridad, pruebas, fórmula de nota, informe, CSV/JSON/PDF e historial) y añade de la v1.0
el análisis del sitio completo y las pruebas con Chrome, pero sin los 20 minutos de la v1.0.

| Qué | De dónde | Archivos |
|---|---|---|
| Analizar una URL (todo lo de la v2.0) | v2.0 | `index.php`, `api/analyze.php`, `api/psi.php` |
| **Analizar un sitio** en 30–60 s | idea de la v1.0, hecho nuevo | `sitio.php`, `assets/js/site.js`, `api/site.php`, `api/site_page.php`, `src/SitePlanner.php`, `src/SiteToken.php` |
| **Pruebas con Chrome** (teclado, foco, zoom 400 %, cookies, carrusel, formulario) | v1.0 | `src/Navegador.php`, `src/browser/medidas.js`, `src/BrowserChecks.php`, `api/browser.php` |
| HTTPS sin tocar php.ini | v1.0 | `certs/cacert.pem`, `CurlTransport::caOptions()` |
| **4 comprobaciones nuevas por página** (puntúan): C21 título en iframes, C22 id repetidos, C23 subtítulos en vídeo, C24 reproducción automática | v1.0 (UX-16, UX-19, UX-15, UX-10), afinadas | `src/Checks/C21…C24*.php` |
| **Comprobaciones de todo el sitio** (informativas): página 404 con una URL inventada y títulos repetidos entre páginas | v1.0 (WEB-01, WEB-08) | `api/site.php`, `assets/js/site.js` |
| PageSpeed (contraste y Core Web Vitals) de la portada en el modo sitio | v2.0 | `assets/js/site.js` → `api/psi.php` |
| PDF del informe del sitio, menú con la página actual y estilos para el alto contraste de Windows | nuevo | `assets/css/app.css` |
| Historial también de los análisis de sitio y página `historial.php` con todas las URL analizadas | v2.0, ampliado | `src/History.php`, `api/site_record.php`, `historial.php` |

### Cómo se ha bajado el tiempo del sitio completo (de ~20 min a 30–60 s)

La v1.0 descargaba **todas** las páginas una a una (con pausa de 300 ms) y abría Chrome en 5 páginas.
La v3.0 hace tres cosas distintas:

1. **Muestra en vez de todo.** `SitePlanner` junta los enlaces de la portada y hasta 4 archivos de sitemap y elige
   hasta `site_max_pages` (50) páginas **repartidas por secciones** (`/blog`, `/productos`, `/contacto`…). Las plantillas
   de un sitio se repiten: con 50 páginas bien repartidas salen los mismos fallos que con miles.
2. **En paralelo.** El navegador lanza las páginas de `site_parallel` (6) en 6 contra `api/site_page.php`. Cada petición
   es un proceso PHP distinto en Apache, así que trabajan a la vez de verdad.
3. **Sin repetir lo que es de todo el sitio.** robots.txt se guarda 10 minutos en `storage/robots_cache/`; enlaces rotos (C16)
   y sitemap (C18) solo se comprueban en la portada. Chrome solo prueba la portada y lo hace **a la vez** que las páginas.

Tiempos medidos en local (WAMP, 9/11/2026), portada + 50 páginas:

| Sitio | v1.0 | v3.0 |
|---|---|---|
| proconsi.com | 19,6 min (401 páginas) | 32 s (sin Chrome) |
| genbeta.com | 21 min, parado a mano (645 de 61.818) | 40 s (con Chrome) |
| decathlon.es | error de memoria, sin informe | 5 s: «acceso bloqueado», sin nota |

### Nota del sitio

Cada página se puntúa con la fórmula de la v2.0. La nota del sitio es la **media** de las páginas analizadas, y se
muestra también la peor página. La tabla «Fallos por comprobación» dice en cuántas páginas falla cada punto
(«C04 falla en 39 de 51 páginas»), que era lo más útil de la v1.0. Las páginas bloqueadas o con error no puntúan.
Las pruebas con Chrome, PageSpeed de la portada, la página 404 y los títulos repetidos son **informativos** (no cambian la nota media).

### Seguridad del modo sitio

- `api/site.php` cuenta como un análisis en el límite de uso y **firma** (HMAC, 30 min) cada página de la muestra.
  `api/site_page.php` y `api/browser.php` solo aceptan URL firmadas: no sirven para descargar direcciones arbitrarias.
  La clave se crea sola en `storage/site_secret`.
- Todas las descargas pasan por `UrlGuard` (solo http/https, puertos 80/443, sin IP privadas, IP fijada).
- Los sitemaps se leen con el límite de 2 MB por archivo: un sitemap enorme ya no agota la memoria (fallo de la v1.0).
- **Chrome ejecuta el JavaScript de la web analizada.** Está **desactivado por defecto**. Activarlo solo en red interna:
  `'chrome' => ['activo' => true]` en `config/config.php`. Todas sus peticiones pasan por `UrlGuard`, solo se permiten
  GET/HEAD/OPTIONS, se bloquean los WebSockets y el perfil es temporal. Los formularios se envían **vacíos**, nunca con datos.

### Comprobaciones de la v1.0 que se han traído y las que no

De las 88 de la v1.0, la mayoría ya estaban en la v2.0 con otro número (estado y HTTPS, noindex, robots, sitemap, canonical,
title, meta description, H1 y encabezados, viewport, idioma, alt, JSON-LD, nombres accesibles, etiquetas de formulario,
roles ARIA, contenido mixto, enlaces rotos, contenido sin JavaScript…). Se han traído las que faltaban y se pueden medir
bien desde el HTML: C21–C24 por página, la página 404 y los títulos repetidos en el modo sitio, y las 6 pruebas de Chrome.

**No** se han traído (a propósito): las de negocio local (dirección, horarios, coordenadas, reseñas, mapa), las que eran
estimaciones con poca base (lenguaje sencillo Flesch, relleno de palabras, contenido suficiente de 300 palabras, proporción
texto/código) y las que necesitan IA de pago (calidad del alt e intención de búsqueda).

### Pendiente

- **Decidido por el grupo (9/11/2026):** pesos de `config/weights.php` aprobados (incluidos C16, C18, C20 y C21–C24);
  de momento **no** se usa IA de pago; la clave de PageSpeed va en `config/config.php` (no se sube a git).
- PageSpeed y Chrome solo se hacen en la portada en el modo sitio (75 s y ~15 s por página si no).
- Los estilos de alto contraste no se han probado todavía con el modo de alto contraste real de Windows.

## Historial de análisis (Fase 4; ampliado en la v3.0)

**v3.0:** el historial guarda también los análisis de **sitio completo** (tipo `sitio`, con la nota media de las páginas,
el nº de páginas y las comprobaciones distintas que fallan en alguna, por gravedad) y tiene su propia página,
**`historial.php`** (enlace «Historial» en el menú): lista de los últimos análisis con buscador por dirección, filtro por
tipo y páginas de 50. En el modo sitio, al terminar se guarda el análisis y se muestran los anteriores de ese sitio.

**Informe completo (decisión del grupo del 9/11/2026):** cada fila guarda también el informe entero (JSON comprimido,
columna `informe`), con evidencias y extractos de la web. En `historial.php`, «Ver informe» lo abre tal como salió ese día
(`index.php?informe=ID` o `sitio.php?informe=ID`, vía `api/history_report.php`). Lo guarda **siempre el servidor**: el
de una URL en `api/analyze.php` (y `api/score.php` lo completa con PageSpeed); el de sitio en `api/site_record.php`, que
junta los resultados que `site.php`, `site_page.php`, `browser.php` y `psi.php` dejaron 1 hora en `storage/runs/`
(`src/RunStore.php`). El navegador solo dice qué páginas se analizaron. Los análisis anteriores a esto no tienen informe.

Para que nadie pueda guardar notas inventadas, `api/site_record.php` solo acepta páginas firmadas de ese análisis,
usa los resultados del servidor, **recalcula la media** él mismo y solo acepta cada análisis una vez.
Si ya tenías la base de la v2.0, ejecuta una vez el `ALTER TABLE` del final de `install.sql` (añade `tipo`, `paginas` e `informe`).

Lo de abajo es lo que ya hacía la v2.0 para una URL.

Opcional y desactivado por defecto. Guarda en MySQL, por cada análisis, la URL, la fecha (UTC), las tres notas y la
global, si alguna es incompleta, si hubo bloqueo, si incluye PageSpeed y los fallos por gravedad. **Nunca** guarda
contenido, evidencias ni extractos de la web analizada. Las filas de más de 12 meses se borran al guardar otra.
El informe muestra, plegado, «Análisis anteriores de esta URL» (hasta 10; misma clave de `UrlNormalizer`).

Activarlo:
1. Importar `install.sql` en phpMyAdmin (crea la base `radar_web`, separada de la `web_seo` de la v1.0).
2. Copiar a `config/config.php` el bloque `'historial'` de `config.example.php` con el DSN, el usuario y la clave.

La nota se guarda al terminar `api/analyze.php` (sin PageSpeed) y se actualiza una vez en `api/score.php` cuando llegan
C08 y C14. Para no aceptar notas enviadas por el navegador, `analyze.php` y `psi.php` firman (HMAC) los estados que
calculan y `score.php` comprueba las dos firmas. La clave de firma se genera sola en `storage/historial.key` (no se sube
a git ni es accesible por web). Sin historial, todo funciona como antes. No hay login: quien acceda a la herramienta
ve el historial de cualquier URL que analice.

## Funciones informativas (no puntúan)

- **01 Simulador de mejora** (`src/Simulator.php`): recalcula con el mismo `Scorer` las notas por área y la global
  suponiendo corregidos los N fallos que más restan (w × P; N = `simulator_top_n`, 3 por defecto, de 1 a 15) y
  las muestra como «antes / después», marcadas como estimación.
- **02 Lo que ve un bot** (`src/Insights.php`, nuevo apartado 7 «Análisis complementario (no puntúa)», antes del
  glosario; el informe pasa a diez apartados): texto del contenido
  principal del HTML inicial con la misma extracción y el mismo recuento que C03 (`DomHelper::mainContent` /
  `countWords`), nº de palabras, aviso si hay poco texto y unos 5.000 caracteres ampliables al texto completo.
- **03 Vista previa en Google y redes**: title / URL / meta description recortados a unos 60 / 60 / 155 caracteres
  y tarjeta social (og:title, og:description, og:image, twitter:card) con las etiquetas ausentes; la imagen solo se
  carga si es https, con `referrerpolicy="no-referrer"` (la CSP admite `img-src https:`). Es una aproximación.
- **04 Tabla de bots de IA** (`src/RobotsAnalyzer.php`): acceso a la URL de los bots de búsqueda (`search_engine_bots`
  + `ai_search_bots`) y de entrenamiento (`ai_training_bots`) con el grupo y la regla aplicados (grupo del propio
  token o «*», regla más larga, Allow en empate). C02 usa la misma lectura de robots.txt; ausencia o error se informan.
- **05 Palabra clave objetivo** (campo opcional del formulario, `palabra_clave`, máx. 100 caracteres): si la frase
  aparece, sin distinguir mayúsculas ni tildes (la ñ sí), en title, H1, meta description, URL y primeras 100 palabras
  del contenido principal. Lista informativa con «Aparece / No aparece» por elemento.
- **06 Esquema del documento**: árbol anidado (`ul` dentro de `li`) de los h1-h6 visibles con los saltos de nivel
  marcados (criterio de C05) y recuento de regiones header/nav/main/aside/footer y sus roles ARIA, con ocultas y nombres.
- **07 Redirecciones y peso**: con los datos que ya da el `Fetcher`, cadena de peticiones (URL, código, tiempo), tiempo
  total, tamaño del HTML (descomprimido) y `Content-Encoding`; aviso informativo con más de 2 redirecciones.
- **08 Exportar CSV y JSON** (`assets/js/export.js`): botones «Descargar CSV / JSON» generados en el navegador (Blob),
  sin guardar nada en el servidor. Columnas: id, comprobación, área, estado, detalle (en español), severidad, valor
  medido, umbral, criterio, evidencia, recomendación, impacto y esfuerzo. CSV con «;», UTF-8 con BOM y protección frente a fórmulas (apóstrofo ante `=`, `+`,
  `-`, `@`, tabulador o retorno). Se prueba en Edge/Chrome sin interfaz (`tests/js/`); sin navegador queda OMITIDA.
- **09 Fragmento de corrección** (`src/FixSnippets.php`, plantillas en `lang/es.php` → `correcciones`): en las
  recomendaciones, «antes / después» para C04, C06, C07, C09, C10, C12, C15 y C20 (máx. 3 casos). «Antes» es la
  etiqueta real de la evidencia; si está recortada o el fallo no es sencillo, no se muestra nada. Primer borrador.
- **10 Comparar dos URLs** (`assets/js/compare.js`, modo «Comparar dos URLs» del formulario): el navegador lanza un
  análisis independiente por URL (una URL por petición; cuenta como dos en el límite de uso) con PageSpeed para ambas
  y muestra notas por área y global con su diferencia, recuento de estados y C01–C20 lado a lado (distintas / iguales).

## Entradas que no son la web del negocio

`InputUrlPolicy::check()` (`src/InputUrlPolicy.php`) se ejecuta en `api/analyze.php` y `api/psi.php` antes de
`UrlGuard` (que se sigue aplicando igual) y, por tanto, antes de descargar nada o consultar PageSpeed: Google Maps y
acortadores (`g.page`, `goo.gl`, `maps.app.goo.gl`, `bit.ly`, `tinyurl.com`, `t.co`…) → 400 `NOT_BUSINESS_SITE`;
credenciales, localhost, dominios internos e IP no públicas → el código de siempre con la indicación «Introduce la
dirección de la web del negocio.». Solo se aplica a lo que escribe el usuario, no a redirecciones ni enlaces. La
lista de acortadores es provisional.

## Normalización de URL para comparar (no conectada)

`UrlNormalizer::comparisonKey($url)` (`src/UrlNormalizer.php`) devuelve una clave para saber si dos URL son la misma
página: parte de `UrlGuard::normalize()` (esquema y host en minúsculas, sin fragmento ni puerto por defecto, sin DNS),
quita «www.» si queda un dominio, los parámetros de seguimiento (`utm_*`, `gclid`, `gbraid`, `wbraid`, `fbclid`,
`msclkid`, `dclid`, `yclid`, `mc_cid`, `mc_eid`, `igshid`) y la barra de la raíz; la barra final de otras rutas se
conserva. Solo sirve para comparar: la descarga sigue usando la URL de `UrlGuard::validate()`. Aún no la usa ninguna
pantalla; la lista de parámetros es provisional.

## Identidad visual (fase 9)

- Estilo suizo: fondo blanco, retícula limpia, filetes finos y mucho espacio en blanco. Paleta corporativa
  negro `#000000` (texto), rojo `#e30613` (títulos de apartado, botón principal, fallos y avisos de riesgo)
  y gris `#6c6c6c` (texto secundario, líneas). Tokens en `:root` de `assets/css/app.css`.
- Poppins Regular, Medium, SemiBold y Bold servidas en local desde `assets/fonts/` (SIL Open Font License 1.1,
  texto en `assets/fonts/OFL.txt`; archivos de github.com/google/fonts). Sin CDN.
- Logotipo: `assets/img/logo-proconsi.webp`, copia sin modificar del archivo facilitado (WebP con
  transparencia, 1791 × 611). En pantalla, abajo a la izquierda del pie, con un filete gris encima.
- Impresión A4 («Imprimir o guardar como PDF»): cabecera discreta en gris, logotipo de unos 90 pt abajo a la
  izquierda de cada página con filete gris y «Página n de N». Usa las cajas de margen de `@page`
  (verificado con Edge 156 en modo sin interfaz); un navegador sin soporte imprime el informe sin esos elementos.
- Estados sin depender solo del color: Fallo en bloque rojo, Aviso con contorno rojo, «Sin errores
  detectables automáticamente» con contorno gris y Sin datos sobre fondo gris.
- Contraste comprobado en `tests/ui_test.php`: rojo sobre blanco 4,88:1, blanco sobre rojo 4,88:1, rojo
  sobre el gris de superficie `#f7f7f7` 4,56:1 y gris `#6c6c6c` sobre blanco 5,25:1. El rojo sobre fondos
  rojizos claros no llega a 4,5:1, por eso no se usan.

## Comprobaciones adicionales (fase 8)

- `C16 Enlaces rotos` (gravedad media): los 20 primeros enlaces http(s) distintos, con el mismo `Fetcher`
  (UrlGuard, IP fijada, redirecciones revalidadas), 5 s por enlace, 12 s en total y 64 KB por respuesta.
  4xx (salvo 401/403/407/429), dominio inexistente o URL mal formada → roto; 5xx, tiempo agotado o error
  de conexión → aviso; bloqueos y destinos no públicos no cuentan.
- `C18 Sitemap` (media): sitemaps de robots.txt o, si no hay, `/sitemap.xml`; índices, texto y gzip; máximo
  5 archivos y 8 s. No figura → fallo; figura con otra variante o lectura parcial → aviso; bloqueo → sin
  datos; noindex intencionado → no aplica.
- `C20 Certificado y contenido mixto` (alta): el certificado se da por válido porque cURL lo verifica al
  descargar (si no, no hay informe); aviso si caduca en menos de 14 días. Contenido mixto activo (script,
  hoja de estilos, iframe, object, embed, base, formularios) → fallo, salvo `upgrade-insecure-requests`
  (aviso); pasivo (imágenes, audio, vídeo, iconos) → aviso. Página por http → no aplica (lo penaliza C01).
- Supuestos pendientes de validar: gravedades, impacto y esfuerzo, y reparto en `config/weights.php`
  (C16 .7/.3/0, C18 .6/.4/0, C20 .7/.3/0). Límites configurables en `config.example.php`.
- C16 y C18 descargan recursos mediante `$ctx->fetch` (inyectable: las pruebas no usan red). El análisis
  puede tardar hasta unos 35 s en el peor caso (`set_time_limit(50)` en `api/analyze.php`).

## Informe (fase 7)

Nueve apartados, con índice enlazado: 1 resumen ejecutivo, 2 puntuaciones por área y global, 3 problemas
críticos, 4 hallazgos detallados con evidencia (solo lo medido), 5 recomendaciones priorizadas, 6 plan de
acción (matriz impacto × esfuerzo), 7 glosario, 8 limitaciones (texto fijo de `index.php`, siempre visible)
y 9 evidencias del análisis (fecha, URL solicitada y final, redirecciones, respuesta, IP, tiempos,
robots.txt, agente de usuario y datos de PageSpeed).

- `src/Report.php` deriva resumen, críticos, prioridades y matriz de los registros y la puntuación. Lo
  devuelven `api/analyze.php` y `api/score.php` (este lo reconstruye con `BaseCheck::describe()`: textos,
  gravedad, impacto y esfuerzo salen siempre del servidor).
- Supuestos: «problemas críticos» = fallos de gravedad crítica; prioridad = fallos por penalización
  w × P (un fallo alto repetido puede adelantar a uno crítico) y después avisos por gravedad; en la matriz,
  impacto alto = ALTO y esfuerzo bajo = BAJO (MEDIO cuenta como impacto bajo y esfuerzo alto).
- Impresión: botón «Imprimir o guardar como PDF» y hoja `@media print` (oculta formulario e índice, abre
  todos los desplegables antes de imprimir y los restaura después).

## PageSpeed Insights (fase 6)

- `C08 Contraste de color`: auditoría `color-contrast` de Lighthouse. Fallo con k = nº de elementos; la
  evidencia muestra selector, extracto y relación de contraste. No evalúa texto sobre imágenes ni degradados
  (revisión manual). Auditoría «no aplicable» → no aplica; error o ausencia → «Sin datos».
- `C14 Core Web Vitals`: datos de campo (CrUX, p75) **de la propia URL** en estrategia móvil. Si solo hay
  datos del dominio (`origin_fallback`) o ninguno → «Sin datos» (no se usan datos de laboratorio).
  Bueno → sin errores; mejorable → aviso; deficiente → fallo. Umbrales de Google: LCP 2,5 s / 4 s,
  INP 200 ms / 500 ms, CLS 0,1 / 0,25 (bueno ≤, deficiente >). Si falta una métrica se evalúa con las demás.
- `POST api/psi.php` (`{"url": "..."}`): consulta PageSpeed y devuelve los registros de C08 y C14; si PageSpeed
  falla responde 200 con ambos en «Sin datos» y el motivo. Tiene su propio contador de límite de uso.
- `POST api/score.php` (`{"checks": [{"id","estado","ocurrencias","aplica"}]}`): recalcula la puntuación con el
  mismo `Scorer`; la gravedad y el reparto se toman del servidor, no de la petición.
- La pantalla pide `analyze.php` y `psi.php` en paralelo, muestra primero la puntuación provisional y la
  actualiza al llegar PageSpeed sin mover el foco.
- **Campos pedidos**: `fields` no admite seleccionar una auditoría suelta (`lighthouseResult/audits/color-contrast`
  devuelve HTTP 400), así que se piden todas las auditorías. En una prueba con proconsi.com (una sola medición)
  la respuesta ocupó unos 420 KB y tardó unos 23 s.
- **Errores**: cuota (429), clave (400/403), consulta rechazada (4xx), fallo de Lighthouse (5xx), JSON no válido,
  tiempo agotado, red y respuesta de más de 5 MB dejan C08 y C14 en «Sin datos» con el motivo en español; el
  mensaje original de Google se añade entre comillas y la clave se sustituye por «[clave]» si apareciera.
- **Clave obligatoria**: sin `psi_api_key` en `config/config.php` la API responde 429 (cuota 0) y C08 y C14
  quedan siempre en «Sin datos». La clave se crea en Google Cloud (API «PageSpeed Insights API»).
- **Tiempo de espera**: Lighthouse tarda entre 15 y 60 s. `psi_timeout` (75 s) debe caber en los límites del
  servidor: con PHP-FPM detrás de Apache/Nginx, `ProxyTimeout`/`fastcgi_read_timeout` (60 s por defecto)
  cortaría la petición antes.

## Pantalla y API (fase 5)

- `index.php`: formulario, indicador de carga, resultados y errores. Mobile first, sin librerías ni CDN,
  con CSP estricta. Los resultados se pintan con `textContent` (nunca `innerHTML`).
- `POST api/analyze.php` con JSON `{"url": "...", "noindex_intentional": false}`. Respuestas:
  `200` con comprobaciones y puntuación; `400` URL no válida o no pública; `405`/`415` método o formato;
  `403` origen distinto; `429` límite de uso (con `Retry-After`); `502`/`504` errores al descargar la web.
- Límite de uso por IP en `storage/ratelimit/` (hash de la IP y marcas de tiempo; ningún dato del análisis).
  Se usa `REMOTE_ADDR`: si el servidor está detrás de un proxy inverso, todas las peticiones contarían
  como una sola IP (pendiente de saber cómo es el servidor de la empresa).
- Prueba rápida sin Apache: `php -S 127.0.0.1:8799 -t .` y abrir `http://127.0.0.1:8799/`.

## Puntuación (fase 4)

`Scorer` aplica la fórmula de `CLAUDE.md`: cada fallo (`NO_CUMPLE`) resta `w × P` una sola vez,
repartido entre áreas según `config/weights.php`, con `w = 1 + ln(k)`. Devuelve por área la nota, el
desglose de descuentos y si está incompleta; la nota global; la bandera «Riesgo alto: revisar de
inmediato» con sus motivos; y las listas de comprobaciones sin datos y que no aplican.
Supuesto: la nota global se marca incompleta si lo está alguna de sus áreas.

## Estructura

```
index.php            Pantalla (fase 5)
api/                 analyze.php, psi.php y score.php (fases 5-6)
src/                 UrlGuard, Fetcher, CurlTransport, Analyzer, CheckContext, CheckRunner, BaseCheck,
                     DomHelper, RobotsTxt, Config, Lang, RateLimiter, Scorer, PsiClient, Api, Report
src/Checks/          Una clase por comprobación (C01HttpsStatus … C15MetaDescription, C08 y C14 vía PageSpeed,
                     C16BrokenLinks, C18Sitemap y C20HttpsSecurity)
config/              config.example.php (plantilla), config.php (local, fuera de git), weights.php
lang/es.php          Nombres, recomendaciones y glosario (borrador para revisar)
assets/              CSS y JS propios, fonts/ (Poppins, OFL) e img/ (logotipo)
storage/             Datos de ejecución (límite de uso, temporales, registro de errores); .htaccess deniega el acceso web
bin/limpiar.php      Limpieza periódica de temporales (tarea programada; solo consola)
docs/                SEGURIDAD.md (requisitos de despliegue) y migracion-fiabilidad.sql (opcional)
tests/               run.php y *_test.php, fixtures/ (HTML y JSON de ejemplo), server/, utilidades manuales
```

## Pruebas

- `php tests/run.php`: todas las pruebas, sin internet (incluye un servidor local de PHP en 127.0.0.1 para probar cURL).
  Los endpoints se prueban con `WEBSEO_CONFIG_DIR` apuntando a un directorio temporal que solo tiene
  `config.example.php`: ninguna prueba carga `config/config.php` ni llama a Google.
- `php tests/analyze_cli.php <url> [--json] [--noindex-intencionado]`: análisis completo de una URL real (requiere internet).
- `php tests/live_fetch.php [url ...]`: prueba manual del Fetcher contra sitios reales (requiere internet).
- `php tests/check_env.php`: diagnóstico del entorno (versión de PHP, extensiones y certificados).
- Pruebas de las mejoras del motor (todas sin red ni claves): `persistencia_test.php` (escritura atómica,
  reservas, transacciones y `site_record.php` con MySQL caído, sobre SQLite), `errores_test.php` (errores
  internos con referencia), `fiabilidad_test.php` (indicador de fiabilidad), `navegador_test.php` (Chrome:
  resolución fijada, DNS rebinding simulado contra 127.0.0.1 y limpieza) y `sitejs_test.php` (recomendaciones
  agrupadas). Las que necesitan Chrome o Edge se informan como OMITIDAS si no hay navegador: una prueba
  omitida no es una prueba superada.

## Comprobaciones (fase 3)

Cada clase de `src/Checks/` devuelve un registro con `id`, `pilares`, `estado`, `severidad`, `valor_medido`,
`umbral`, `criterio`, `evidencia` (selector CSS, extracto HTML y línea), `recomendacion`, `impacto`,
`esfuerzo` y `requiere_revision_manual`, más `ocurrencias` (k para la puntuación), `aplica` y `detalle`.
Los extractos se guardan sin escapar: **hay que escaparlos con `htmlspecialchars` al imprimirlos**.

Limitaciones conocidas: el HTML se analiza con el parser HTML de libxml (no es HTML5 estricto); no se
evalúa CSS externo (un elemento oculto por clase cuenta como visible); el nombre accesible es una
aproximación del algoritmo AccName; los valores de impacto y esfuerzo son un borrador.

## Seguridad de la descarga (fase 2)

`UrlGuard` valida cada URL (solo http/https, sin credenciales, puertos 80/443, rechazo de IP privadas o
reservadas en IPv4 e IPv6, formas numéricas ambiguas y dominios internos) y `Fetcher` descarga con la
IP validada fijada (`CURLOPT_RESOLVE`), redirecciones manuales revalidadas (máx. 5), presupuesto total
de 10 s y 2 MB de respuesta. Un 403, captcha, 401 o 429 se informa como bloqueo, no como fallo del sitio.

## Requisitos

- PHP 8.0 o superior (el código evita funciones de 8.1+ hasta conocer la versión del servidor).
- Extensiones de PHP. `php tests/check_env.php` las comprueba todas.

  | Extensión | Para qué | ¿Obligatoria? |
  |---|---|---|
  | `curl` | Descargar las páginas, robots.txt, sitemaps y PageSpeed | Sí |
  | `openssl` | HTTPS y datos del certificado (C20) | Sí |
  | `dom` (con `libxml`) | Analizar el HTML | Sí |
  | `mbstring` | Textos en UTF-8 | Sí |
  | `json` | API y almacenamiento temporal (incluida en PHP 8) | Sí |
  | `pdo_mysql` | Historial en MySQL | Solo si se activa el historial |
  | `zlib` | Comprimir el informe guardado en el historial (`gzencode`) | Solo si se activa el historial |
  | `intl` | Dominios con caracteres no ASCII (IDN); sin ella se rechazan | Recomendada |
  | `pdo_sqlite` | Solo para las pruebas del historial (`tests/run.php`) | Solo para pruebas |

- Funciones `proc_open` y `exec` no deshabilitadas (`disable_functions`): las usan las pruebas y, si se
  activan, las pruebas con Chrome.
- Apache con `AllowOverride` activo para que `storage/.htaccess` surta efecto.
- `storage/` escribible por el usuario del servidor web.

## Fiabilidad de las notas, errores internos y limpieza

**Indicador de fiabilidad** (`src/Reliability.php`). Junto a las notas se muestra cuánto de lo que podían
medir se ha medido: estado del análisis (completo, parcial o bloqueado), puntos evaluados sobre posibles,
puntos sin datos y «peso evaluado» (la misma proporción ponderada con lo que cada comprobación puede restar:
P de su gravedad × su reparto por área). **No cambia ninguna nota, peso ni criterio.** El nivel (alta / media /
baja) solo aparece si se definen umbrales en `config.php` (`'fiabilidad' => ['alta' => …, 'media' => …]`);
en `config.example.php` están comentados como PROPUESTA pendiente de validar. En el historial se guarda dentro
del informe y, si se aplica `docs/migracion-fiabilidad.sql` (opcional, a mano), también en tres columnas
para verlo en las listas.

**Errores internos** (`src/ErrorLog.php`). Si una comprobación falla por un error de la herramienta, queda
«Sin datos» (no resta) con un mensaje fijo y una referencia (`E-xxxxxxxx`); si falla un endpoint, la
respuesta lleva esa referencia. El detalle técnico está en `storage/logs/errores.log` (fecha, referencia,
contexto, clase del error y archivo:línea; sin URL analizadas ni claves). Con la referencia que vea la
persona se localiza la línea del registro.

**Persistencia.** La fila del historial y su informe se guardan en una transacción; un análisis de sitio
solo se marca como guardado cuando MySQL lo confirma (si falla, se puede reintentar); los datos temporales
de `storage/runs/` se escriben de forma atómica.

**Limpieza programada** (`bin/limpiar.php`, solo consola). Borra temporales caducados, contadores del
límite de uso, perfiles de Chrome abandonados, registros de errores de más de 90 días y filas del historial
caducadas. La herramienta también limpia al recibir peticiones, pero conviene programarla para que no
dependa de ello:

- Windows (Programador de tareas, cada 30 minutos):
  `schtasks /Create /SC MINUTE /MO 30 /TN "RadarWeb limpiar" /TR "C:\wamp64\bin\php\php8.x\php.exe C:\ruta\v3.0\bin\limpiar.php"`
- Linux (cron): `*/30 * * * * php /ruta/v3.0/bin/limpiar.php`

Ajustar las rutas. La tarea debe ejecutarse con el mismo usuario que el servidor web.

**Seguridad y despliegue:** ver `docs/SEGURIDAD.md` (qué resuelve el código, qué exige infraestructura y los
requisitos para activar las pruebas con Chrome).

## Ejecutar en WAMP (Windows)

1. **Ubicación.** El proyecto puede estar fuera de `www`. Opciones: copiarlo a `C:\wamp64\www\<nombre>` o crear un alias/VirtualHost apuntando a esta carpeta.
2. **Extensiones.** Menú de WAMP → PHP → Extensiones PHP: marcar `curl`, `openssl`, `mbstring` (DOM suele venir activa) y, para el historial, `pdo_mysql` (`zlib` viene incluida en WAMP); para las pruebas, `pdo_sqlite`. Recomendada: `intl`.
3. **Certificados HTTPS (obligatorio).** Sin `cacert.pem`, cURL no valida los HTTPS y la herramienta no podrá analizar ninguna web:
   - Descargar `cacert.pem` desde https://curl.se/ca/cacert.pem y guardarlo, p. ej., en `C:\wamp64\bin\php\cacert.pem`.
   - En el `php.ini` que use Apache (WAMP → PHP → `php.ini`) **y** en el de CLI si se prueba por consola: `curl.cainfo = "C:\wamp64\bin\php\cacert.pem"` y `openssl.cafile = "C:\wamp64\bin\php\cacert.pem"`.
   - Reiniciar los servicios de WAMP.
4. **Configuración.** Copiar `config/config.example.php` a `config/config.php` y completar la clave de PageSpeed Insights.
5. **Verificar.** Por consola: `php tests/check_env.php`. En navegador: `http://localhost/<ruta>/tests/check_env.php`. Todas las filas deben estar en OK.

## Despliegue en el servidor de la empresa

1. Subir todo salvo `config/config.php` y el contenido de `storage/` (ya excluidos por `.gitignore`).
2. Crear `config/config.php` en el servidor a partir de la plantilla.
3. Asegurar que `storage/` es escribible por el usuario del servidor web y que `storage/.htaccess` se respeta (o equivalente en Nginx: denegar `/storage/`).
4. Ejecutar `tests/check_env.php` y después **eliminarlo o restringirlo** del servidor de producción.
5. Confirmar la versión de PHP (pendiente) y el `cacert.pem`.

## Pendiente de confirmar

- Clave de PageSpeed Insights.
- Versión de PHP del servidor de la empresa.
- Lista definitiva de bots de IA (la de `config.example.php` es provisional y su clasificación entre «búsqueda» y «entrenamiento» es una suposición).
- Normas visuales de Proconsi (fase 9).
