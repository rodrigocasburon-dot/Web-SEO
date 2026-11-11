# Seguridad de Radar web (v5.0)

Auditoría automatizada del 11/11/2026 sobre la rama `revision-seguridad` (basada en `feature/v5.0`, carpeta
`v5.0`). Copia de seguridad previa: rama `respaldo-antes-de-seguridad`. Las líneas citadas como «antes» se
refieren al commit `fe969e0` (v5.0 anterior a la auditoría).

> Esta revisión la ha hecho una herramienta automática (Claude Code) con pruebas en local. **No sustituye a
> una revisión de un especialista en seguridad** ni a una prueba de intrusión sobre el servidor real.

## 1. Resumen para no técnicos

**Qué es Radar web.** Una página interna donde se escribe la dirección de una web y la herramienta la
descarga y la analiza. Eso significa que *nuestro servidor visita webs que elige quien usa la herramienta*.
El mayor riesgo de este tipo de programas es que alguien la use para que el servidor visite sitios internos
de Proconsi (por ejemplo, la base de datos) en lugar de webs públicas.

**Qué está bien.** Esa defensa principal es sólida: se han probado en local más de 30 trucos conocidos
para colar direcciones internas y todos se bloquean. Tampoco se ha conseguido ejecutar código malicioso en
el navegador de quien lee un informe, ni lanzar acciones desde otra web (CSRF).

**Qué se ha encontrado.** 15 hallazgos: ninguno crítico, 3 altos, 5 medios y 7 bajos.

- Se han **corregido 8**, cada uno con su commit y una prueba que demuestra que el ataque ya no funciona.
  Entre ellos: el límite de páginas del análisis de sitio se podía saltar (el servidor de una web ajena
  podía recibir miles de visitas nuestras); las pruebas internas se podían lanzar desde el navegador; y
  Apache dejaba ver el código, la documentación y los listados de carpetas.
- **Quedan 7 pendientes**, que necesitan una decisión o un cambio en el servidor, no en el código:
  - el historial lo puede ver cualquiera que llegue a la herramienta;
  - el zip de la v4.0 que circuló por WhatsApp incluía los archivos de claves;
  - no hay un tope global de consultas a PageSpeed;
  - otros detalles menores.

**Qué hay que hacer antes de publicarla en el servidor de la empresa:**

1. Servirla solo en la red interna o por VPN, y siempre por HTTPS.
2. Cambiar (rotar) la clave de Google PageSpeed y las claves de `storage/`, porque salieron en el zip.
3. Seguir la lista `docs/CHECKLIST_DESPLIEGUE_PROCONSI.md`.

## 2. Hallazgos por gravedad

| ID | Gravedad | Hallazgo | Estado |
|---|---|---|---|
| SEG-01 | Alta | El tope de páginas y el modo del análisis de sitio solo los controlaba el navegador | Corregido (1957e5a) |
| SEG-02 | Alta | Las pruebas (`tests/run.php`) se podían ejecutar desde la web | Corregido (7a78cd8) |
| SEG-14 | Alta | El zip de la v4.0 enviado por WhatsApp contenía archivos de claves | Pendiente: rotar claves |
| SEG-03 | Media | Listados de carpetas y archivos internos accesibles por web | Corregido (f7e09bd) |
| SEG-04 | Media | Los errores de PHP mostraban rutas del servidor | Corregido (47636be) |
| SEG-05 | Media | El mensaje de rechazo revelaba la IP interna de un dominio | Corregido (3f0f14b) |
| SEG-06 | Media | Sin tope global de consultas a PageSpeed Insights | Pendiente: decisión |
| SEG-07 | Media | Historial sin control de acceso y con identificadores correlativos | Pendiente: decisión de despliegue |
| SEG-08 | Baja | Las respuestas anunciaban la versión de PHP (y Apache la suya) | Corregido en código (f4e2dc6); Apache en el servidor |
| SEG-09 | Baja | Faltaban cabeceras de protección (marcos, permisos, caché del historial) | Corregido (f4e2dc6) |
| SEG-12 | Baja | Sin límite al tamaño de las cabeceras recibidas; protocolos de redirección sin fijar | Corregido (3594001) |
| SEG-10 | Baja | Límite por IP: rotación de IPv6 y oficinas que comparten IP | Pendiente: decisión |
| SEG-11 | Baja | La comprobación de origen ignora el puerto y el esquema | Pendiente (riesgo bajo) |
| SEG-13 | Baja | El informe carga imágenes de la web analizada (le revela la IP de quien lo lee) | Pendiente: decisión |
| SEG-15 | Baja | PHP 8.0 de pruebas sin soporte; el servidor debe usar PHP ≥ 8.2 | Pendiente: servidor |

