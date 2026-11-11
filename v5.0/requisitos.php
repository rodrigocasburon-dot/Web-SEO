<?php
/**
 * Requisitos (v4): todas las comprobaciones que hace la herramienta (C01, C02…), agrupadas por el área a la que
 * más restan (SEO, GEO, UX), con qué miran, su gravedad, cómo se reparte su penalización, la referencia en la que
 * se basan y la recomendación general. Solo lectura y sin JavaScript: los datos salen de las propias clases de
 * src/Checks/ y de lang/es.php, así que esta página no puede quedar desactualizada. Todo se escapa al imprimir.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

$lang = Lang::all();
$e = fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$asset = function (string $path): string {
    $file = __DIR__ . '/' . $path;
    return htmlspecialchars($path . '?v=' . (is_file($file) ? filemtime($file) : '0'), ENT_QUOTES, 'UTF-8');
};

// De dónde sale cada dato cuando no es el HTML de la página
$origin = [
    'C08' => 'PageSpeed Insights (Google), solo en la página escrita.',
    'C14' => 'PageSpeed Insights (Google), solo en la página escrita.',
    'C16' => 'Todo el sitio: se comprueba en la portada.',
    'C18' => 'Todo el sitio: se comprueba en la portada.',
];
$backing = [
    'oficial' => 'Documentación oficial de los buscadores.',
    'experimento' => 'Estudios y experimentos publicados; no es una norma oficial.',
];

$groups = ['seo' => [], 'ia' => [], 'accesibilidad' => []];
foreach (CheckRunner::catalog() as $id => $check) {
    $d = $check->describe(BaseCheck::NO_DATA);
    $text = $lang['checks'][$id] ?? [];
    $groups[Report::primaryArea($d['pilares'])][$id] = [
        'nombre' => $text['nombre'] ?? $d['nombre'],
        'descripcion' => $text['descripcion'] ?? '',
        'recomendacion' => $text['recomendacion'] ?? '',
        'pilares' => $d['pilares'],
        'severidad' => $d['severidad'],
        'criterio' => $d['criterio'] ?? '',
        'impacto' => $d['impacto'] ?? '',
        'esfuerzo' => $d['esfuerzo'] ?? '',
        'manual' => !empty($d['requiere_revision_manual']),
        'respaldo' => $d['respaldo'] ?? null,
    ];
}
foreach ($groups as &$list) {
    ksort($list, SORT_NATURAL);
}
unset($list);
$intro = [
    'seo' => 'Lo que ayuda a que los buscadores encuentren, entiendan y muestren bien la página.',
    'ia' => 'Lo que ayuda a que los buscadores y asistentes de IA puedan leer la página y citarla. No mide si de verdad la citan.',
    'accesibilidad' => 'Lo que permite que todas las personas puedan usar la página, también con lector de pantalla, teclado o en el móvil.',
];
$total = array_sum(array_map('count', $groups));
$pct = fn(float $v): string => round($v * 100) . ' %';
$split = fn(array $pilares): string => implode(' · ', array_map(fn($a) => $lang['areas'][$a] . ' ' . $pct((float) ($pilares[$a] ?? 0)), array_keys(array_filter($pilares, fn($w) => $w > 0))));

// Lo mismo en datos, para el PDF que se descarga (assets/js/requisitos.js con assets/js/pdf.js)
$pdf = [
    'total' => $total,
    'puntuacion' => [
        'Cada área (SEO, GEO y UX) parte de 100. Solo resta lo que no cumple; los avisos, lo que no aplica y lo que no se ha podido medir no restan.',
        'Lo que resta cada fallo depende de su gravedad: crítica ' . Scorer::PENALTIES['CRITICO'] . ', alta ' . Scorer::PENALTIES['ALTO'] . ', media ' . Scorer::PENALTIES['MEDIO'] . ' y baja ' . Scorer::PENALTIES['BAJO'] . ' puntos. Si se repite en la página, resta algo más, pero no el doble.',
        'Un fallo resta una sola vez, repartido entre las áreas como se indica en cada requisito.',
        'Nota global: ' . $pct(Scorer::GLOBAL_WEIGHTS['seo']) . ' SEO, ' . $pct(Scorer::GLOBAL_WEIGHTS['ia']) . ' GEO y ' . $pct(Scorer::GLOBAL_WEIGHTS['accesibilidad']) . ' UX.',
        $lang['revision_manual'],
    ],
    'areas' => [],
];
foreach ($groups as $area => $list) {
    $items = [];
    foreach ($list as $id => $r) {
        $line = 'Gravedad ' . mb_strtolower($lang['severidades'][$r['severidad']] ?? $r['severidad']) . ' (resta hasta ' . (int) (Scorer::PENALTIES[$r['severidad']] ?? 0) . ' puntos) · ' . $split($r['pilares']);
        $text = $r['descripcion'];
        if (isset($origin[$id])) {
            $text .= ' ' . $origin[$id];
        }
        if ($r['criterio'] !== '') {
            $text .= ' En qué se basa: ' . $r['criterio'] . ($r['respaldo'] && isset($backing[$r['respaldo']]) ? ' ' . $backing[$r['respaldo']] : '');
        }
        if ($r['manual']) {
            $text .= ' Además, una persona debe revisarlo.';
        }
        $items[] = ['titulo' => $id . ' · ' . $r['nombre'], 'estado' => 'REQUISITO', 'linea' => $line, 'texto' => trim($text), 'arreglo' => $r['recomendacion']];
    }
    $pdf['areas'][] = ['nombre' => $lang['areas'][$area], 'intro' => $intro[$area], 'puntos' => $items];
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Requisitos que se comprueban · Radar web de Proconsi</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= $asset('assets/css/app.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/componentes.css') ?>">
<link rel="stylesheet" href="<?= $asset('assets/css/proconsi.css') ?>">
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
      <a href="./">Analizar</a>
      <a href="requisitos.php" aria-current="page">Requisitos</a>
      <a href="historial.php">Historial</a>
    </nav>
    <p class="site-header__tag"><span aria-hidden="true"></span>Herramienta interna</p>
  </div>
</header>

<main id="contenido" class="container" tabindex="-1">
  <div class="hero hero--compact">
    <p class="eyebrow">Qué se mira en cada análisis</p>
    <h1>Requisitos</h1>
    <p class="lead">Las <?= $total ?> comprobaciones automáticas que se hacen en cada página, agrupadas por el área a la que más restan. Ábrelas para ver qué miran, en qué se basan y cómo se arreglan.</p>
  </div>

  <section class="limitations" aria-labelledby="como-puntua">
    <h2 id="como-puntua">Cómo puntúan</h2>
    <ul>
      <li>Cada área (SEO, GEO y UX) parte de 100. Solo resta lo que <strong>no cumple</strong>; los avisos, lo que no aplica y lo que no se ha podido medir no restan.</li>
      <li>Lo que resta cada fallo depende de su gravedad: crítica <?= Scorer::PENALTIES['CRITICO'] ?>, alta <?= Scorer::PENALTIES['ALTO'] ?>, media <?= Scorer::PENALTIES['MEDIO'] ?> y baja <?= Scorer::PENALTIES['BAJO'] ?> puntos. Si se repite en la página, resta algo más, pero no el doble.</li>
      <li>Un fallo resta una sola vez, repartido entre las áreas como se indica en cada requisito.</li>
      <li>Nota global: <?= $pct(Scorer::GLOBAL_WEIGHTS['seo']) ?> SEO, <?= $pct(Scorer::GLOBAL_WEIGHTS['ia']) ?> GEO y <?= $pct(Scorer::GLOBAL_WEIGHTS['accesibilidad']) ?> UX.</li>
      <li><?= $e($lang['revision_manual']) ?></li>
    </ul>
  </section>

  <nav class="requisitos-indice" aria-label="Áreas">
    <ul>
<?php foreach ($groups as $area => $list): ?>
      <li><a href="#area-<?= $e($area) ?>"><?= $e($lang['areas'][$area]) ?> (<?= count($list) ?>)</a></li>
<?php endforeach; ?>
    </ul>
  </nav>

<?php foreach ($groups as $area => $list): ?>
  <section class="requisitos-area" id="area-<?= $e($area) ?>" aria-labelledby="area-<?= $e($area) ?>-t">
    <h2 id="area-<?= $e($area) ?>-t"><?= $e($lang['areas'][$area]) ?></h2>
    <p class="help"><?= $e($intro[$area]) ?></p>
<?php foreach ($list as $id => $r): ?>
    <details class="fold" id="<?= $e($id) ?>">
      <summary><span class="fold__title"><?= $e($id) ?> · <?= $e($r['nombre']) ?></span></summary>
      <div class="fold__body">
<?php if ($r['descripcion'] !== ''): ?>
        <p><?= $e($r['descripcion']) ?></p>
<?php endif; ?>
        <dl class="requisito-datos">
          <dt>Gravedad</dt><dd><?= $e($lang['severidades'][$r['severidad']] ?? $r['severidad']) ?> (resta hasta <?= (int) (Scorer::PENALTIES[$r['severidad']] ?? 0) ?> puntos si no cumple)</dd>
          <dt>Reparto entre áreas</dt><dd><?= $e($split($r['pilares'])) ?></dd>
<?php if ($r['impacto'] !== '' || $r['esfuerzo'] !== ''): ?>
          <dt>Impacto y esfuerzo</dt><dd>Impacto <?= $e(mb_strtolower($lang['niveles'][$r['impacto']] ?? (string) $r['impacto'])) ?> · esfuerzo <?= $e(mb_strtolower($lang['niveles'][$r['esfuerzo']] ?? (string) $r['esfuerzo'])) ?></dd>
<?php endif; ?>
<?php if (isset($origin[$id])): ?>
          <dt>De dónde sale</dt><dd><?= $e($origin[$id]) ?></dd>
<?php endif; ?>
<?php if ($r['criterio'] !== ''): ?>
          <dt>En qué se basa</dt><dd><?= $e($r['criterio']) ?><?= $r['respaldo'] && isset($backing[$r['respaldo']]) ? ' ' . $e($backing[$r['respaldo']]) : '' ?></dd>
<?php endif; ?>
<?php if ($r['recomendacion'] !== ''): ?>
          <dt>Cómo se arregla</dt><dd><?= $e($r['recomendacion']) ?></dd>
<?php endif; ?>
<?php if ($r['manual']): ?>
          <dt>Revisión manual</dt><dd>Además de la comprobación automática, una persona debe revisarlo.</dd>
<?php endif; ?>
        </dl>
      </div>
    </details>
<?php endforeach; ?>
  </section>
<?php endforeach; ?>

  <!-- v4: descarga en PDF de esta misma lista (se genera en el navegador con assets/js/pdf.js) -->
  <section id="descargas" class="results downloads no-print" aria-labelledby="descargas-title">
    <h2 id="descargas-title">Descargas</h2>
    <div>
      <h3>Requisitos</h3>
      <div class="report-actions rw-context__actions">
        <button type="button" class="rw-btn" id="requisitos-pdf">Descargar los requisitos (PDF)</button>
      </div>
      <p class="help" id="requisitos-pdf-estado" role="status" aria-live="polite">Las <?= $total ?> comprobaciones con su gravedad, reparto, referencia y cómo se arreglan, con el diseño de Proconsi.</p>
      <noscript><p class="help">Para descargar el PDF hace falta JavaScript.</p></noscript>
    </div>
  </section>
</main>

<footer class="site-footer">
  <div class="site-footer__inner">
    <img class="site-footer__logo" src="<?= $asset('assets/img/logo-proconsi.png') ?>" alt="Proconsi" width="96" height="33">
    <p class="site-footer__name">Radar web</p>
    <p>Evaluación automática y parcial. No sustituye una auditoría manual.</p>
  </div>
</footer>
<script type="application/json" id="requisitos-datos"><?= json_encode($pdf, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= $asset('assets/js/pdf-fuentes.js') ?>" defer></script>
<script src="<?= $asset('assets/js/pdf.js') ?>" defer></script>
<script src="<?= $asset('assets/js/requisitos.js') ?>" defer></script>
</body>
</html>
