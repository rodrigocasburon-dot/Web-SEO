# Radar web v3.0 · Mejoras del motor (fiabilidad y seguridad)

Fecha: 9/11/2026. Base: `Web-SEO-v3.0.zip`. No se ha tocado el repositorio ni se ha hecho ninguna operación de git.

## Qué hay en esta carpeta

- `parches/fase1…fase6.patch`: un parche unificado por fase (se aplican en orden con `patch -p1` o `git apply`
  desde la carpeta `v3.0`; se revierten en orden inverso). Comprobado: base + los 6 parches = `v3.0/`.
- `v3.0/`: el proyecto completo ya modificado (para copiarlo directamente).
- `Web-SEO-v3.0-mejorado.zip`: lo mismo, comprimido.

No se ha leído ni incluido ningún `config/config.php`. No se cambian la fórmula, los pesos, las
penalizaciones por repetición ni los criterios de las comprobaciones.

## Resultado de las pruebas (ejecutadas, no supuestas)

| Qué | Resultado |
|---|---|
| `php tests/run.php` original (PHP 8.0.30) | 273 correctas, 0 fallidas |
| `php tests/run.php` final (PHP 8.0.30 y PHP 8.4.15) | 305 correctas, 0 fallidas, 0 omitidas |
| `php -l` sobre los 102 archivos PHP (8.0 y 8.4) | sin errores |
| Pruebas existentes | no se ha modificado ninguna; solo se añade al final de `tests/js/site_test.js` |
| Notas de los fixtures | sin cambios (las pruebas de puntuación originales pasan igual) |
| MySQL real (8.4.7, instancia temporal en el puerto 3399, borrada al terminar) | transacción, rollback, migración e `install.sql` nuevo: correctos |
| Chrome real instalado en este equipo | prueba de DNS rebinding simulado y de limpieza: correctas |

No verificado: MariaDB, Linux (rama `pkill`), otras versiones de Chrome o Edge, IIS/Nginx, lector de
pantalla sobre los textos nuevos y el aspecto visual de las líneas nuevas (solo se comprobó su contenido
en el DOM). Node no está instalado: `site_test.js` se ejecutó en Chrome sin interfaz.

## Fase 0 · Verificación de los hallazgos

Líneas del código ORIGINAL.

| # | Hallazgo | Dónde | Veredicto | Evidencia |
|---|---|---|---|---|
| 1 | Chrome resuelve DNS por su cuenta tras validar con UrlGuard | `src/Navegador.php:26-30, 185-210` | **Confirmado** | `destino_seguro()` llama a `UrlGuard::validate()` (DNS en PHP) y después `Fetch.continueRequest` deja que Chrome resuelva otra vez; Chrome arranca sin `--host-resolver-rules`. Reproducido con Chrome real: con la validación viendo una IP pública, la página leía un servidor en 127.0.0.1 |
| 1b | (nuevo) La validación se guardaba solo por host | `src/Navegador.php:199-209` | **Confirmado** | `$this->hosts[$host]`: validado `https://e.example/`, después `https://e.example:8443/` pasaba sin validar el puerto. Prueba incluida |
| 2 | `site_record.php` marca el análisis como usado antes de guardar | `api/site_record.php:42-46` frente a `107-111` | **Confirmado** | El `.used` se crea antes de `History::fromConfig()`; si MySQL falla responde 503 y el reintento da 409 «ya guardado»: el análisis se pierde. Prueba de extremo a extremo incluida |
| 3 | Informe y registro en operaciones separadas | `api/site_record.php:111 y 118`; `api/analyze.php:61 y 94`; `api/score.php:54 y 69` | **Confirmado** | `recordSite()`/`record()`/`finalize()` y `saveReport()` son sentencias independientes, sin transacción; en `analyze.php` y `score.php` el fallo del informe se traga con `error_log` |
| 4 | RunStore ignora errores y no escribe de forma atómica | `src/RunStore.php:25-27, 36-37` | **Confirmado** | `return` silencioso si no hay directorio; `@file_put_contents` sin comprobar el resultado, directo sobre el archivo final; `put()` devuelve `void` |
| 5 | Sin limpieza periódica ni garantía de limpiar perfiles de Chrome | `src/RunStore.php:29`; `api/site_record.php:37-41`; `src/BrowserChecks.php:57-61`; `src/Navegador.php:128-147` | **Parcial** | Hay limpieza, pero solo cuando llega una petición (y al azar: 1 de cada 20). Chrome se cierra en un `finally`, que no cubre error fatal, tiempo agotado ni proceso matado; no hay barrido de perfiles huérfanos |
| 6 | CheckRunner convierte errores en SIN_DATOS sin registrar nada | `src/CheckRunner.php:79-90` | **Confirmado** (y algo más) | No registra nada y además muestra `$e->getMessage()` al usuario; el registro resultante no lleva `pilares`, `severidad` ni `recomendacion` |
| 7 | El historial no tiene control de acceso | `historial.php`; `api/history_report.php:14-23` | **Confirmado**, sin corregir | Sin identificación e ids correlativos. Añadir login está excluido: queda documentado como requisito de despliegue en `docs/SEGURIDAD.md` |