Pruebas: `php tests/seguridad.php` (15 pruebas, sin internet). Sobre la versión anterior fallan 6 y ahora
pasan todas. La batería completa (`php tests/run.php`) pasa: 365 correctas, 0 fallidas y 1 omitida (necesita
un navegador Chrome concreto).

## 3. Detalle de cada hallazgo

### SEG-01 · El tope del análisis de sitio se podía saltar — Alta — Corregido

- **Dónde (antes):** `api/site_page.php:83` (firmaba enlaces nuevos si el navegador enviaba
  `"descubrir": true`); el tope y el modo solo existían en `assets/js/site.js`.
- **Qué significa:** quien quisiera podía pedir a nuestro servidor que recorriera una web ajena sin límite y
  repetir la misma página una y otra vez. Así nuestro servidor sirve para saturar webs de terceros, y la IP
  de Proconsi es la que aparece en sus registros.
- **Cómo se reproducía en local:** con un análisis rápido, llamar a `api/site_page.php` con
  `"descubrir": true` devolvía 20 enlaces nuevos firmados por página, y la misma página firmada se podía
  reenviar indefinidamente.
- **Corrección:** `src/SiteRun.php` guarda en el servidor el modo, el tope, las páginas analizadas y
  firmadas, y el ritmo. Respeta `Crawl-delay` (máx. 10 s) con un mínimo de 250 ms entre páginas, y se
  detiene si el sitio responde 429 o 503.
  - **Ahora:** 0 enlaces nuevos en el modo rápido, `LIMIT_REACHED` al pasar el tope y 403 con un análisis
    inventado.
  - **Prueba:** `tests/siterun_test.php` y `tests/seguridad.php`.
- **OWASP 2021:** A04 Diseño inseguro.

### SEG-02 · Las pruebas se ejecutaban desde la web — Alta — Corregido

- **Dónde (antes):** `tests/run.php:1` (sin comprobación de consola), `tests/live_fetch.php` y
  `tests/analyze_cli.php`. No había `tests/.htaccess`.
- **Qué significa:** cualquiera que abriera `…/tests/run.php` lanzaba en el servidor la batería completa
  (cientos de pruebas, servidores auxiliares y un Chrome sin ventana). Repetido, deja el servidor sin
  recursos. `live_fetch.php` hacía además peticiones salientes.
- **Cómo se reproducía en local:**
  - en la copia de WAMP de la v4.0, la carpeta `tests/` se listaba y sus scripts se servían (`run.php` no se
    lanzó allí para no arrancar navegadores);
  - al pasar `tests/seguridad.php` sobre la versión anterior, en el servidor integrado de PHP, `run.php`
    respondió 200 y ejecutó la batería entera por web (prueba «Despliegue»).
- **Corrección:** los tres scripts responden 404 fuera de la consola, y `tests/.htaccess` niega el acceso
  en Apache (comprobado en WAMP: 403).
- **OWASP 2021:** A05 Configuración de seguridad incorrecta.

### SEG-14 · Claves en el zip de la v4.0 — Alta — Pendiente (fuera del repositorio)

- **Dónde:** el archivo `v4.0.zip` recibido por WhatsApp el 11/11/2026 incluía:
  - `config/config.php`, que no se ha abierto, de modo que no se sabe si tenía la clave real de PageSpeed
    o la contraseña de la base de datos;
  - `storage/historial.key`;
  - `storage/site_secret`.

  En el repositorio no hay secretos: `config.php` nunca se ha subido a Git y no aparece ninguna clave de
  Google en el historial.
