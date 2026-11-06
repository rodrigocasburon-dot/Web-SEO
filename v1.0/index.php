<?php
require 'lib/carga.php';

$url = trim($_POST['url'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(120);
    if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        $url = 'https://' . $url;
    }
    try {
        $config = cargar_config();
        $id = iniciar_analisis(conectar($config), $url, $config);
        header("Location: progreso.php?id=$id");
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

cabecera('Auditoría web');
?>
<section class="inicio" id="formulario">
    <h1>Analiza una web</h1>
    <p>Escribe una dirección y obtén su nota de SEO, GEO y UX, con los puntos que faltan por mejorar. La herramienta recorre todas las páginas que encuentra.</p>
    <?php if ($error): ?>
        <p class="error" role="alert"><?= h($error) ?></p>
    <?php endif; ?>
    <form method="post">
        <label for="url">Dirección de la web</label>
        <div class="fila">
            <input id="url" name="url" type="text" inputmode="url" required placeholder="https://www.ejemplo.es" value="<?= h($url) ?>">
            <button type="submit">Analizar</button>
        </div>
    </form>
</section>
<?php pantalla_carga('', 1, 'Abriendo la primera página. Puede tardar unos segundos.', oculta: true); ?>
<script>
    // La primera descarga se hace al enviar el formulario: la pantalla de carga cubre esa espera.
    function verCarga(activa) {
        document.getElementById('formulario').hidden = activa;
        document.getElementById('carga').hidden = !activa;
    }
    document.querySelector('form').addEventListener('submit', function () {
        document.getElementById('carga-url').textContent = document.getElementById('url').value;
        verCarga(true);
    });
    // Al volver atrás, el navegador podría restaurar la pantalla de carga.
    window.addEventListener('pageshow', function () { verCarga(false); });
</script>
<?php
pie();