Hallazgos adicionales confirmados y corregidos por estar dentro del alcance:

- `History::secretKey()` (`src/History.php:70-79`): dos peticiones simultáneas podían generar claves distintas, y no respetaba `WEBSEO_STORAGE_DIR`.
- `History::saveReport()` (`:249-256`): no comprobaba `json_encode`/`gzencode` ni que la fila existiera.
- `site_record.php:55-59`: las páginas cuyo resultado ya no estaba en el servidor se omitían sin avisar.
- Errores fatales de PHP en la API: respuesta vacía, sin JSON.

## Fase 1 · Persistencia fiable

- `History::atomic()`: fila e informe en una transacción en `analyze.php`, `score.php` y `site_record.php`.
- `site_record.php`: reserva de un solo uso (`RunStore::claim/confirm/release`). Solo se confirma tras el `commit`; si falla, se libera al terminar la petición (también con error fatal) y se puede reintentar. Una reserva abandonada caduca a los 120 s.
- `RunStore`: temporal + `rename`, `put()` devuelve `bool`, limpieza cada 10 min (no al azar) y `purge()` público.
- `bin/limpiar.php`: limpieza por tarea programada (solo consola).
- Las páginas sin resultado se cuentan (`sin_resultado`) y se avisa en pantalla.
- Cambio de comportamiento: si el informe no se puede guardar, tampoco queda la fila de notas (antes quedaba sin informe).
- Probar a mano: con el historial activado, parar MySQL, analizar un sitio (aviso «no se ha podido guardar… puedes volver a intentarlo»), arrancar MySQL y repetir el guardado.

## Fase 2 · Errores internos controlados

- `src/ErrorLog.php`: referencia `E-xxxxxxxx` y línea en `storage/logs/errores.log` (fecha, referencia, contexto, clase, archivo:línea, mensaje saneado: sin URL, sin rutas, y los errores de base de datos reducidos a su SQLSTATE).
- `CheckRunner`: sigue dando SIN_DATOS (no penaliza), con registro completo y el mensaje fijo «No se ha podido evaluar por un error interno de la herramienta (referencia E-…). No resta puntos.»
- `Api::internalError()` en los 8 endpoints y captura de errores fatales: siempre JSON con la referencia.
- Probar a mano: provocar un error (por ejemplo, un DSN de historial a una base sin tabla), ver la referencia en pantalla y buscarla en `storage/logs/errores.log`.

## Fase 3 · Indicador de fiabilidad (sin tocar la fórmula)

- `src/Reliability.php`, incluido en `informe.fiabilidad`: estado (completo / parcial / bloqueado), evaluados sobre posibles, sin datos, motivos y «peso evaluado» (proporción ponderada con P de la gravedad × reparto por área × peso del área; usa las constantes ya aprobadas, no añade ninguna).
- C08 y C14 constan como pendientes hasta que llega PageSpeed. HTML truncado ⇒ parcial.
- Se ve junto a las notas (una URL y sitio), en «Análisis anteriores» y en `historial.php`.
- Historial: va dentro del informe guardado. Para verlo en las listas hacen falta 3 columnas: **`docs/migracion-fiabilidad.sql` (no ejecutado; es opcional)**. Sin ellas todo funciona igual.
- Umbrales del nivel alta/media/baja: no inventados. Están comentados en `config.example.php` como PROPUESTA (90 y 50); sin ellos no se muestra nivel.
- Supuesto: «punto» = comprobación, como ya hacía `Report::coverage()`.

