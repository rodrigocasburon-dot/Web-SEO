# Lista de comprobación para desplegar Radar web en Proconsi

Qué hay que hacer en el servidor antes de abrir la herramienta a los compañeros. El código ya trae sus
defensas (ver `docs/SEGURIDAD.md`); esta lista cubre lo que **solo se puede hacer en el servidor o en la
organización**. Marcar cada punto al completarlo e indicar quién lo ha hecho.

**Responsables** (los nombres están por asignar; los decide el grupo):

- **Sistemas:** quien administra el servidor, la red y las copias. Nombre: [por asignar].
- **Desarrollo:** quien mantiene Radar web. Nombre: [por asignar].
- **Datos:** delegado o responsable de protección de datos. Nombre: [por asignar].
- **Grupo:** el equipo o la dirección que aprueba las decisiones.

## 1. Acceso y red

- [ ] **Solo red interna o VPN** (Sistemas)
  - La herramienta no debe ser accesible desde Internet: no tiene usuarios ni contraseñas y el historial
    lo ve cualquiera que llegue (SEG-07).
  - Se restringe en el cortafuegos o en Apache, con `Require ip` y los rangos internos, o con autenticación
    de Apache.
- [ ] **HTTPS obligatorio** (Sistemas)
  - Certificado válido y redirección de http a https.
  - HSTS (`Strict-Transport-Security`) solo cuando HTTPS funcione bien en todo el dominio.
- [ ] **Cortafuegos de salida** (Sistemas)
  - El servidor solo puede salir a Internet por los puertos 80 y 443, y nunca hacia rangos internos
    (10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, fc00::/7, fe80::/10).
  - Es una segunda barrera por si fallara el control del código (SSRF).
  - Obligatorio antes de activar Chrome (`docs/SEGURIDAD.md`, apartado A3).
- [ ] **Proxy inverso** (Sistemas y Desarrollo)
  - Si se usa, el límite por IP verá la IP del proxy y no la del usuario. En ese caso hay que configurar la
    IP de confianza antes de abrirla (SEG-10).

## 2. Apache y PHP

- [ ] **`AllowOverride` activo en la carpeta de la herramienta** (Sistemas)
  - Al menos `AuthConfig Options`; en WAMP viene `All`. Sin esto, los `.htaccess` (SEG-02, SEG-03) no se
    aplican.
  - Comprobación: `https://servidor/radar/config/` y `https://servidor/radar/tests/run.php` deben dar 403
    o 404.
- [ ] **En Nginx o IIS** (Sistemas)
  - Negar `config/`, `src/`, `lang/`, `bin/`, `docs/`, `certs/`, `tests/` y `storage/`, los archivos
    ocultos y los `.md`, `.sql` y `.pem`, y desactivar los listados. Los `.htaccess` no sirven allí.
- [ ] **`display_errors = Off` y `log_errors = On` en `php.ini`** (Sistemas)
  - El código ya lo fuerza en sus páginas (SEG-04). Hacerlo también en `php.ini` cubre cualquier error
    anterior.
- [ ] **`expose_php = Off`** en `php.ini`; **`ServerTokens Prod`** y **`ServerSignature Off`** en Apache
  (Sistemas)
  - Ocultan las versiones (SEG-08).
- [ ] **PHP 8.2 o superior, con soporte de seguridad** (Sistemas)
  - Extensiones: curl, dom, mbstring, openssl y json; pdo_mysql si se usa el historial.
  - PHP 8.0 está sin soporte desde el 26/11/2023 (SEG-15).
- [ ] **Actualizaciones de PHP, Apache y el sistema** (Sistemas)
  - Revisión al menos mensual y parches de seguridad en cuanto salgan. Responsable con fecha en el
    calendario.
- [ ] **Usuario sin privilegios para Apache y PHP** (Sistemas)
  - Solo `storage/` es escribible, más el temporal del sistema si se activa Chrome.

## 3. Configuración y claves

- [ ] **`config/config.php` se crea a mano en el servidor** a partir de `config.example.php` (Desarrollo)
  - No se copia de ningún zip ni equipo.
  - No se sube a Git: ya está en `.gitignore`.
