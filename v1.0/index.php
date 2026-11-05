<?php
require 'lib/db.php';
require 'lib/descarga.php';
require 'lib/pagina.php';
require 'lib/motor.php';
require 'lib/plantilla.php';

$url = trim($_POST['url'] ?? '');
$es_local = isset($_POST['es_local']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(120);
    if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        $url = 'https://' . $url;
    }
    try {
        $config = cargar_config();
        $db = conectar($config);
        $pagina = crear_pagina(descargar($url, $config), $url, $es_local, $config);
        $id = guardar_analisis($db, $pagina, cargar_checklist());
        header("Location: resultado.php?id=$id");
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

cabecera('Auditoría web');
?>
<section class="inicio">
    <h1>Analiza una web</h1>
    <p>Escribe una dirección y obtén su nota de SEO, GEO y UX, con los puntos que faltan por mejorar.</p>
    <?php if ($error): ?>
        <p class="error" role="alert"><?= h($error) ?></p>
    <?php endif; ?>
    <form method="post">
        <label for="url">Dirección de la web</label>
        <div class="fila">
            <input id="url" name="url" type="text" inputmode="url" required placeholder="https://www.ejemplo.es" value="<?= h($url) ?>">
            <button type="submit">Analizar</button>
        </div>
        <label class="casilla">
            <input type="checkbox" name="es_local" <?= $es_local ? 'checked' : '' ?>>
            Es un negocio local
        </label>
        <p class="ayuda">Márcala si la empresa tiene un local físico. Si no, los puntos de dirección, horarios o mapa no cuentan en la nota.</p>
    </form>
</section>
<?php
pie();
