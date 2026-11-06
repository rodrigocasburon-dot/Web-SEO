# Web-SEO

Herramienta interna de Proconsi. Le das una URL y puntúa de 0 a 100 su SEO, su GEO y su UX (con accesibilidad dentro), y marca los puntos de la checklist que faltan. Está hecha con PHP y MySQL, sin frameworks. Además usa Chrome para probar las páginas de verdad y, si hay claves, PageSpeed y una IA.

## Instalación

1. Clona el repositorio dentro de `C:\wamp64\www`, con WampServer arrancado.
2. Abre <http://localhost/phpmyadmin>, entra en **Importar** y sube `install.sql`.
3. Copia `config.example.php` con el nombre `config.php`. Si tu MySQL usa otro puerto o contraseña, cámbialos ahí.
4. Abre <http://localhost/Web-SEO/v1.0/>.

`config.php` no se sube a GitHub, porque guarda contraseñas y el repositorio es público.

## Cómo funciona

1. `index.php` recibe la URL, descarga la portada y crea el análisis.
2. `progreso.php` se recarga solo. Cada recarga visita páginas durante unos segundos, siguiendo los enlaces del mismo dominio y respetando `robots.txt`. No hay límite de páginas: al acabarse los enlaces lee el sitemap, y se puede pulsar "Terminar ya" para ver el informe con lo analizado.
3. Chrome abre hasta 5 páginas de muestra y mide teclado, foco, zoom, formularios, carruseles y cookies.
4. La IA, si hay clave, valora en esas páginas los textos alt y si el contenido responde a lo que promete.
5. La última fase pide PageSpeed, si hay clave, y ejecuta las comprobaciones que comparan páginas.
6. `resultado.php` muestra el informe, con las notas, la checklist y el botón "Guardar como PDF".

La nota de cada bloque es puntos conseguidos entre puntos posibles, con peso según la gravedad: crítica 10, alta 6, media 3 y baja 1.

## Chrome, IA y PageSpeed

Todo es opcional. Lo que falte se estima leyendo el código o sale como "sin datos", y el informe indica qué se ha medido.

| Qué | Cómo se activa | Sin ello |
|---|---|---|
| Chrome | Instálalo. Se busca solo, o pon su ruta en `chrome_ruta` | Los seis puntos se estiman por código |
| IA | Crea una clave en <https://platform.claude.com> y ponla en `ia_clave` | El alt y la intención se estiman por código |
| PageSpeed | Crea una clave gratuita en Google Cloud y ponla en `pagespeed_clave` | La velocidad, el contraste y los botones táctiles salen como "sin datos" |

Las claves van en `config.php`, nunca en GitHub. Cada análisis con IA tiene un coste en la cuenta de Anthropic.

Chrome abre webs de terceros y ejecuta su JavaScript en tu servidor, así que solo debe usarse en una red interna. Se bloquean las direcciones privadas y cualquier envío de datos, también en los formularios, pero una web podría cambiar su DNS para intentar saltarse ese filtro.

Si actualizas desde una versión anterior, vuelve a importar `install.sql`.

## Carpetas

| Carpeta | Qué contiene |
|---|---|
| `seo/`, `geo/`, `ux/` | Las comprobaciones de cada bloque |
| `web/` | Comprobaciones que comparan páginas o usan PageSpeed |
| `lib/` | El motor: descarga, lectura, rastreo, notas y funciones comunes |
| `checklist/checklist.json` | La lista: pesos, reglas y textos de cómo arreglar cada punto |
| `docs/Lista_unificada.pdf` | La misma lista para leer cómodamente |

## Cómo cambiar o añadir una comprobación

Los 88 puntos ya están programados. Para cambiar uno, edita su archivo. Para añadir uno nuevo:

1. Añade el punto a `checklist/checklist.json` con un ID nuevo, por ejemplo `SEO-27`.
2. Crea un archivo con ese nombre exacto en su carpeta: `seo/SEO-27.php`.
3. Copia el patrón de abajo y programa la regla.
4. Analiza una web en <http://localhost/Web-SEO/v1.0/> y comprueba el resultado.

No hay que registrar nada: la herramienta encuentra el archivo por su nombre. Mientras no exista, el punto sale como pendiente y no cuenta en la nota.

