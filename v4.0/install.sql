-- Historial de notas (Fase 4 de la v2.0; en la v3.0 también los análisis de sitio). Se importa una vez desde phpMyAdmin.
-- Base de datos propia: no comparte tablas con la v1.0 (web_seo), cuyo install.sql borra las suyas.
-- Guarda notas, recuentos y (v3.0, decisión del 9/11/2026) el informe completo, con evidencias de la web. Las filas de más de 12 meses
-- (o 'meses' en la configuración) se borran automáticamente al guardar un análisis nuevo.
CREATE DATABASE IF NOT EXISTS radar_web CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE radar_web;

CREATE TABLE IF NOT EXISTS historial (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(2048) NOT NULL,                -- URL final analizada
    url_clave CHAR(40) NOT NULL,               -- sha1 de UrlNormalizer::comparisonKey(url)
    fecha DATETIME NOT NULL,                   -- UTC
    nota_seo DECIMAL(4,1) NULL,                -- NULL si el acceso estaba bloqueado
    nota_ia DECIMAL(4,1) NULL,
    nota_accesibilidad DECIMAL(4,1) NULL,
    nota_global DECIMAL(4,1) NULL,
    incompleta TINYINT(1) NOT NULL DEFAULT 0,
    bloqueado TINYINT(1) NOT NULL DEFAULT 0,
    pagespeed TINYINT(1) NOT NULL DEFAULT 0,   -- 1 si la nota incluye C08 y C14 con datos
    criticos SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- fallos (NO_CUMPLE) por gravedad
    altos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    medios SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    bajos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tipo VARCHAR(10) NOT NULL DEFAULT 'url',   -- v3.0: 'url' (una página) o 'sitio' (muestra de páginas)
    paginas SMALLINT UNSIGNED NOT NULL DEFAULT 1,    -- v3.0: páginas con nota en un análisis de sitio
    informe MEDIUMBLOB NULL,                   -- v3.0: informe completo (JSON comprimido con gzip), para volver a verlo
    KEY por_url (url_clave, tipo, fecha),
    KEY por_fecha (fecha)
) ENGINE=InnoDB;

-- Si ya tenías la base de la v2.0, en vez de lo anterior ejecuta solo esto (una vez):
-- ALTER TABLE radar_web.historial ADD COLUMN tipo VARCHAR(10) NOT NULL DEFAULT 'url', ADD COLUMN paginas SMALLINT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN informe MEDIUMBLOB NULL;
