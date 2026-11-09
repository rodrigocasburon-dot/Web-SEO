<?php
/**
 * v3.0 · Pantalla del modo «sitio completo».
 * Analiza la portada y una muestra de páginas del mismo sitio en paralelo (assets/js/site.js).
 * Los resultados se pintan con textContent (nunca innerHTML con datos de la web analizada).
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
$config = Config::load();
$maxPages = max(1, min(500, (int) ($config['site_max_pages'] ?? 50)));
$i18n = [
    'areas' => $lang['areas'],
    'estados' => $lang['estados'],
    'severidades' => $lang['severidades'],
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
<title>Analizar un sitio completo · Radar web de Proconsi</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= $asset('assets/css/app.css') ?>">
<script src="<?= $asset('assets/js/site.js') ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#contenido">Saltar al contenido</a>
<header class="site-header">
  <div class="site-header__inner">
    <a class="brand" href="./">
      <img class="brand__logo" src="<?= $asset('assets/img/logo-proconsi.png') ?>" alt="Proconsi" width="144" height="50">
      <span class="brand__name">Radar web</span>
    </a>
    <nav class="site-nav" aria-label="Navegación principal">
      <a href="./">Analizar una URL</a>
      <a href="sitio.php" aria-current="page">Analizar sitio</a>
      <a href="historial.php">Historial</a>
    </nav>
    <p class="site-header__tag"><span aria-hidden="true"></span>Herramienta interna</p>
  </div>
</header>

<main id="contenido" class="container" tabindex="-1">
  <div class="hero hero--compact">
    <p class="eyebrow"><span aria-hidden="true"></span>Sitio completo, en minutos</p>
    <h1>Analiza un sitio completo</h1>
    <p class="lead">Se analiza la portada entera y una muestra de hasta <?= $maxPages ?> páginas del mismo sitio, elegidas de sus enlaces y de su sitemap repartiendo entre secciones. Las páginas se analizan a la vez, de <?= max(1, min(10, (int) ($config['site_parallel'] ?? 6))) ?> en <?= max(1, min(10, (int) ($config['site_parallel'] ?? 6))) ?>.</p>

    <noscript><p class="notice notice--fail">Esta herramienta necesita JavaScript activado para funcionar.</p></noscript>

    <form id="site-form" class="analyze-form no-print" novalidate>
      <div class="field field--url">
        <label for="site-url">Dirección del sitio</label>
        <div class="url-row">
          <span class="url-row__icon" aria-hidden="true">↗</span>
          <input id="site-url" name="url" type="text" inputmode="url" autocomplete="url" spellcheck="false"
                 autocapitalize="off" required placeholder="https://www.tuweb.es" aria-describedby="site-help site-error">
          <button type="submit" class="button" id="site-submit">Analizar sitio</button>
        </div>
        <p id="site-error" class="field-error" hidden></p>
        <p id="site-help" class="help">Suele tardar entre 30 segundos y 2 minutos. Cuenta como un análisis en el límite de uso.</p>
      </div>
      <div class="field field--check">
        <input id="site-noindex" name="noindex_intentional" type="checkbox">
        <label for="site-noindex">Hay páginas con <span lang="en">noindex</span> a propósito</label>
      </div>
    </form>
  </div>

  <div id="site-status" class="status" role="status" aria-live="polite"></div>
  <div id="site-progress-wrap" class="no-print" hidden>
    <label for="site-progress" class="visually-hidden">Páginas analizadas</label>
    <progress id="site-progress" class="site-progress" max="1" value="0"></progress>
    <p><button type="button" class="button button--secondary" id="site-stop">Terminar ya y ver el informe con lo analizado</button></p>
  </div>
  <div id="site-error-box" class="notice notice--fail" role="alert" tabindex="-1" hidden></div>

  <section id="site-results" class="results" aria-labelledby="site-results-title" hidden></section>

  <section class="limitations" aria-labelledby="site-limits-title">
    <h2 id="site-limits-title">Limitaciones del modo sitio</h2>
    <ul>
      <li>Es una <strong>muestra</strong> de páginas, no todas: sirve para ver los fallos que se repiten en el sitio. Para revisar una página concreta, usa «Analizar una URL».</li>
      <li>La nota del sitio es la media de las notas de las páginas analizadas. Enlaces rotos (C16) y sitemap (C18) solo se comprueban en la portada.</li>
      <li>Contraste y Core Web Vitals (PageSpeed) y las pruebas con Chrome solo se hacen en la portada y no cambian la nota media.</li>
      <li><?= $e($lang['revision_manual']) ?></li>
    </ul>
  </section>
</main>

<footer class="site-footer">
  <div class="site-footer__inner">
    <img class="site-footer__logo" src="<?= $asset('assets/img/logo-proconsi.png') ?>" alt="Proconsi" width="96" height="33">
    <p class="site-footer__name">Radar web</p>
    <p>Evaluación automática y parcial. No sustituye una auditoría manual. <?= History::enabled($config) ? 'Se guarda un historial de los análisis (notas e informe completo) durante ' . History::months($config) . ' meses.' : 'No se guarda ningún dato de los análisis de sitio.' ?></p>
  </div>
</footer>
<script type="application/json" id="i18n"><?= json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</body>
</html>
