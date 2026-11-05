<?php
// Cada carga de esta página hace una tanda de trabajo y se recarga sola hasta terminar.
require 'lib/carga.php';

set_time_limit(240);
$config = cargar_config();
$db = conectar($config);
$id = (int) ($_GET['id'] ?? 0);

$consulta = $db->prepare('SELECT url FROM analisis WHERE id = ?');
$consulta->execute([$id]);
$url = $consulta->fetchColumn();
if ($url === false) {
    header('Location: index.php');
    exit;
}

$estado = hacer_tanda($db, $id, $config);
if ($estado === 'terminado') {
    header("Location: resultado.php?id=$id");
    exit;
}

$consulta = $db->prepare("SELECT COUNT(*) AS total, SUM(estado <> 'pendiente') AS hechas FROM paginas WHERE analisis_id = ?");
$consulta->execute([$id]);
$cuenta = $consulta->fetch(PDO::FETCH_ASSOC);

cabecera('Analizando', 0);
?>
<section class="inicio">
    <p class="etiqueta">Analizando</p>
    <h1 class="url"><?= h($url) ?></h1>
    <?php if ($estado === 'cierre'): ?>
        <p role="status">Últimas comprobaciones: enlaces rotos y velocidad. Puede tardar hasta un minuto.</p>
    <?php else: ?>
        <p role="status"><?= (int) $cuenta['hechas'] ?> de <?= (int) $cuenta['total'] ?> páginas encontradas. Máximo: <?= (int) $config['paginas_maximas'] ?>.</p>
    <?php endif; ?>
    <progress class="barra-progreso" max="<?= (int) $cuenta['total'] ?>" value="<?= (int) $cuenta['hechas'] ?>" aria-label="Progreso del análisis"></progress>
    <p class="ayuda">No cierres esta pestaña. La página se actualiza sola.</p>
</section>
<?php
pie();
