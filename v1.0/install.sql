-- Crea la base de datos de la herramienta. Se importa una sola vez desde phpMyAdmin.
CREATE DATABASE IF NOT EXISTS web_seo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE web_seo;

-- Un análisis por cada URL enviada. No es un historial: se borra a las 24 horas.
CREATE TABLE IF NOT EXISTS analisis (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(2048) NOT NULL,
    es_local TINYINT(1) NOT NULL DEFAULT 0,
    creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Cada página visitada dentro de un análisis.
CREATE TABLE IF NOT EXISTS paginas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analisis_id INT UNSIGNED NOT NULL,
    url VARCHAR(2048) NOT NULL,
    codigo SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tiempo_ms INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (analisis_id) REFERENCES analisis (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- El resultado de cada punto de la checklist en cada página.
CREATE TABLE IF NOT EXISTS resultados (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pagina_id INT UNSIGNED NOT NULL,
    punto_id VARCHAR(10) NOT NULL,
    estado ENUM('cumple', 'no_cumple', 'aviso', 'sin_datos', 'no_aplica', 'pendiente') NOT NULL,
    detalle TEXT NOT NULL,
    FOREIGN KEY (pagina_id) REFERENCES paginas (id) ON DELETE CASCADE
) ENGINE=InnoDB;