```php
<?php
// SEO-07 Canonical: existe y coincide con la URL analizada.
return function (array $pagina): array {
    $enlaces = $pagina['xpath']->query('//link[@rel="canonical"]');

    if ($enlaces->length === 0) {
        return ['estado' => 'aviso', 'detalle' => 'La página no tiene canonical.'];
    }
    return ['estado' => 'cumple', 'detalle' => 'La página tiene canonical.'];
};
```

Puedes usar de ejemplo cualquier archivo de la carpeta. Los puntos con alcance `comparacion` y los de PageSpeed no reciben `$pagina`, sino `$sitio`, con `paginas`, `pagespeed`, `url` y `config`.

### Qué debe devolver

Un array con `estado` y `detalle`. El detalle va en español y dice qué se ha encontrado, para que lo entienda alguien que no programa.

| Estado | Cuándo usarlo | Efecto en la nota |
|---|---|---|
| `cumple` | Hay pruebas de que está bien | Suma todo el peso |
| `aviso` | Señal dudosa o que puede ser intencionada | Suma la mitad |
| `no_cumple` | Hay pruebas de que falta o está mal | No suma |
| `sin_datos` | No se ha podido comprobar | No cuenta |
| `no_aplica` | El punto no tiene sentido en esta página | No cuenta |

La columna "Si falla" de la lista indica si un fallo es `no_cumple` o `aviso`. Los puntos marcados como LOCAL no necesitan código extra: la base los salta si la casilla no está marcada.

### Qué contiene `$pagina`

| Clave | Qué es |
|---|---|
| `$pagina['url']` | URL final, después de las redirecciones |
| `$pagina['url_pedida']` | URL que escribió la persona |
| `$pagina['codigo']` | Código HTTP, por ejemplo `200` |
| `$pagina['tiempo_ms']` | Milisegundos hasta el primer byte |
| `$pagina['saltos']` | Redirecciones seguidas: lista de `url` y `codigo` |
| `$pagina['cabeceras']` | Cabeceras HTTP, con el nombre en minúsculas |
| `$pagina['html']` | HTML como texto, en UTF-8 |
| `$pagina['dom']` | El HTML como `DOMDocument` |
| `$pagina['xpath']` | `DOMXPath` para buscar elementos |
| `$pagina['texto']` | Texto visible, sin scripts ni estilos |
| `$pagina['texto_principal']` | Texto visible sin menús, cabecera ni pie |
| `$pagina['css']` | Estilos de la web. Solo se leen en la portada |
| `$pagina['jsonld']` | Lista de objetos JSON-LD ya leídos |
| `$pagina['jsonld_errores']` | Bloques JSON-LD con la sintaxis rota |
| `$pagina['robots']` | Texto de `robots.txt`, o `null` si no existe |
| `$pagina['es_portada']` | `true` en la primera página |
| `$pagina['config']` | La configuración, para llamar a `descargar()` |

También puedes usar estas funciones:

- `robots_permite($pagina['robots'], 'Googlebot', '/')` indica si un bot puede entrar en una ruta.
- `url_absoluta($pagina['url'], '/sitemap.xml')` completa una dirección relativa.
- `descargar($url, $pagina['config'])` descarga otra dirección, como el sitemap. Lanza `RuntimeException` si falla.
- `cumple($detalle)`, `no_cumple($detalle)`, `aviso($detalle)`, `sin_datos($detalle)` y `no_aplica($detalle)` crean el resultado.
- En `lib/ayudas.php` hay más: `palabras()`, `encabezados()`, `enlaces_de()`, `campo_jsonld()`, `telefonos_en()`...

## Qué pedirle a tu IA

Pásale este README y el ejemplo de tu carpeta, y pega esto cambiando los corchetes:

```text
Estoy programando una comprobación para una herramienta de auditoría web en PHP
puro, sin librerías externas. Sigue exactamente el patrón del README y del archivo
de ejemplo que te paso.
Crea el archivo [carpeta/ID.php], que comprueba: [copia la regla de la lista].
Si falla, devuelve [no_cumple o aviso]. Si no se puede comprobar, devuelve sin_datos.
El detalle debe estar en español y decir qué se ha encontrado.
No cambies ningún otro archivo.
```

## Reglas del equipo

- No inventes resultados: si algo no se puede medir, devuelve `sin_datos`.
- Nunca escribas "cumple WCAG" ni "cumple la ley". Di "sin errores detectables automáticamente".
- No subas contraseñas ni claves a GitHub.
