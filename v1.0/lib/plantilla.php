<?php
// Cabecera y pie comunes de todas las pantallas.

function h(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

function cabecera(string $titulo): void
{
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($titulo) ?> · Proconsi</title>
    <meta name="description" content="Herramienta interna de Proconsi para auditar webs en SEO, GEO y UX.">
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
    <a class="saltar" href="#contenido">Saltar al contenido</a>
    <header class="barra">
        <a href="index.php"><img src="assets/img/logo-proconsi.png" alt="Proconsi, ir al inicio" width="166" height="56"></a>
        <span>Auditoría web</span>
    </header>
    <main id="contenido">
    <?php
}

function pie(): void
{
    ?>
    </main>
    <footer class="pie">Herramienta interna de Proconsi. Evaluación automática orientativa: no sustituye a una auditoría profesional.</footer>
</body>
</html>
    <?php
}
