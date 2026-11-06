<?php
// Enseña la pantalla de carga, hace una tanda de trabajo y se recarga sola hasta terminar.
require 'lib/carga.php';

set_time_limit(240);
$config = cargar_config();
$db = conectar($config);
$id = (int) ($_GET['id'] ?? 0);

$consulta = $db->prepare('SELECT url, estado FROM analisis WHERE id = ?');
$consulta->execute([$id]);
$analisis = $consulta->fetch(PDO::FETCH_ASSOC);
if (!$analisis) {
    header('Location: index.php');
    exit;
}
if ($analisis['estado'] === 'terminado') {
    header("Location: resultado.php?id=$id");
    exit;
}

// Terminar ya: se deja de rastrear y el informe sale con lo analizado hasta ahora.
if (isset($_GET['terminar']) && $analisis['estado'] === 'rastreando') {
    $db->prepare('UPDATE analisis SET sitemap = 1 WHERE id = ?')->execute([$id]);
    $db->prepare("UPDATE paginas SET estado = 'omitida' WHERE analisis_id = ? AND estado = 'pendiente'")->execute([$id]);
    header("Location: progreso.php?id=$id");
    exit;
}

$consulta = $db->prepare("SELECT COUNT(*) AS total, SUM(estado <> 'pendiente') AS hechas FROM paginas WHERE analisis_id = ?");
$consulta->execute([$id]);
$cuenta = $consulta->fetch(PDO::FETCH_ASSOC);
$hechas = (int) $cuenta['hechas'];
$total = (int) $cuenta['total'];

// La recarga larga es una red de seguridad: si PHP se corta, la pantalla no se queda colgada.
cabecera('Analizando', 300);
$ultimas = [
    'navegador' => 'Probando la web en Chrome: teclado, zoom, formularios y cookies. Puede tardar un par de minutos.',
    'ia' => 'Revisando imágenes y contenido con IA. Puede tardar un minuto.',
    'cierre' => 'Revisando enlaces rotos y velocidad. Puede tardar hasta un minuto.',
];
if (isset($ultimas[$analisis['estado']])) {
    pantalla_carga($analisis['url'], 3, $ultimas[$analisis['estado']], $total, $total);
} else {
    pantalla_carga($analisis['url'], 2, "$hechas de $total páginas encontradas hasta ahora.", $hechas, $total);
    echo '<p class="inicio"><a href="' . h("progreso.php?id=$id&terminar=1") . '">Terminar ya y ver el informe con lo analizado</a></p>';
}
pie(false);

// La pantalla sale ya, antes de ponerse a trabajar.
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();

try {
    $destino = hacer_tanda($db, $id, $config) === 'terminado' ? "resultado.php?id=$id" : "progreso.php?id=$id";
    echo '<meta http-equiv="refresh" content="0;url=' . h($destino) . '">';
} catch (Throwable) {
    echo '<p class="error" role="alert">El análisis se ha interrumpido. <a href="' . h("progreso.php?id=$id") . '">Reintentar</a></p>';
}
?>
</body>
</html>