- **Qué significa:** quien tenga el zip puede usar la cuota de PageSpeed de Proconsi y, si esas claves de
  `storage/` llegaran a usarse en el servidor, falsificar firmas de enlaces del historial o del análisis de
  sitio.
- **Reproducción:** no aplica (es un archivo distribuido, no un fallo del código).
- **Corrección propuesta:**
  1. Crear una clave nueva de PageSpeed en Google Cloud, restringida a la API de PageSpeed Insights y a la
     IP del servidor.
  2. Borrar la antigua.
  3. Escribir la nueva a mano en `config/config.php` del servidor.
  4. Cambiar la contraseña de MySQL si estaba en ese archivo.
  5. No copiar `storage/` al servidor: la herramienta genera sus claves al arrancar.
  6. No enviar zips con `config/` ni `storage/`.
- **OWASP 2021:** A02 Fallos criptográficos (exposición de secretos).

### SEG-03 · Listados de carpetas y archivos internos por web — Media — Corregido

- **Dónde (antes):** no existían `.htaccess` en la raíz ni en `config/`, `src/`, `lang/`, `bin/`, `docs/` y
  `certs/`. Solo había uno en `storage/`.
- **Qué significa:**
  - Apache mostraba la lista de archivos de esas carpetas;
  - servía como texto `README.md`, `CLAUDE.md`, `install.sql`, `.gitignore`, esta documentación y
    `certs/cacert.pem`;
  - los `.php` internos se ejecutaban sueltos, aunque sin mostrar su código.

  No revelaba claves, pero sí da a un atacante el mapa de la aplicación.
- **Cómo se reproducía en local:** en WAMP, `GET /config/`, `/src/` y `/docs/` respondían 200 con el
  listado. `GET /install.sql` y `/README.md` también respondían 200.
- **Corrección:**
  - un `.htaccess` con `Require all denied` en cada carpeta interna;
  - en la raíz, `Options -Indexes` y la denegación de archivos ocultos y de `.md`, `.sql` y `.pem`.

  Comprobado en WAMP sobre una copia temporal: las carpetas y archivos internos devuelven 403, mientras que
  `index.php`, `historial.php`, `requisitos.php`, `api/` y `assets/` siguen funcionando.
- **Limitación:** solo vale para Apache con `AllowOverride` activo. En Nginx o IIS hay que repetirlo en la
  configuración del servidor (ver la lista de despliegue).
- **OWASP 2021:** A05 Configuración de seguridad incorrecta.

### SEG-04 · Errores con rutas del servidor — Media — Corregido

- **Dónde (antes):** `historial.php:27` convertía `$_GET['q']` a texto sin comprobar que lo fuera. Además,
  `src/bootstrap.php` no desactivaba `display_errors` en las páginas (las API sí lo hacían).
- **Qué significa:** con `historial.php?q[]=x` la página mostraba un aviso de PHP con la ruta completa del
  archivo en el servidor, una pista útil para un atacante.
- **Cómo se reproducía en local:** en WAMP, la respuesta incluía el aviso de PHP con la ruta del archivo y
  el número de línea 27.
- **Corrección:** `bootstrap.php` desactiva `display_errors` fuera de la consola (los errores van al
  registro de PHP), e `historial.php` solo acepta `q` como texto.
  - **Prueba:** `tests/seguridad.php`, «XSS y fugas».
- **OWASP 2021:** A05 Configuración de seguridad incorrecta.

### SEG-05 · El rechazo revelaba la IP interna — Media — Corregido

- **Dónde (antes):** `src/UrlGuard.php:63`. El mensaje decía «… (host → IP)».
- **Qué significa:** registrando un dominio que apunte a una IP interna, el mensaje confirmaba qué IP tenía.
  Sirve para explorar la red interna desde fuera.
- **Cómo se reproducía:** era una prueba con DNS simulado (`interno.example.com` → `10.0.0.5`): el mensaje
  incluía `10.0.0.5`.
- **Corrección:** el mensaje solo nombra el dominio. Prueba nueva en `tests/urlguard_test.php`.
- **OWASP 2021:** A04 Diseño inseguro (CWE-209, información en mensajes de error).

### SEG-06 · Sin tope global de PageSpeed Insights — Media — Pendiente (decisión)

