# Analizador SEO / IA / Accesibilidad (Proconsi)

Herramienta web interna en PHP (sin framework ni Composer) que analiza una URL y genera un
informe de SEO, preparación para IA y accesibilidad. La evaluación es automática y parcial:
no sustituye una auditoría manual ni certifica el cumplimiento de WCAG ni de ninguna ley.

Estado: **fase 1** (estructura y entorno). Las demás fases están descritas en `CLAUDE.md` y en el plan del proyecto.

## Estructura

```
index.php            Pantalla (fase 5)
api/                 analyze.php y psi.php (fases 3-6)
src/                 UrlGuard, Fetcher, RateLimiter, Scorer, Report; src/Checks/ (una clase por comprobación)
config/              config.example.php (plantilla), config.php (local, fuera de git), weights.php
lang/es.php          Textos de recomendación
assets/              CSS y JS propios
storage/             Datos de ejecución (límite de uso); .htaccess deniega el acceso web
tests/               check_env.php, run.php (fase 3), fixtures/
```

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
