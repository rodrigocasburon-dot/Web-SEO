<?php
// Carga la configuración y conecta con MySQL.

function cargar_config(): array
{
    $archivo = __DIR__ . '/../config.php';
    if (!is_file($archivo)) {
        throw new RuntimeException('Falta config.php. Copia config.example.php con ese nombre.');
    }
    return require $archivo;
}

function conectar(array $config): PDO
{
    $dsn = "mysql:host={$config['db_host']};port={$config['db_puerto']};dbname={$config['db_nombre']};charset=utf8mb4";
    try {
        return new PDO($dsn, $config['db_usuario'], $config['db_clave'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException) {
        throw new RuntimeException('No se puede conectar con MySQL. Revisa config.php y que hayas importado install.sql.');
    }
}

function guardar_analisis(PDO $db, array $pagina, array $checklist): int
{
    // Sin historial: los análisis antiguos se borran solos.
    $db->exec('DELETE FROM analisis WHERE creado < NOW() - INTERVAL 1 DAY');

    $db->beginTransaction();
    $db->prepare('INSERT INTO analisis (url, es_local) VALUES (?, ?)')
        ->execute([$pagina['url_pedida'], (int) $pagina['es_local']]);
    $analisis_id = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO paginas (analisis_id, url, codigo, tiempo_ms) VALUES (?, ?, ?, ?)')
        ->execute([$analisis_id, $pagina['url'], $pagina['codigo'], $pagina['tiempo_ms']]);
    $pagina_id = (int) $db->lastInsertId();

    $insertar = $db->prepare('INSERT INTO resultados (pagina_id, punto_id, estado, detalle) VALUES (?, ?, ?, ?)');
    foreach ($checklist['puntos'] as $punto) {
        $resultado = ejecutar_punto($punto, $pagina);
        $insertar->execute([$pagina_id, $punto['id'], $resultado['estado'], $resultado['detalle']]);
    }
    $db->commit();
    return $analisis_id;
}