- [ ] **`config/` fuera de la raíz web**, o al menos negada por `.htaccess` (Sistemas y Desarrollo)
  - La herramienta busca la configuración en la carpeta que indique la variable de entorno
    `WEBSEO_CONFIG_DIR`, si existe.
  - Con Apache y mod_php basta `SetEnv`; con FastCGI (mod_fcgid) hay que pasarla con `FcgidInitialEnv`.
    **Hay que comprobarlo en el servidor**: no se ha probado en esta auditoría.
  - Si no se mueve, `config/.htaccess` niega el acceso web (comprobado en WAMP).
- [ ] **Rotar la clave de Google PageSpeed** (Desarrollo, con quien administre la cuenta de Google Cloud)
  - Pasos:
    1. Crear una clave nueva.
    2. Restringirla a la API de PageSpeed Insights y a la IP pública del servidor.
    3. Ponerla en el `config.php` del servidor.
    4. Borrar la antigua.
  - Motivo: el zip de la v4.0 enviado por WhatsApp incluía `config/config.php` (SEG-14).
- [ ] **No copiar `storage/` de otro equipo** (Desarrollo)
  - `historial.key` y `site_secret` se generan solos en el primer uso; los del zip quedan descartados
    (SEG-14).
- [ ] **Rotación periódica de claves** (Desarrollo)
  - Clave de PageSpeed: anual, y siempre que salga del servidor o cambie el equipo.
  - Claves de `storage/`: borrarlas las regenera, pero invalida los enlaces firmados que estén abiertos.

## 4. Base de datos (solo si se activa el historial)

- [ ] **Usuario de MySQL propio y con permisos mínimos** (Sistemas)
  - Solo `SELECT`, `INSERT`, `UPDATE` y `DELETE` sobre la base `radar_web`.
  - Sin `DROP`, `ALTER`, `GRANT`, `FILE` ni acceso a otras bases.
  - Conexión solo desde el propio servidor (`'usuario'@'localhost'`).
  - **No se ha podido verificar** cómo está hoy (no se ha leído `config.php`).
- [ ] **Contraseña larga y única**, guardada solo en `config.php` del servidor (Sistemas)
  - Cambiarla si estaba en el `config.php` del zip.
- [ ] **MySQL sin acceso desde la red** (`bind-address = 127.0.0.1`) si está en la misma máquina (Sistemas).
- [ ] **Datos personales** (Datos)
  - Revisar que guardar URL, notas e informes durante 12 meses, sin IP de usuarios, encaja con el registro
    de actividades de tratamiento.
  - Decidir si hay que informar a los usuarios internos (SEG-07).

## 5. Copias de seguridad

- [ ] **Copias cifradas** de la base `radar_web` y de `config/config.php` (Sistemas)
  - Cifrado en reposo, por ejemplo con 7-Zip AES-256 o el cifrado del sistema de copias.
  - Clave guardada aparte de la copia.
  - `storage/` no necesita copia: son temporales y claves que se regeneran.
- [ ] **Copias fuera de la carpeta web** y sin acceso público (Sistemas).
- [ ] **Prueba de restauración** al menos una vez antes de la puesta en marcha (Sistemas).

## 6. Funcionamiento

- [ ] **Tarea programada de limpieza**: `php bin/limpiar.php` cada 30 minutos (Sistemas). Ver el README.
- [ ] **Chrome desactivado** (`chrome.activo = false`) salvo que se cumplan los requisitos del apartado A3
  de `docs/SEGURIDAD.md` (Grupo).
- [ ] **Tope global de PageSpeed** (Grupo; Desarrollo para aplicarlo)
  - Decidir la cifra diaria y aplicarla (SEG-06, pendiente).
- [ ] **Registro de errores**: revisar `storage/logs/errores.log` y el registro de PHP cada semana
  (Desarrollo).
- [ ] **Pruebas tras cada despliegue** (Desarrollo)
  - Por consola en el servidor: `php tests/run.php` y `php tests/seguridad.php`.
  - Por web: comprobar que `…/tests/run.php`, `…/config/` y `…/install.sql` dan 403 o 404.

## 7. Decisiones pendientes del grupo

1. ¿Restricción por red interna o VPN, o autenticación de Apache? (SEG-07)
2. Cifra del tope diario de PageSpeed (SEG-06).
3. ¿Mostrar las imágenes de la web analizada en el informe o solo su URL? (SEG-13)
4. ¿Agrupar el límite por IP en IPv6 por /64? Solo importa si la herramienta queda expuesta fuera de la red
   interna (SEG-10).
5. Quién es responsable de cada bloque de esta lista.