- **Dónde:** `api/psi.php:18`. Solo hay un límite por IP (10 cada 10 minutos, según
  `config.example.php`).
- **Qué significa:** varias personas o varias IP pueden agotar la cuota diaria de la clave de Google, y
  entonces el contraste (C08) y los Core Web Vitals (C14) quedan SIN_DATOS para todos. No tiene coste
  económico conocido (la API es gratuita con cuota), pero afecta a la disponibilidad.
- **Reproducción:** no se ha probado contra Google (está fuera de alcance). Se ha comprobado leyendo el
  código que no hay un contador global.
- **Propuesta:** un contador diario global en `storage/` con un tope configurable (por ejemplo, el 80 % de
  la cuota contratada). **Hace falta decidir la cifra.**
- **OWASP 2021:** A04 Diseño inseguro.

### SEG-07 · Historial sin control de acceso — Media — Pendiente (decisión de despliegue)

- **Dónde:** `historial.php:40` y `api/history_report.php:17`. Los identificadores son correlativos.
- **Qué significa:** quien llegue a la herramienta ve todas las webs analizadas, sus notas y los informes
  guardados, y puede recorrerlos uno a uno. Es coherente con la decisión «sin cuentas ni login», pero
  obliga a limitar quién llega a la herramienta.
- **RGPD:** se guardan URL, fechas, notas e informes (con extractos de las webs), sin IP de los usuarios,
  durante 12 meses y luego se borran solos. Las URL podrían contener datos personales si alguien analiza
  direcciones con parámetros. Conviene que lo valore quien lleve protección de datos.
- **Reproducción:** no se ha podido probar con base de datos real (no hay base de datos de prueba). Se ha
  revisado el código: las consultas SQL usan sentencias preparadas.
- **Propuesta:** restringir la herramienta completa a la red interna o la VPN, o proteger el directorio con
  la autenticación de Apache. No requiere cambiar el código.
- **OWASP 2021:** A01 Control de acceso roto.

### SEG-08 · Versiones de PHP y Apache en las respuestas — Baja — Corregido en código

- **Dónde (antes):** todas las respuestas llevaban `X-Powered-By: PHP/8.3.28`. Apache envía
  `Server: Apache/2.4.65 (Win64) PHP/8.3.28 …`.
- **Qué significa:** le dice a un atacante qué versiones exactas buscar en las listas de vulnerabilidades.
- **Corrección:** `bootstrap.php` quita `X-Powered-By`. La cabecera `Server` solo se puede reducir en el
  servidor: con `ServerTokens Prod` y `ServerSignature Off`, y con `expose_php = Off` en `php.ini` (ver la
  lista de despliegue).
- **OWASP 2021:** A05 Configuración de seguridad incorrecta.

### SEG-09 · Cabeceras de protección incompletas — Baja — Corregido

- **Dónde (antes):** `index.php:13-16`, `historial.php:13-16` y `requisitos.php:13-16`. Ya había CSP con
  `frame-ancestors 'none'`, `nosniff` y `Referrer-Policy`.
- **Qué faltaba:**
  - `X-Frame-Options` para navegadores antiguos;
  - `Permissions-Policy`, para que la página no pueda pedir cámara, micrófono o ubicación;
  - `Cache-Control: no-store` en el historial.
- **Corrección:** comprobado en WAMP. Prueba en `tests/seguridad.php`.
- **OWASP 2021:** A05 Configuración de seguridad incorrecta.

### SEG-12 · Cabeceras recibidas sin límite — Baja — Corregido

- **Dónde (antes):** `src/CurlTransport.php:57`. Además, en `src/CurlTransport.php:41` no se fijaba
  `CURLOPT_REDIR_PROTOCOLS`.
- **Qué significa:** la libcurl 7.76 del PHP 8.0 no limita el total de cabeceras. Una web hostil podía
  enviar cientos de KB de cabeceras durante los 10 s de espera y llenar memoria del servidor. Lo de los
  protocolos no era explotable, porque cURL no sigue redirecciones (lo hace `Fetcher`, revalidando cada
  salto).
