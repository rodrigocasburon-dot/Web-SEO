<?php
/**
 * v3.0 · Historial de URL analizadas: lista de los últimos análisis (una URL o sitio completo),
 * con filtro por dirección y tipo. Solo muestra lo que guarda el historial: URL, fecha, notas y
 * fallos por gravedad (nunca contenido de las webs). Sin JavaScript. Todo se escapa al imprimir.
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

$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 200));
$type = in_array($_GET['tipo'] ?? '', ['url', 'sitio'], true) ? (string) $_GET['tipo'] : '';
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
            error_log('[historial] ' . $ex->getMessage());
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
      <a href="sitio.php">Analizar sitio</a>
      <a href="historial.php" aria-current="page">Historial</a>
    </nav>
    <p class="site-header__tag"><span aria-hidden="true"></span>Herramienta interna</p>
  </div>
</header>

<main id="contenido" class="container" tabindex="-1">
  <div class="hero hero--compact">
    <p class="eyebrow"><span aria-hidden="true"></span>Lo que ya se ha analizado</p>
    <h1>Historial de análisis</h1>
    <p class="lead">Direcciones analizadas, con su fecha, sus notas y el informe completo de ese día. Se guardan <?= $enabled ? History::months($config) : 12 ?> meses y luego se borran.</p>
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
    <div class="field">
      <label for="tipo">Tipo de análisis</label>
      <select id="tipo" name="tipo">
        <option value=""<?= $type === '' ? ' selected' : '' ?>>Todos</option>
        <option value="url"<?= $type === 'url' ? ' selected' : '' ?>>Una URL</option>
        <option value="sitio"<?= $type === 'sitio' ? ' selected' : '' ?>>Sitio completo</option>
      </select>
    </div>
    <button type="submit" class="button">Filtrar</button>
  </form>

  <?php if (!$rows): ?>
    <p><?= $q !== '' || $type !== '' ? 'No hay análisis que coincidan con el filtro.' : 'Todavía no hay análisis guardados.' ?></p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <caption class="visually-hidden">Análisis guardados, del más reciente al más antiguo</caption>
        <thead>
          <tr>
            <th scope="col">Dirección</th>
            <th scope="col">Tipo</th>
            <th scope="col">Fecha (UTC)</th>
            <th scope="col" class="num">Global</th>
            <th scope="col" class="num">SEO</th>
            <th scope="col" class="num">IA</th>
            <th scope="col" class="num">Accesibilidad</th>
            <th scope="col" class="num">Fallos críticos / altos / medios / bajos</th>
            <th scope="col"><span class="visually-hidden">Informe</span></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <th scope="row"><a href="<?= $e($r['url']) ?>" rel="noopener noreferrer"><?= $e($r['url']) ?></a></th>
            <td><?= $r['tipo'] === 'sitio' ? 'Sitio (' . (int) $r['paginas'] . ' páginas)' : 'Una URL' . ($r['pagespeed'] ? ' + PageSpeed' : '') ?></td>
            <td><?= $e(str_replace(['T', 'Z'], [' ', ''], substr($r['fecha'], 0, 16))) ?></td>
          <?php if ($r['bloqueado']): ?>
            <td colspan="5">Acceso bloqueado: sin nota</td>
          <?php else: ?>
            <td class="num"><?= $e($num($r['nota_global'])) ?><?= $r['incompleta'] ? ' <span class="compare-table__meta">incompleta</span>' : '' ?></td>
            <td class="num"><?= $e($num($r['nota_seo'])) ?></td>
            <td class="num"><?= $e($num($r['nota_ia'])) ?></td>
            <td class="num"><?= $e($num($r['nota_accesibilidad'])) ?></td>
            <td class="num"><?= (int) $r['criticos'] ?> / <?= (int) $r['altos'] ?> / <?= (int) $r['medios'] ?> / <?= (int) $r['bajos'] ?></td>
          <?php endif; ?>
            <td>
            <?php if ($r['tiene_informe']): ?>
              <a href="<?= $e(($r['tipo'] === 'sitio' ? 'sitio.php' : 'index.php') . '?informe=' . (int) $r['id']) ?>">Ver informe<span class="visually-hidden"> de <?= $e($r['url']) ?> del <?= $e(str_replace(['T', 'Z'], [' ', ''], substr($r['fecha'], 0, 16))) ?></span></a>
            <?php else: ?>
              <span class="compare-table__meta">Sin informe guardado</span>
            <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="help">En los análisis de sitio, las notas son la media de las páginas y los fallos son comprobaciones distintas que fallan en alguna página.</p>
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
