# Analizador SEO / IA / Accesibilidad (Proconsi)

Herramienta web interna en PHP (sin framework ni Composer) que analiza una URL y genera un
informe de SEO, preparación para IA y accesibilidad. La evaluación es automática y parcial:
no sustituye una auditoría manual ni certifica el cumplimiento de WCAG ni de ninguna ley.

Estado: **fase 9** (identidad visual de Proconsi). Las fases están descritas en `CLAUDE.md`.

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
  sin guardar nada en el servidor. CSV con «;», UTF-8 con BOM y protección frente a fórmulas (apóstrofo ante `=`, `+`,
  `-`, `@`, tabulador o retorno). Se prueba en Edge/Chrome sin interfaz (`tests/js/`); sin navegador queda OMITIDA.
- **09 Fragmento de corrección** (`src/FixSnippets.php`, plantillas en `lang/es.php` → `correcciones`): en las
  recomendaciones, «antes / después» para C04, C06, C07, C09, C10, C12, C15 y C20 (máx. 3 casos). «Antes» es la
  etiqueta real de la evidencia; si está recortada o el fallo no es sencillo, no se muestra nada. Primer borrador.
- **10 Comparar dos URLs** (`assets/js/compare.js`, modo «Comparar dos URLs» del formulario): el navegador lanza un
  análisis independiente por URL (una URL por petición; cuenta como dos en el límite de uso) con PageSpeed para ambas
  y muestra notas por área y global con su diferencia, recuento de estados y C01–C20 lado a lado (distintas / iguales).

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
storage/             Datos de ejecución (límite de uso); .htaccess deniega el acceso web
tests/               run.php y *_test.php, fixtures/ (HTML y JSON de ejemplo), server/, utilidades manuales
```

## Pruebas

- `php tests/run.php`: todas las pruebas, sin internet (incluye un servidor local de PHP en 127.0.0.1 para probar cURL).
- `php tests/analyze_cli.php <url> [--json] [--noindex-intencionado]`: análisis completo de una URL real (requiere internet).
- `php tests/live_fetch.php [url ...]`: prueba manual del Fetcher contra sitios reales (requiere internet).
- `php tests/check_env.php`: diagnóstico del entorno.

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
- Extensiones: cURL, OpenSSL, DOM, mbstring.
- Apache con `AllowOverride` activo para que `storage/.htaccess` surta efecto.

## Ejecutar en WAMP (Windows)

1. **Ubicación.** El proyecto puede estar fuera de `www`. Opciones: copiarlo a `C:\wamp64\www\<nombre>` o crear un alias/VirtualHost apuntando a esta carpeta.
2. **Extensiones.** Menú de WAMP → PHP → Extensiones PHP: marcar `curl`, `openssl`, `mbstring` (DOM suele venir activa).
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
