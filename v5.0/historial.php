<?php
/**
 * Historial (v4): una de las dos únicas páginas (la otra es el buscador, index.php). Lista de las búsquedas
 * guardadas, con filtro por dirección. Cada fila es una búsqueda: la página buscada (notas y fallos de esa
 * página) y, si se terminó, el sitio entero (nº de páginas); «Ver informe» abre los dos en index.php?informe=ID.
 * Las filas de tipo «sitio» son de la v3.0 («Analizar sitio»). Sin JavaScript. Todo se escapa al imprimir.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

$config = Config::load();
$e = fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$asset = function (string $path): string {
    $file = __DIR__ . '/' . $path;
    return htmlspecialchars($path . '?v=' . (is_file($file) ? filemtime($file) : '0'), ENT_QUOTES, 'UTF-8');
};
$num = fn($v): string => $v === null ? '—' : str_replace('.', ',', (string) round((float) $v, 1));

$q = isset($_GET['q']) && is_string($_GET['q']) ? trim(mb_substr($_GET['q'], 0, 200)) : '';
$type = '';
$page = max(1, min(1000, (int) ($_GET['pagina'] ?? 1)));
$perPage = 50;

$rows = [];
$error = '';
$enabled = History::enabled($config);
if ($enabled) {
    $history = History::fromConfig($config);
    if ($history) {
        try {
            // Se pide una fila de más para saber si hay página siguiente
            $rows = $history->latest($q, $type, $perPage + 1, ($page - 1) * $perPage);
        } catch (Throwable $ex) {
            ErrorLog::record('historial:lista', $ex);
            $error = 'No se ha podido leer el historial.';
        }
    } else {
        $error = 'No se ha podido conectar con la base de datos del historial.';
    }
}
$hasNext = count($rows) > $perPage;
$rows = array_slice($rows, 0, $perPage);
$link = fn(int $p): string => 'historial.php?' . http_build_query(array_filter(['q' => $q, 'tipo' => $type, 'pagina' => $p > 1 ? $p : null]));
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Historial de análisis · Radar web de Proconsi</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= $asset('assets/css/app.css') ?>">
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
      <a href="requisitos.php">Requisitos</a>
      <a href="historial.php" aria-current="page">Historial</a>
    </nav>
    <p class="site-header__tag"><span aria-hidden="true"></span>Herramienta interna</p>
  </div>
</header>

<main id="contenido" class="container" tabindex="-1">
  <div class="hero hero--compact">
    <p class="eyebrow">Lo que ya se ha analizado</p>
    <h1>Historial de análisis</h1>
    <p class="lead">Webs analizadas, con su fecha, sus notas y el informe completo de ese día (la página buscada y todo el sitio). Se guardan <?= $enabled ? History::months($config) : 12 ?> meses y luego se borran.</p>
  </div>

<?php if (!$enabled): ?>
  <p class="notice notice--warn">El historial está desactivado. Para activarlo, importa <code>install.sql</code> y añade el bloque <code>'historial'</code> en <code>config/config.php</code> (ver el README).</p>
<?php elseif ($error !== ''): ?>
  <p class="notice notice--fail" role="alert"><?= $e($error) ?></p>
<?php else: ?>
  <form class="analyze-form history-filter no-print" method="get" action="historial.php" role="search" aria-label="Filtrar el historial">
    <div class="field">
      <label for="q">Buscar por dirección</label>
      <input id="q" name="q" type="search" value="<?= $e($q) ?>" placeholder="proconsi.com" autocomplete="off" spellcheck="false">
    </div>
    <button type="submit" class="button">Filtrar</button>
  </form>

  <?php if (!$rows): ?>
    <p><?= $q !== '' ? 'No hay análisis que coincidan con el filtro.' : 'Todavía no hay análisis guardados.' ?></p>
  <?php else: ?>
    <!-- v4 · Comparar dos auditorías: se marcan dos casillas y se abre index.php?comparar[]=…&comparar[]=… -->
    <form id="comparar" method="get" action="index.php" class="history-compare no-print">
      <p class="help" id="comparar-help">Para ver qué ha mejorado o empeorado, marca dos análisis (por ejemplo, de la misma web en dos fechas) y pulsa «Comparar».</p>
      <button type="submit" class="button" aria-describedby="comparar-help">Comparar los dos marcados</button>
    </form>
    <div class="table-wrap">
      <table class="data-table">
        <caption class="visually-hidden">Análisis guardados, del más reciente al más antiguo</caption>
        <thead>
          <tr>
            <th scope="col"><span class="visually-hidden">Marcar para comparar</span></th>
            <th scope="col">Dirección</th>
            <th scope="col">Sitio entero</th>
            <th scope="col">Fecha (UTC)</th>
            <th scope="col" class="num">Global</th>
            <th scope="col" class="num">SEO</th>
            <th scope="col" class="num">GEO</th>
            <th scope="col" class="num">UX</th>
            <th scope="col" class="num">Fallos críticos / altos / medios / bajos</th>
            <th scope="col"><span class="visually-hidden">Informe</span></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php if ($r['tiene_informe']): ?><input type="checkbox" form="comparar" name="comparar[]" value="<?= (int) $r['id'] ?>" id="cmp-<?= (int) $r['id'] ?>" aria-label="<?= $e('Comparar ' . $r['url'] . ' del ' . str_replace(['T', 'Z'], [' ', ''], substr($r['fecha'], 0, 16))) ?>"><?php endif; ?></td>
            <th scope="row"><a href="<?= $e($r['url']) ?>" rel="noopener noreferrer"><?= $e($r['url']) ?></a></th>
            <td><?= $r['paginas'] > 1 ? (int) $r['paginas'] . ' páginas' : ($r['tipo'] === 'sitio' ? (int) $r['paginas'] . ' página' : 'Solo esta página') ?></td>
            <td><?= $e(str_replace(['T', 'Z'], [' ', ''], substr($r['fecha'], 0, 16))) ?></td>
          <?php if ($r['bloqueado']): ?>
            <td colspan="5">Acceso bloqueado: sin nota</td>
          <?php else: ?>
            <td class="num"><?= $e($num($r['nota_global'])) ?><?= $r['incompleta'] ? ' <span class="compare-table__meta">incompleta</span>' : '' ?><?php if (!empty($r['fiabilidad'])): ?> <span class="compare-table__meta">análisis <?= $e($r['fiabilidad']['estado']) ?>: <?= (int) $r['fiabilidad']['evaluados'] ?> de <?= (int) $r['fiabilidad']['posibles'] ?> puntos</span><?php endif; ?></td>
            <td class="num"><?= $e($num($r['nota_seo'])) ?></td>
            <td class="num"><?= $e($num($r['nota_ia'])) ?></td>
            <td class="num"><?= $e($num($r['nota_accesibilidad'])) ?></td>
            <td class="num"><?= (int) $r['criticos'] ?> / <?= (int) $r['altos'] ?> / <?= (int) $r['medios'] ?> / <?= (int) $r['bajos'] ?></td>
          <?php endif; ?>
            <td>
            <?php if ($r['tiene_informe']): ?>
              <a href="<?= $e('index.php?informe=' . (int) $r['id']) ?>">Ver informe<span class="visually-hidden"> de <?= $e($r['url']) ?> del <?= $e(str_replace(['T', 'Z'], [' ', ''], substr($r['fecha'], 0, 16))) ?></span></a>
            <?php else: ?>
              <span class="compare-table__meta">Sin informe guardado</span>
            <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="help">Las notas y los fallos de la tabla son los de la página buscada (con PageSpeed si respondió). Las notas medias de todo el sitio están en el informe. En las filas antiguas de «Analizar sitio» (v3.0) son ya la media del sitio.</p>
    <?php if ($page > 1 || $hasNext): ?>
      <nav class="report-actions no-print" aria-label="Páginas del historial">
        <?php if ($page > 1): ?><a class="button button--secondary" href="<?= $e($link($page - 1)) ?>">Más recientes</a><?php endif; ?>
        <?php if ($hasNext): ?><a class="button button--secondary" href="<?= $e($link($page + 1)) ?>">Más antiguos</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
</main>

<footer class="site-footer">
  <div class="site-footer__inner">
    <img class="site-footer__logo" src="<?= $asset('assets/img/logo-proconsi.png') ?>" alt="Proconsi" width="96" height="33">
    <p class="site-footer__name">Radar web</p>
    <p>Herramienta interna sin login: quien accede a la herramienta ve este historial y sus informes.</p>
  </div>
</footer>
</body>
</html>
