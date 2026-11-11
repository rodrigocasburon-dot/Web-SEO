# Seguridad: qué resuelve el código y qué debe resolver el despliegue

Este documento separa lo que la herramienta hace por sí misma de lo que **exige infraestructura** y, por
tanto, corresponde a Proconsi al desplegarla. Lo segundo no está implementado en el código.

## 1. Descargas con PHP (cURL)

Resuelto en código (`UrlGuard`, `Fetcher`): solo http/https, puertos 80 y 443, sin credenciales en la URL,
rechazo de IP privadas y reservadas (IPv4 e IPv6), IP validada fijada con `CURLOPT_RESOLVE`, redirecciones
manuales revalidadas (máx. 5), 10 s y 2 MB por respuesta, límite de uso por IP.

## 2. Pruebas con Chrome (`chrome.activo`, desactivado por defecto)

Chrome **ejecuta el JavaScript de la web analizada** en el servidor. Es la parte de más riesgo de la
herramienta y por eso viene desactivada. Mantenerla así salvo que se cumplan los requisitos del apartado 3.

### Qué hace el código

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

### Qué NO resuelve el código (limitaciones conocidas)

- La comprobación de la red real (`resourceIPAddressSpace`) es una defensa adicional, no una garantía: Chrome
  no siempre da el dato antes de entregar la respuesta. La garantía es que Chrome no resuelve por su cuenta.
- Si la respuesta se descarta, **la petición GET ya ha salido** (sin poder leerse la respuesta). Con la
  resolución fijada esto solo ocurriría si la IP validada como pública llevara a un servicio interno.
- UrlGuard decide si una IP es pública por rangos. No conoce la red del servidor: una IP pública que el
  servidor enruta hacia dentro (NAT, proxy transparente, IP públicas de uso interno) pasa la validación.
- Fallos de seguridad del propio Chrome (escape del aislamiento) y consumo de CPU y memoria del servidor.
- `chrome.sin_sandbox = true` desactiva el aislamiento de Chrome: no usarlo con webs de terceros.

## 3. Requisitos de despliegue para activar Chrome (infraestructura, no implementado)

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

Sin 1 y 2, la recomendación es **no activar Chrome** en un servidor con acceso a la red interna.

## 4. Historial sin control de acceso (hallazgo confirmado, sin cambios en código)

`historial.php` y `api/history_report.php` no piden identificación: cualquiera que llegue a la herramienta
ve las URL analizadas, sus notas y los informes guardados (que incluyen extractos de las webs). Los
identificadores son correlativos, así que se pueden recorrer. Es una decisión del proyecto («sin cuentas ni
login») y añadir autenticación está fuera de este encargo. Requisito de despliegue: restringir el acceso a
la herramienta completa en el servidor web (red interna o VPN, lista de IP, o autenticación del propio
Apache/IIS) y servirla solo por HTTPS.

## 5. Datos que se guardan

- `storage/runs/`: resultados temporales (1 h). `storage/ratelimit/`: marcas de tiempo por hash de IP.
- `storage/logs/errores.log`: errores internos con identificador, sin URL analizadas ni claves (ver `ErrorLog`).
- `storage/historial.key` y `storage/site_secret`: claves de firma generadas por la herramienta.
- MySQL (`radar_web.historial`), si está activado: URL, fecha, notas, recuentos, fiabilidad e informe completo; 12 meses.

`storage/` no debe ser accesible por web (`storage/.htaccess` en Apache; en Nginx o IIS hay que denegarlo
en la configuración del servidor) ni incluirse en copias públicas.