- **Cómo se reproducía en local:** una ruta de prueba con 800 KB de cabeceras se descargaba entera.
- **Corrección:** la descarga se corta a partir de 256 KB de cabeceras, y los protocolos de redirección
  quedan fijados a HTTP y HTTPS. Prueba en `tests/curltransport_test.php`.
- **OWASP 2021:** A04 Diseño inseguro (consumo de recursos) y A10 SSRF (endurecimiento).

### SEG-10 · Límite por IP: IPv6 y NAT — Baja — Pendiente (decisión)

- **Dónde:** `src/Api.php` (`rateLimit`). Usa `REMOTE_ADDR` exacto.
- **Qué significa:**
  - con IPv6, una persona puede tener millones de direcciones (un bloque /64) y saltarse el límite;
  - al revés, toda una oficina detrás de una misma IP comparte el límite.

  Falsear `X-Forwarded-For`, `Client-IP` o `X-Real-IP` **no** sirve: comprobado.
- **Propuesta:** agrupar las IPv6 por /64. Si la herramienta queda solo en la red interna, el riesgo es
  mínimo; si se pone detrás de un proxy inverso, configurar su IP de confianza.
- **OWASP 2021:** A04 Diseño inseguro.

### SEG-11 · Comprobación de origen sin puerto ni esquema — Baja — Pendiente

- **Dónde:** `src/Api.php:81-83`. Solo compara el nombre del host.
- **Qué significa:** una página en el mismo servidor pero en otro puerto (por ejemplo, otra aplicación en
  `:8080`) pasaría la comprobación.
  - **Mitigación ya existente:** la API exige `Content-Type: application/json`, lo que obliga al navegador
    a pedir permiso previo (preflight CORS), y la API no responde a ese permiso.
  - **Riesgo práctico:** muy bajo.
- **Propuesta:** comparar esquema, host y puerto. Es un cambio pequeño, pero conviene probarlo en el
  servidor real (proxy y HTTPS) antes de aplicarlo.
- **OWASP 2021:** A01 Control de acceso roto (CSRF).

### SEG-13 · Imágenes de la web analizada en el informe — Baja — Pendiente (decisión)

- **Dónde:** `assets/js/app.js:1676-1677` (logotipo e imágenes de identidad) y `index.php:14`
  (`img-src … https:`).
- **Qué significa:** al ver un informe, el navegador del empleado descarga el logotipo o la imagen social
  directamente de la web analizada, que puede saber qué IP lo ha visto y cuándo. No permite ejecutar
  código: la CSP y el filtro solo admiten `https:`, y ya se envía `referrerpolicy="no-referrer"`.
- **Propuesta:** mostrar solo la URL como texto o servir las imágenes a través del servidor. Es una
  decisión de producto.
- **OWASP 2021:** A04 Diseño inseguro (privacidad).

### SEG-15 · Versión de PHP — Baja — Pendiente (servidor)

- **Dónde:** el PHP 8.0.30 usado para las pruebas por consola no tiene soporte de seguridad desde el
  26/11/2023. Apache en WAMP usa 8.3.28.
- **Propuesta:** en el servidor de la empresa, PHP 8.2 o superior con soporte. El código es compatible con
  PHP 8.0 o superior.
- **OWASP 2021:** A06 Componentes vulnerables y obsoletos.

### Observaciones (no son fallos)

- `htmlspecialchars` se usa sin `ENT_SUBSTITUTE`: un texto con UTF-8 inválido sale vacío, sin riesgo de XSS.
- `assets/js/rw-componentes.js:256` acepta cualquier `href` en la tabla matriz. Hoy no se usa con datos de
  webs analizadas. Si se usa en el futuro, conviene filtrar a `http(s)`.
- Resultado del XSS en el navegador (arnés local con una web hostil):
  - no se ejecutó ningún script ni se abrieron alertas;
  - no aparecieron enlaces `javascript:`;
  - el texto hostil se mostró como texto.

  El JavaScript del informe no usa `innerHTML` ni equivalentes.
- La exportación CSV neutraliza fórmulas (`= + - @`, tabulador y retorno de carro). El PDF escapa
  `\ ( )` y quita caracteres de control. Ambas cosas se han revisado leyendo el código.
