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
// Pie: qué se guarda depende de si el historial (Fase 4) está activado
$appConfig = Config::load();
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
    'psi_checks' => [
        'C08' => $lang['checks']['C08']['nombre'],
        'C14' => $lang['checks']['C14']['nombre'],
    ],
];
// Bloque de área en el que se mostrarán C08 y C14 mientras se esperan
foreach (array_keys($i18n['psi_checks']) as $psiId) {
    $i18n['psi_bloque'][$psiId] = Report::primaryArea(CheckRunner::catalog()[$psiId]->describe(BaseCheck::NO_DATA)['pilares']);
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
<title>Radar web de Proconsi: SEO, IA y accesibilidad</title>
<meta name="description" content="Herramienta interna de Proconsi que analiza una URL y evalúa de forma automática su SEO, su preparación para buscadores de IA y su accesibilidad.">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= $asset('assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/componentes.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/graficos.css') ?>">
<script src="<?= $asset('assets/js/export.js') ?>" defer></script>
<script src="<?= $asset('assets/js/compare.js') ?>" defer></script>
<script src="<?= $asset('assets/js/rw-componentes.js') ?>" defer></script>
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
      <a href="#analyze-form" aria-current="page">Analizar URL</a>
      <a href="sitio.php">Analizar sitio</a>
      <a href="historial.php">Historial</a>
      <a href="#metodo">Cómo funciona</a>
    </nav>
    <p class="site-header__tag"><span aria-hidden="true"></span>Herramienta interna</p>
  </div>
</header>

<main id="contenido" class="container" tabindex="-1">
  <div class="hero">
    <div class="hero__intro">
      <p class="eyebrow"><span aria-hidden="true"></span>Una URL. Tres perspectivas.</p>
      <h1>Tu web tiene <span class="nowrap">mucho que <em>decir.</em></span></h1>
      <p class="lead">Descubre cómo la encuentran los buscadores, cómo la interpreta la IA y cómo la usan las personas.</p>

  <noscript><p class="notice notice--fail">Esta herramienta necesita JavaScript activado para funcionar.</p></noscript>

  <form id="analyze-form" class="analyze-form" novalidate>
    <div class="field field--url">
      <label for="url">Empecemos por tu URL</label>
      <div class="url-row">
        <span class="url-row__icon" aria-hidden="true">↗</span>
        <input id="url" name="url" type="text" inputmode="url" autocomplete="url" spellcheck="false"
               autocapitalize="off" required placeholder="https://www.tuweb.es" aria-describedby="url-help url-error">
        <button type="submit" class="button" id="submit">Analizar URL</button>
      </div>
      <p id="url-error" class="field-error" hidden></p>
      <p id="url-help" class="help">Una página por análisis · SEO, IA y accesibilidad. Si no indicas http o https, se usará https.</p>
    </div>
    <div class="field" id="url2-field" hidden>
      <label for="url2">Segunda URL</label>
      <p id="url2-help" class="help">Cada URL se analiza con su propia petición, así que una comparación cuenta como dos análisis en el límite de uso.</p>
      <input id="url2" name="url2" type="text" inputmode="url" autocomplete="url" spellcheck="false"
             autocapitalize="off" aria-describedby="url2-help url2-error">
      <p id="url2-error" class="field-error" hidden></p>
    </div>
    <div class="field field--check" id="local-field">
      <input id="local-business" name="negocio_local" type="checkbox" aria-describedby="local-help">
      <div>
        <label for="local-business">Es un negocio local</label>
        <p id="local-help" class="help">Se anota en el informe; de momento no cambia ninguna comprobación ni la nota.</p>
      </div>
    </div>
    <details class="more-options" id="more-options">
      <summary>Más opciones</summary>
      <div class="more-options__body">
        <fieldset class="field mode-switch">
          <legend>Tipo de análisis</legend>
          <div class="mode-switch__options">
            <div class="field--check">
              <input id="mode-single" name="modo" type="radio" value="una" checked>
              <label for="mode-single">Analizar una URL</label>
            </div>
            <div class="field--check">
              <input id="mode-compare" name="modo" type="radio" value="comparar">
              <label for="mode-compare">Comparar dos URLs</label>
            </div>
          </div>
        </fieldset>
        <div class="field" id="keyword-field">
          <label for="keyword">Palabra clave objetivo (opcional)</label>
          <p id="keyword-help" class="help">Por ejemplo: software de gestión. Se comprueba si aparece en el <span lang="en">title</span>, el H1, la <span lang="en">meta description</span>, la URL y las primeras 100 palabras. No puntúa.</p>
          <input id="keyword" name="palabra_clave" type="text" maxlength="100" autocomplete="off" aria-describedby="keyword-help">
        </div>
        <div class="field field--check">
          <input id="noindex" name="noindex_intentional" type="checkbox">
          <label for="noindex">La página tiene <span lang="en">noindex</span> a propósito</label>
        </div>
        <p id="noindex-compare" class="help" hidden>En la comparación, esta casilla se aplica a las dos URLs.</p>
      </div>
    </details>
  </form>
    </div>
    <div class="hero__art" aria-hidden="true">
      <img class="hero__characters" src="<?= $asset('assets/img/personajes.webp') ?>" alt="" width="1536" height="1024">
      <p class="float float--1"><span class="float__icon">↗</span><span>Más visible.<small>Para los buscadores.</small></span></p>
      <p class="float float--2"><span class="float__icon float__icon--dark">✳</span><span>Más comprensible.<small>Para la inteligencia artificial.</small></span></p>
      <p class="float float--3"><span class="float__icon">☺</span><span>Más accesible.<small>Para todas las personas.</small></span></p>
    </div>
  </div>

  <section class="pillars" aria-label="Áreas del análisis">
    <article><span class="pillar__icon" aria-hidden="true">↗</span><div><p class="pillar__kicker">01 / Visibilidad</p><h2>SEO</h2><p>Los detalles que ayudan a encontrar tu página.</p></div></article>
    <article><span class="pillar__icon" aria-hidden="true">✳</span><div><p class="pillar__kicker">02 / Comprensión</p><h2>Preparación para IA</h2><p>Contenido que las máquinas pueden interpretar.</p></div></article>
    <article><span class="pillar__icon" aria-hidden="true">◎</span><div><p class="pillar__kicker">03 / Experiencia</p><h2>Accesibilidad</h2><p>Una página más fácil de usar para las personas.</p></div></article>
  </section>

  <div id="status" class="status" role="status" aria-live="polite"></div>
  <div id="error" class="notice notice--fail" role="alert" tabindex="-1" hidden></div>

  <section id="results" class="results rw" aria-labelledby="results-title" hidden></section>

  <section id="metodo" class="method" aria-labelledby="method-title">
    <div>
      <p class="eyebrow">De la URL al siguiente paso</p>
      <h2 id="method-title">Menos dudas. Más acción.</h2>
    </div>
    <ol>
      <li><span class="method__n" aria-hidden="true">01</span><div><h3>Comparte una URL</h3><p>La página concreta que quieres revisar.</p></div></li>
      <li><span class="method__n" aria-hidden="true">02</span><div><h3>Conectamos las señales</h3><p>Comprobaciones técnicas en tres áreas.</p></div></li>
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

  <!-- Datos de la descarga y de las consultas (plegado; lo pinta app.js) -->
  <section id="evidencias" class="report-evidence" aria-labelledby="evidence-title" hidden></section>
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
