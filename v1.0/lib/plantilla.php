<?php
// Cabecera y pie comunes de todas las pantallas.

function h(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

function cabecera(string $titulo, ?int $recargar = null): void
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
    <?php if ($recargar !== null): ?><meta http-equiv="refresh" content="<?= $recargar ?>"><?php endif; ?>
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

// Con $cerrar en false deja el documento abierto para seguir enviando después.
function pie(bool $cerrar = true): void
{
    ?>
    </main>
    <footer class="pie">Herramienta interna de Proconsi. Evaluación automática orientativa: no sustituye a una auditoría profesional.</footer>
    <?php if ($cerrar): ?>
</body>
</html>
    <?php endif;
}

// $paso es la fase en curso, de 1 a 3. Sin total, la barra no indica cantidad.
function pantalla_carga(string $url, int $paso, string $texto, int $hechas = 0, int $total = 0, bool $oculta = false): void
{
    $pasos = [1 => 'Conectando con la web', 2 => 'Analizando las páginas', 3 => 'Últimas comprobaciones e informe'];
    ?>
    <section class="inicio carga" id="carga"<?= $oculta ? ' hidden' : '' ?>>
        <div class="carga-giro" aria-hidden="true"></div>
        <p class="etiqueta">Analizando</p>
        <h1 class="url" id="carga-url"><?= h($url) ?></h1>
        <ol class="pasos">
            <?php foreach ($pasos as $numero => $nombre): ?>
                <li class="<?= $numero < $paso ? 'hecho' : ($numero === $paso ? 'actual' : '') ?>"<?= $numero === $paso ? ' aria-current="step"' : '' ?>><?= h($nombre) ?></li>
            <?php endforeach; ?>
        </ol>
        <p role="status"><?= h($texto) ?></p>
        <progress class="barra-progreso" aria-label="Progreso del análisis"<?= $total > 0 ? " max=\"$total\" value=\"$hechas\"" : '' ?>></progress>
        <p class="ayuda">Una web grande puede tardar horas. Si cierras esta pestaña se pausa: vuelve a esta misma dirección para seguir.</p>
    </section>
    <?php
}