- XXE en `sitemap.xml`: la entidad externa no se expande (comprobado con una web hostil local).

## 4. Lo que no se ha podido verificar

- **El servidor real de Proconsi:** versión de PHP y Apache, `AllowOverride`, HTTPS, cortafuegos de salida
  y si la herramienta será accesible desde Internet. Todo lo probado es la instancia local (WAMP y el
  servidor integrado de PHP).
- **Permisos del usuario de MySQL:** no se ha leído `config/config.php` (norma de la auditoría).
- **Historial con base de datos real:** no hay base de datos de prueba. Se ha revisado el SQL (sentencias
  preparadas), pero no se ha probado en ejecución.
- **ReDoS:** no hay una revisión exhaustiva de las expresiones regulares. PCRE aplica límites de
  retroceso, de modo que el peor caso es un análisis fallido, no un bloqueo indefinido. **Sospecha no
  confirmada.**
- **Exportaciones CSV y PDF:** solo revisión del código (no hay Node para ejecutarlas fuera del navegador).
- **Pruebas con Chrome (`chrome.activo`):** están desactivadas por defecto y no se han atacado
  dinámicamente. Las medidas descritas en el anexo se han revisado leyendo el código.
- **El contenido de `config/config.php`** del zip de la v4.0 y del equipo local: no se ha abierto.
- **Dependencias del sistema** (OpenSSL, libcurl y Chrome del servidor): no se han inventariado.

---

## Anexo · Medidas del código y requisitos para activar Chrome (documento anterior)

Este documento separa lo que la herramienta hace por sí misma de lo que **exige infraestructura** y, por
tanto, corresponde a Proconsi al desplegarla. Lo segundo no está implementado en el código.

### A1. Descargas con PHP (cURL)

Resuelto en código (`UrlGuard`, `Fetcher`): solo http/https, puertos 80 y 443, sin credenciales en la URL,
rechazo de IP privadas y reservadas (IPv4 e IPv6), IP validada fijada con `CURLOPT_RESOLVE`, redirecciones
manuales revalidadas (máx. 5), 10 s y 2 MB por respuesta, límite de uso por IP.

### A2. Pruebas con Chrome (`chrome.activo`, desactivado por defecto)

Chrome **ejecuta el JavaScript de la web analizada** en el servidor. Es la parte de más riesgo de la
herramienta y por eso viene desactivada. Mantenerla así salvo que se cumplan los requisitos del apartado A3.

#### Qué hace el código

| Riesgo | Medida | Dónde |
|---|---|---|
| **DNS rebinding**: UrlGuard valida una IP pública y Chrome, al resolver por su cuenta, obtiene una interna | Chrome **no resuelve nombres**: arranca con `--host-resolver-rules="MAP host IP_validada, …, MAP * ~NOTFOUND"`. Solo puede conectarse a las IP que ha validado PHP. Como las reglas se fijan al arrancar, la página se carga hasta 4 veces para descubrir y fijar los hosts que pide (CDN, fuentes…) | `Navegador::reglas_resolucion()`, `BrowserChecks::run()` |
| Redirecciones y subpeticiones a destinos internos | Cada petición (incluidas redirecciones) se intercepta y se valida con UrlGuard antes de salir; solo GET, HEAD y OPTIONS; WebSockets bloqueados | `Navegador::filtrar()`, `destino_seguro()` |
| Un host validado en el puerto 443 daba por bueno cualquier otro puerto (fallo confirmado) | La validación se guarda por esquema + host + puerto | `Navegador::clave_destino()` |
| La IP a la que Chrome se conecta no es pública pese a todo | Defensa adicional: si Chrome informa de una red no pública (`resourceIPAddressSpace`), la respuesta se descarta antes de entregarla a la página y el destino queda vetado | `Navegador::respuesta_permitida()` |
| Descargas de archivos al servidor | `Browser.setDownloadBehavior: deny`; perfil temporal que se borra | `Navegador::preparar()` |
| Chrome o su perfil quedan vivos si la petición se interrumpe | Cierre en `finally`, en el destructor y al terminar la petición (también con error fatal o tiempo agotado); tiempo total máximo de la sesión; barrido de perfiles abandonados al arrancar y con `bin/limpiar.php` | `Navegador::cerrar()`, `limpiar_huerfanos()` |
| Abuso (muchas peticiones) | Máximo de peticiones por sesión, firma de un solo análisis, límite de uso por IP | `Navegador`, `api/browser.php` |

