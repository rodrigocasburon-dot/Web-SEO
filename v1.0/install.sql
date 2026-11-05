-- Crea la base de datos de la herramienta. Se importa desde phpMyAdmin.
-- Volver a importarlo borra los análisis guardados, que son temporales.
CREATE DATABASE IF NOT EXISTS web_seo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE web_seo;

DROP TABLE IF EXISTS resultados, paginas, analisis;

-- Un análisis por cada URL enviada. No es un historial: se borra a las 24 horas.
CREATE TABLE analisis (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(2048) NOT NULL,
    es_local TINYINT(1) NOT NULL DEFAULT 0,
    estado ENUM('rastreando', 'cierre', 'terminado') NOT NULL DEFAULT 'rastreando',
    robots MEDIUMTEXT NULL,
    pagespeed MEDIUMTEXT NULL,
    creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Las páginas de un análisis. Las pendientes son la cola del rastreo.
CREATE TABLE paginas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analisis_id INT UNSIGNED NOT NULL,
    url VARCHAR(2048) NOT NULL,
    url_hash CHAR(40) NOT NULL,
    profundidad SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    estado ENUM('pendiente', 'hecha', 'omitida') NOT NULL DEFAULT 'pendiente',
    codigo SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tiempo_ms INT UNSIGNED NOT NULL DEFAULT 0,
    datos MEDIUMTEXT NULL,
    UNIQUE KEY pagina_unica (analisis_id, url_hash),
    FOREIGN KEY (analisis_id) REFERENCES analisis (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- El resultado de cada punto de la checklist en cada página.
CREATE TABLE resultados (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pagina_id INT UNSIGNED NOT NULL,
    punto_id VARCHAR(10) NOT NULL,
    estado ENUM('cumple', 'no_cumple', 'aviso', 'sin_datos', 'no_aplica', 'pendiente') NOT NULL,
    detalle TEXT NOT NULL,
    FOREIGN KEY (pagina_id) REFERENCES paginas (id) ON DELETE CASCADE
) ENGINE=InnoDB;
