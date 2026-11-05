# Analizador SEO / IA / Accesibilidad (Proconsi)

Herramienta web interna en PHP (sin framework ni Composer) que analiza una URL y genera un
informe de SEO, preparación para IA y accesibilidad. La evaluación es automática y parcial:
no sustituye una auditoría manual ni certifica el cumplimiento de WCAG ni de ninguna ley.

Estado: **fase 4** (comprobaciones y puntuación). Las fases están descritas en `CLAUDE.md`.

## Puntuación (fase 4)

`Scorer` aplica la fórmula de `CLAUDE.md`: cada fallo (`NO_CUMPLE`) resta `w × P` una sola vez,
repartido entre áreas según `config/weights.php`, con `w = 1 + ln(k)`. Devuelve por área la nota, el
desglose de descuentos y si está incompleta; la nota global; la bandera «Riesgo alto: revisar de
inmediato» con sus motivos; y las listas de comprobaciones sin datos y que no aplican.
Supuesto: la nota global se marca incompleta si lo está alguna de sus áreas.

## Estructura

```
index.php            Pantalla (fase 5)
api/                 analyze.php y psi.php (fases 5-6)
src/                 UrlGuard, Fetcher, CurlTransport, Analyzer, CheckContext, CheckRunner, BaseCheck,
                     DomHelper, RobotsTxt, Config, Lang (pendientes: RateLimiter, Scorer, Report)
src/Checks/          Una clase por comprobación (C01HttpsStatus … C15MetaDescription)
config/              config.example.php (plantilla), config.php (local, fuera de git), weights.php
lang/es.php          Nombres y recomendaciones de las comprobaciones (borrador para revisar)
assets/              CSS y JS propios
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