## Fase 4 · Chrome

Sigue desactivado por defecto.

- Chrome ya no resuelve nombres: `--host-resolver-rules="MAP host IP_validada, …, MAP * ~NOTFOUND"`. Como las reglas solo se pueden dar al arrancar, la página se carga hasta 4 veces para descubrir y fijar los hosts de terceros.
- Validación por esquema + host + puerto (corrige 1b).
- Defensa adicional: se descarta la respuesta si Chrome informa de una red no pública. No es una garantía: Chrome no siempre da ese dato a tiempo (medido), por eso la medida principal es la anterior.
- Limpieza: `cerrar()` repetible, en `finally`, destructor y fin de petición; tiempo máximo de sesión; barrido de perfiles huérfanos (busca el proceso por el nombre aleatorio del perfil).
- Descargas denegadas y tope de peticiones por sesión.
- **Coste**: en una web con 6 hosts de terceros, ~45 s frente a ~17 s. En una web sencilla, igual que antes (~11 s).
- Observación: la comprobación B05 (carrusel) ya era inestable en el código original (2 de 5 ejecuciones dieron AVISO en la misma web); no es efecto de estos cambios y no se ha tocado.
- `docs/SEGURIDAD.md`: lo que el código no puede garantizar (filtrado de salida, aislamiento de red, usuario sin privilegios) queda como requisito de despliegue, sin implementar.

## Fase 5 · Recomendaciones agrupadas

Existía a medias: una lista por comprobación con 5 ejemplos, ordenada por porcentaje. Ahora, en el informe de sitio:

- Apartado «Correcciones, de más a menos páginas afectadas»: cada recomendación idéntica una sola vez, con el recuento (no cumplen / con aviso), la gravedad, el arreglo y la lista de páginas.
- Tope configurable: `site_group_max_pages` (20). Si hay más, «Y N páginas más».
- C16 y C18 no se agrupan (son de todo el sitio). La tabla «Fallos por comprobación» no cambia.

## Fase 6 · Documentación y pruebas

- README: tabla de extensiones (obligatorias y opcionales), fiabilidad, errores, limpieza programada y pruebas nuevas. `tests/check_env.php` comprueba también `pdo_mysql`, `zlib`, `intl`, `pdo_sqlite`, `proc_open` y `exec`.
- 32 pruebas nuevas en `persistencia_test.php`, `errores_test.php`, `fiabilidad_test.php`, `navegador_test.php` y `sitejs_test.php`. Sin red externa y sin claves.

## Pruebas que debes ejecutar tú en WampServer

1. `php tests\check_env.php` → todo OK (el AVISO de `config.php` es normal si no existe).
2. `php tests\run.php` → se esperan **305 correctas, 0 fallidas**. Si aparece «OMITIDA» en las de Chrome, no hay navegador: no cuentan como superadas.
3. `php bin\limpiar.php` → imprime los recuentos de limpieza.
4. Con WAMP arrancado: un análisis de una URL y uno de sitio; comprobar la línea «Fiabilidad de las notas», el apartado de correcciones agrupadas y `historial.php`.
5. Opcional: ejecutar `docs\migracion-fiabilidad.sql` en phpMyAdmin y repetir el paso 4.
6. Solo si se va a usar Chrome: activar `chrome.activo` en una red de pruebas y lanzar un análisis de sitio.

## Decisiones pendientes (no tomadas por mí)

1. Aplicar o no la migración SQL de fiabilidad.
2. Validar los umbrales 90/50 del nivel de fiabilidad, o dejarlos sin nivel.
3. Aceptar el mayor tiempo de las pruebas con Chrome a cambio de que no resuelva DNS por su cuenta.
4. Restringir el acceso a la herramienta en el servidor (hallazgo 7) y cumplir `docs/SEGURIDAD.md` antes de activar Chrome.
5. Pesos y gravedades: no se han modificado. Están aprobados por el grupo y el encargo los excluye. No he encontrado ningún dato objetivo nuevo que justifique proponer otros valores; cualquier cambio sería una opinión, no una medición.
