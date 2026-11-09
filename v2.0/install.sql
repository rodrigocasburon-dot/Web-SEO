-- Historial de notas de la v2.0 (Fase 4). Se importa una vez desde phpMyAdmin.
-- Base de datos propia: no comparte tablas con la v1.0 (web_seo), cuyo install.sql borra las suyas.
-- Solo guarda notas y recuentos; nunca contenido de las webs analizadas. Las filas de más de 12 meses
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
    KEY por_url (url_clave, fecha),
    KEY por_fecha (fecha)
) ENGINE=InnoDB;
