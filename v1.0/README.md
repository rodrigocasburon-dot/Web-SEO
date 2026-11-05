# Web-SEO

Herramienta interna de Proconsi. Le das una URL y puntúa de 0 a 100 su SEO, su GEO y su UX (con accesibilidad dentro), y marca los puntos de la checklist que faltan. Está hecha con PHP y MySQL, sin frameworks.

## Instalación

1. Clona el repositorio dentro de `C:\wamp64\www`, con WampServer arrancado.
2. Abre <http://localhost/phpmyadmin>, entra en **Importar** y sube `install.sql`.
3. Copia `config.example.php` con el nombre `config.php`. Si tu MySQL usa otro puerto o contraseña, cámbialos ahí.
4. Abre <http://localhost/Web-SEO/v1.0/>.

`config.php` no se sube a GitHub, porque guarda contraseñas y el repositorio es público.

## Carpetas

| Carpeta | Quién la toca | Qué contiene |
|---|---|---|
| `seo/` | Estefany | Comprobaciones SEO |
| `geo/` | Camila | Comprobaciones GEO |
| `ux/` | Rodrigo | Comprobaciones UX y accesibilidad |
| `web/` | Jose | Comprobaciones que comparan páginas o usan PageSpeed |
| `lib/`, `index.php`, `resultado.php`, `assets/` | Jose | La base de la herramienta |
| `checklist/checklist.json` | Jose, con lo que acordéis | La lista: pesos, reglas y textos de cómo arreglar cada punto |

La lista para leer cómodamente está en `docs/Lista_unificada.pdf`.

## Cómo añadir una comprobación

1. Busca tu punto en la lista y apunta su ID, por ejemplo `SEO-07`.
2. Crea un archivo con ese nombre exacto en tu carpeta: `seo/SEO-07.php`.
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

Hay un ejemplo real en cada carpeta: `seo/SEO-08.php`, `geo/GEO-16.php`, `ux/UX-16.php` y `web/WEB-10.php`.

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
| `$pagina['jsonld']` | Lista de objetos JSON-LD ya leídos |
| `$pagina['jsonld_errores']` | Bloques JSON-LD con la sintaxis rota |
| `$pagina['robots']` | Texto de `robots.txt`, o `null` si no existe |
| `$pagina['es_local']` | `true` si la casilla está marcada |

También puedes usar estas funciones:

- `robots_permite($pagina['robots'], 'Googlebot', '/')` indica si un bot puede entrar en una ruta.
- `url_absoluta($pagina['url'], '/sitemap.xml')` completa una dirección relativa.
- `descargar($url, cargar_config())` descarga otra dirección, como el sitemap. Lanza `RuntimeException` si falla.

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

- Cada uno trabaja en su carpeta. Si necesitas tocar algo más, avisa antes.
- No inventes resultados: si algo no se puede medir, devuelve `sin_datos`.
- Nunca escribas "cumple WCAG" ni "cumple la ley". Di "sin errores detectables automáticamente".
- No subas contraseñas ni claves a GitHub.

## Estado actual

Esta es la base mínima: analiza solo la página que escribes y calcula las notas con lo que ya esté programado. Faltan el rastreo de las 100 páginas, PageSpeed y el informe con "Guardar como PDF".
