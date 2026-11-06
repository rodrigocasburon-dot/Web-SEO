<?php
/**
 * Pantalla única: formulario, indicador de carga y resultados.
 * Los resultados los pinta assets/js/app.js con textContent (nunca innerHTML con datos de la web analizada).
 */
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: https:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

$lang = Lang::all();
$i18n = [
    'areas' => $lang['areas'],
    'estados' => $lang['estados'],
    'severidades' => $lang['severidades'],
    'niveles' => $lang['niveles'],
    'nota_incompleta' => $lang['nota_incompleta'],
    'glosario' => $lang['glosario'],
    // Comprobaciones que llegan después, desde PageSpeed Insights
    'psi_checks' => [
        'C08' => $lang['checks']['C08']['nombre'],
        'C14' => $lang['checks']['C14']['nombre'],
    ],
];
$asset = function (string $path): string {
    $file = __DIR__ . '/' . $path;
    return htmlspecialchars($path . '?v=' . (is_file($file) ? filemtime($file) : '0'), ENT_QUOTES, 'UTF-8');
};
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Analizador web: SEO, IA y accesibilidad</title>
<meta name="description" content="Herramienta interna de Proconsi que analiza una URL y evalúa de forma automática su SEO, su preparación para buscadores de IA y su accesibilidad.">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= $asset('assets/css/app.css') ?>">
<script src="<?= $asset('assets/js/app.js') ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#contenido">Saltar al contenido</a>
<header class="site-header">
  <div class="container">
    <p class="brand">Analizador web <span class="brand__tag">Herramienta interna de Proconsi</span></p>
  </div>
</header>

<main id="contenido" class="container" tabindex="-1">
  <h1>Analizador de SEO, preparación para IA y accesibilidad</h1>
  <p class="intro">Escribe la dirección de una página para obtener una evaluación automática con una nota por área, los hallazgos con su evidencia y recomendaciones.</p>

  <noscript><p class="notice notice--fail">Esta herramienta necesita JavaScript activado para funcionar.</p></noscript>

  <form id="analyze-form" class="analyze-form" novalidate>
    <div class="field">
      <label for="url">URL de la página</label>
      <p id="url-help" class="help">Por ejemplo: https://www.ejemplo.com/servicios. Si no indicas http o https, se usará https.</p>
      <input id="url" name="url" type="text" inputmode="url" autocomplete="url" spellcheck="false"
             autocapitalize="off" required aria-describedby="url-help url-error">
      <p id="url-error" class="field-error" hidden></p>
    </div>
    <div class="field">
      <label for="keyword">Palabra clave objetivo (opcional)</label>
      <p id="keyword-help" class="help">Por ejemplo: software de gestión. Se comprueba si aparece en el title, el H1, la meta description, la URL y las primeras 100 palabras. No puntúa.</p>
      <input id="keyword" name="palabra_clave" type="text" maxlength="100" autocomplete="off" aria-describedby="keyword-help">
    </div>
    <div class="field field--check">
      <input id="noindex" name="noindex_intentional" type="checkbox">
      <label for="noindex">La página tiene <span lang="en">noindex</span> a propósito</label>
    </div>
    <button type="submit" class="button" id="submit">Analizar</button>
  </form>

  <div id="status" class="status" role="status" aria-live="polite"></div>
  <div id="error" class="notice notice--fail" role="alert" tabindex="-1" hidden></div>

  <section id="results" class="results" aria-labelledby="results-title" hidden></section>

  <!-- Texto fijo y siempre visible; con un informe en pantalla es su apartado 8 -->
  <section id="limitaciones" class="limitations" aria-labelledby="limitations-title">
    <h2 id="limitations-title">Limitaciones</h2>
    <p><?= $e($lang['limitaciones']) ?></p>
    <p><?= $e($lang['revision_manual']) ?></p>
  </section>

  <!-- Apartado 9 del informe: datos de la descarga y de las consultas -->
  <section id="evidencias" class="report-evidence" aria-labelledby="evidence-title" hidden></section>
</main>

<footer class="site-footer">
  <div class="container site-footer__inner">
    <img class="site-footer__logo" src="<?= $asset('assets/img/logo-proconsi.webp') ?>" alt="Proconsi" width="96" height="33">
    <p>Herramienta interna. No se guarda ningún dato de los análisis.</p>
  </div>
</footer>
<script type="application/json" id="i18n"><?= json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</body>
</html>
