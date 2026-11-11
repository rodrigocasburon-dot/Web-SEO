<?php
/**
 * Buscador (v4): una de las dos únicas páginas (la otra es historial.php). Solo se escribe la URL:
 * se analiza a fondo esa página (assets/js/app.js, con PageSpeed) y después el sitio entero (assets/js/site.js).
 * Los resultados se pintan con textContent (nunca innerHTML con datos de la web analizada).
 * También muestra los informes guardados del historial (index.php?informe=ID).
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
// Pie: qué se guarda depende de si el historial (Fase 4) está activado
$appConfig = Config::load();
// v4 · Análisis rápido: portada + estas páginas principales; profundo: todas hasta el máximo
$quickPages = SitePlanner::QUICK_PAGES;
$maxPages = SitePlanner::maxPages($appConfig);
$storageNote = History::enabled($appConfig)
    ? 'se guarda un historial de los análisis (notas e informe completo) durante ' . History::months($appConfig) . ' meses.'
    : 'no se guarda ningún dato de los análisis.';
$i18n = [
    'areas' => $lang['areas'],
    'estados' => $lang['estados'],
    'severidades' => $lang['severidades'],
    'niveles' => $lang['niveles'],
    'nota_incompleta' => $lang['nota_incompleta'],
    'glosario' => $lang['glosario'],
    'revisiones_manuales' => $lang['revisiones_manuales'],
    // Pesos de la nota global (Scorer): solo para explicarla en el informe, no se recalcula nada
    'pesos_globales' => Scorer::GLOBAL_WEIGHTS,
    // Comprobaciones que llegan después, desde PageSpeed Insights
    // Nombre de cada comprobación (las páginas del sitio llegan sin textos para ocupar menos)
    'nombres_checks' => array_map(fn($c) => $c['nombre'], $lang['checks']),
    // v4 · Pistas para la revisión manual (src/ManualHints.php)
    'motivos_alt' => ManualHints::MOTIVOS,
    'psi_checks' => [
        'C08' => $lang['checks']['C08']['nombre'],
        'C14' => $lang['checks']['C14']['nombre'],
    ],
];
// Informe de sitio · recomendaciones agrupadas: nº máximo de páginas que se listan en cada una
$i18n['grupo_max_paginas'] = max(1, min(500, (int) ($appConfig['site_group_max_pages'] ?? 20)));
// Bloque de área de cada comprobación (al que más resta): C08 y C14 mientras se esperan y la matriz páginas × puntos
foreach (CheckRunner::catalog() as $checkId => $check) {
    $i18n['bloque_checks'][$checkId] = Report::primaryArea($check->describe(BaseCheck::NO_DATA)['pilares']);
}
foreach (array_keys($i18n['psi_checks']) as $psiId) {
    $i18n['psi_bloque'][$psiId] = $i18n['bloque_checks'][$psiId];
}
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
<title>Radar web de Proconsi: SEO, GEO y UX</title>
<meta name="description" content="Herramienta interna de Proconsi que analiza una web entera y evalúa de forma automática su SEO, su GEO (preparación para buscadores de IA) y su UX (accesibilidad y experiencia de uso).">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= $asset('assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/componentes.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/graficos.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/proconsi.css') ?>">
<script src="<?= $asset('assets/js/export.js') ?>" defer></script>
<script src="<?= $asset('assets/js/compare.js') ?>" defer></script>
<script src="<?= $asset('assets/js/rw-componentes.js') ?>" defer></script>
<script src="<?= $asset('assets/js/site.js') ?>" defer></script>
<script src="<?= $asset('assets/js/pdf.js') ?>" defer></script>
<script src="<?= $asset('assets/js/app.js') ?>" defer></script>
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
      <a href="./" aria-current="page">Analizar</a>
      <a href="requisitos.php">Requisitos</a>
      <a href="historial.php">Historial</a>
    </nav>
    <p class="site-header__tag"><span aria-hidden="true"></span>Herramienta interna</p>
  </div>
</header>

<main id="contenido" class="container" tabindex="-1">
  <div class="hero">
    <div class="hero__intro">
      <p class="eyebrow"><span aria-hidden="true"></span>Una URL. La web entera. Tres perspectivas.</p>
      <h1>Tu web tiene <span class="nowrap">mucho que <em>decir.</em></span></h1>
      <p class="lead">Escribe la dirección y analizamos esa página y el resto del sitio: cómo la encuentran los buscadores (SEO), cómo la entiende la IA (GEO) y cómo la usan las personas (UX).</p>

  <noscript><p class="notice notice--fail">Esta herramienta necesita JavaScript activado para funcionar.</p></noscript>

  <form id="analyze-form" class="analyze-form" novalidate>
    <div class="field field--url">
      <label for="url">Empecemos por tu URL</label>
      <div class="url-row">
        <span class="url-row__icon" aria-hidden="true">↗</span>
        <input id="url" name="url" type="text" inputmode="url" autocomplete="url" spellcheck="false"
               autocapitalize="off" required placeholder="https://www.tuweb.es" aria-describedby="url-help url-error">
        <button type="submit" class="button" id="submit">Analizar</button>
      </div>
      <p id="url-error" class="field-error" hidden></p>
      <p id="url-help" class="help">Primero sale la nota de esa página y, al terminar de recorrer el sitio, la del sitio entero. Si no indicas http o https, se usará https.</p>
    </div>
    <div class="search-options">
      <fieldset class="field search-mode">
        <legend>Tipo de análisis</legend>
        <div class="field--check">
          <input id="modo-rapido" name="modo" type="radio" value="rapido" checked aria-describedby="modo-rapido-help">
          <div>
            <label for="modo-rapido">Rápido</label>
            <p id="modo-rapido-help" class="help">La portada y <?= (int) $quickPages ?> páginas principales, repartidas por secciones. Unos 1–2 minutos.</p>
          </div>
        </div>
        <div class="field--check">
          <input id="modo-profundo" name="modo" type="radio" value="profundo" aria-describedby="modo-profundo-help">
          <div>
            <label for="modo-profundo">Profundo</label>
            <p id="modo-profundo-help" class="help">Todas las páginas del sitio (hasta <?= number_format($maxPages, 0, ',', '.') ?>). En webs grandes puede tardar bastante.</p>
          </div>
        </div>
      </fieldset>
    </div>
  </form>
    </div>
  </div>

  <section class="pillars" aria-label="Áreas del análisis">
    <article><span class="pillar__icon" aria-hidden="true">↗</span><div><p class="pillar__kicker">01 / Visibilidad</p><h2>SEO</h2><p>Los detalles que ayudan a encontrar tu página.</p></div></article>
    <article><span class="pillar__icon" aria-hidden="true">✳</span><div><p class="pillar__kicker">02 / Comprensión</p><h2>GEO</h2><p>Contenido que los buscadores de IA pueden leer y citar.</p></div></article>
    <article><span class="pillar__icon" aria-hidden="true">◎</span><div><p class="pillar__kicker">03 / Experiencia</p><h2>UX</h2><p>Accesibilidad y experiencia de uso para todas las personas.</p></div></article>
  </section>

  <!-- v4: 1) tarjeta de la URL y primera nota (app.js); debajo, qué se está analizando -->
  <section id="results" class="results rw" aria-labelledby="results-title" hidden></section>

  <div id="status" class="status" role="status" aria-live="polite"></div>
  <div id="error" class="notice notice--fail" role="alert" tabindex="-1" hidden></div>

  <!-- v4: el sitio entero (lo pinta assets/js/site.js) -->
  <section id="site-section" class="results site-section" aria-label="Análisis del sitio entero" hidden>
    <div id="site-status" class="status" role="status" aria-live="polite"></div>
    <div id="site-progress-wrap" class="no-print" hidden>
      <label for="site-progress" class="visually-hidden">Páginas del sitio analizadas</label>
      <progress id="site-progress" class="site-progress" max="1" value="0"></progress>
      <p><button type="button" class="button button--secondary" id="site-stop">Terminar ya y ver el informe con lo analizado</button></p>
    </div>
    <div id="site-error-box" class="notice notice--warn" role="alert" hidden></div>
    <div id="site-results" class="rw" aria-labelledby="site-results-title" hidden></div>
  </section>

  <!-- v4: descargas, siempre al final (las de la URL las pone app.js; el CSV de las páginas, site.js) -->
  <section id="descargas" class="results downloads no-print" aria-labelledby="descargas-title" hidden>
    <h2 id="descargas-title">Descargas</h2>
    <div id="descargas-url"></div>
    <div id="descargas-sitio"></div>
    <p class="help">Los archivos se generan en tu navegador y no pasan por el servidor. Los CSV se abren directamente en Excel.</p>
  </section>

  <section id="metodo" class="method" aria-labelledby="method-title">
    <div>
      <p class="eyebrow">De la URL al siguiente paso</p>
      <h2 id="method-title">Menos dudas. Más acción.</h2>
    </div>
    <ol>
      <li><span class="method__n" aria-hidden="true">01</span><div><h3>Escribe la URL</h3><p>Nada más: sin opciones ni variables.</p></div></li>
      <li><span class="method__n" aria-hidden="true">02</span><div><h3>Analizamos la web entera</h3><p>Esa página a fondo y todas las del sitio, en SEO, GEO y UX.</p></div></li>
      <li><span class="method__n" aria-hidden="true">03</span><div><h3>Prioriza las mejoras</h3><p>Evidencias y recomendaciones claras.</p></div></li>
    </ol>
  </section>

  <!-- Texto fijo, plegado al final de la pantalla (también al final del informe) -->
  <section id="limitaciones" class="limitations" aria-labelledby="limitations-title">
    <!-- El título va fuera del <summary> para la navegación por encabezados; el <summary> repite el texto -->
    <h2 id="limitations-title" class="visually-hidden">Limitaciones</h2>
    <details class="fold">
      <summary><span class="fold__title fold__title--h2">Limitaciones</span></summary>
      <div class="fold__body">
        <p><?= $e($lang['limitaciones']) ?></p>
        <p><?= $e($lang['revision_manual']) ?></p>
      </div>
    </details>
  </section>
</main>

<footer class="site-footer">
  <div class="site-footer__inner">
    <img class="site-footer__logo" src="<?= $asset('assets/img/logo-proconsi.png') ?>" alt="Proconsi" width="96" height="33">
    <p class="site-footer__name">Radar web</p>
    <p>Evaluación automática y parcial. No sustituye una auditoría manual. Herramienta interna: <?= htmlspecialchars($storageNote, ENT_QUOTES, 'UTF-8') ?></p>
  </div>
</footer>
<script type="application/json" id="i18n"><?= json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</body>
</html>