Coste: en webs con recursos de terceros, las pruebas con Chrome tardan más que antes (entre 2 y 4 cargas de
la página; medido: ~45 s frente a ~17 s en una web con 6 hosts). Los hosts que la página pida más tarde
de lo descubierto no se resuelven; el recuento va en la respuesta de `api/browser.php` (`seguridad`).

#### Qué NO resuelve el código (limitaciones conocidas)

- La comprobación de la red real (`resourceIPAddressSpace`) es una defensa adicional, no una garantía: Chrome
  no siempre da el dato antes de entregar la respuesta. La garantía es que Chrome no resuelve por su cuenta.
- Si la respuesta se descarta, **la petición GET ya ha salido** (sin poder leerse la respuesta). Con la
  resolución fijada esto solo ocurriría si la IP validada como pública llevara a un servicio interno.
- UrlGuard decide si una IP es pública por rangos. No conoce la red del servidor: una IP pública que el
  servidor enruta hacia dentro (NAT, proxy transparente, IP públicas de uso interno) pasa la validación.
- Fallos de seguridad del propio Chrome (escape del aislamiento) y consumo de CPU y memoria del servidor.
- `chrome.sin_sandbox = true` desactiva el aislamiento de Chrome: no usarlo con webs de terceros.

### A3. Requisitos de despliegue para activar Chrome (infraestructura, no implementado)

1. **Filtrado de salida** del servidor (cortafuegos o proxy de salida): el usuario del sistema que ejecuta
   PHP y Chrome solo puede abrir conexiones a Internet por los puertos 80 y 443, y **nunca** a rangos
   internos (10/8, 172.16/12, 192.168/16, 169.254/16, 127/8, fc00::/7, fe80::/10) ni a los servicios de
   metadatos de la nube (169.254.169.254).
2. **Aislamiento de red**: máquina o contenedor sin acceso a la red interna de Proconsi (DMZ o VLAN propia),
   sin credenciales ni recursos compartidos montados.
3. **Usuario sin privilegios** para el servidor web, con `storage/` como único directorio escribible además
   del temporal del sistema. Chrome con su aislamiento activo (`sin_sandbox` = false).
4. **Chrome actualizado** (actualizaciones automáticas o revisión periódica).
5. **Límites de recursos** del proceso (memoria, CPU, número de procesos) en el sistema o el contenedor.
6. **Tarea programada** de limpieza: `php bin/limpiar.php` cada 15–60 minutos (ver README).

Sin los puntos 1 y 2, la recomendación es **no activar Chrome** en un servidor con acceso a la red interna.

### A4. Historial sin control de acceso (hallazgo confirmado, sin cambios en código)

`historial.php` y `api/history_report.php` no piden identificación: cualquiera que llegue a la herramienta
ve las URL analizadas, sus notas y los informes guardados (que incluyen extractos de las webs). Los
identificadores son correlativos, así que se pueden recorrer. Es una decisión del proyecto («sin cuentas ni
login») y añadir autenticación está fuera de este encargo. Requisito de despliegue: restringir el acceso a
la herramienta completa en el servidor web (red interna o VPN, lista de IP, o autenticación del propio
Apache/IIS) y servirla solo por HTTPS.

### A5. Datos que se guardan

- `storage/runs/`: resultados temporales (1 h). `storage/ratelimit/`: marcas de tiempo por hash de IP.
- `storage/logs/errores.log`: errores internos con identificador, sin URL analizadas ni claves (ver `ErrorLog`).
- `storage/historial.key` y `storage/site_secret`: claves de firma generadas por la herramienta.
- MySQL (`radar_web.historial`), si está activado: URL, fecha, notas, recuentos, fiabilidad e informe completo; 12 meses.

`storage/` no debe ser accesible por web (`storage/.htaccess` en Apache; en Nginx o IIS hay que denegarlo
en la configuración del servidor) ni incluirse en copias públicas.
