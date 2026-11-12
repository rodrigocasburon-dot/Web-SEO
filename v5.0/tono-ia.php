<?php
/**
 * Radar web · Tono y citabilidad para IA (12/11/2026): página para analizar una URL o un texto pegado (por ejemplo,
 * antes de publicarlo). El mismo análisis sale en el informe, en «Análisis complementario». Ver src/ToneForAi.php.
 * Descarga con las reglas del Radar: http/https, IP pública fijada, redirecciones revalidadas, 10 s, 2 MB y 30 por hora.
 */
require_once __DIR__ . '/src/ToneForAi.php';

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    fwrite(STDERR, "Ábrelo en el navegador. Las pruebas están en tests/tono_ia_test.php.
");
    exit(2);
}

/**
 * Descarga segura de una página (mismas reglas que el Radar): solo http/https, puertos 80/443, IP pública fijada
 * con CURLOPT_RESOLVE, redirecciones revalidadas (máx. 5), 10 s y 2 MB. Devuelve [html|null, error|null].
 */
function toneFetch(string $url): array
{
    for ($hop = 0; $hop <= 5; $hop++) {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        $host = strtolower($p['host'] ?? '');
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user']) || !in_array($port, [80, 443], true)) {
            return [null, 'La dirección no es válida: solo http o https, sin usuario ni puertos especiales.'];
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) {
            return [null, 'No se encuentra el dominio.'];
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return [null, 'La dirección apunta a una red privada o reservada.'];
            }
        }
        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'ProconsiRadar-Tono/1.0 (herramienta interna de análisis)',
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'Accept-Language: es'],
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, &$tooBig) {
                $body .= $chunk;
                if (strlen($body) > 2 * 1024 * 1024) {
                    $tooBig = true;
                    return 0;
                }
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($tooBig) {
            return [null, 'La página supera 2 MB.'];
        }
        if ($err) {
            return [null, 'No se ha podido descargar la página (tiempo agotado o error de conexión).'];
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            $url = $location;
            continue;
        }
        if (in_array($status, [401, 403, 429], true) || preg_match('/captcha|cf-chl|are you a robot/i', substr($body, 0, 20000))) {
            return [null, 'Acceso bloqueado por el sitio (HTTP ' . $status . '): no es un fallo de la web, el sitio no deja analizarla automáticamente.'];
        }
        if ($status !== 200) {
            return [null, 'La página responde con HTTP ' . $status . '.'];
        }
        if ($type !== '' && stripos($type, 'html') === false) {
            return [null, 'La dirección no es una página HTML.'];
        }
        return [$body, null];
    }
    return [null, 'Demasiadas redirecciones (más de 5).'];
}

/** Límite de uso por IP: 30 análisis por hora (archivo en la carpeta temporal; no guarda los análisis). */
function toneRateOk(string $ip): bool
{
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'radar-tono-' . md5($ip) . '.json';
    $now = time();
    $hits = array_filter((array) json_decode((string) @file_get_contents($file), true), fn($t) => is_int($t) && $t > $now - 3600);
    if (count($hits) >= 30) {
        return false;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode(array_values($hits)), LOCK_EX);
    return true;
}

// ================================================================ Página (solo si se abre este archivo directamente)
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    $h = fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $url = trim((string) (is_string($_POST['url'] ?? null) ? $_POST['url'] : ''));
    $text = (string) (is_string($_POST['texto'] ?? null) ? mb_substr($_POST['texto'], 0, 200000) : '');
    $result = null;
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($url === '' && trim($text) === '') {
            $error = 'Escribe una URL o pega un texto.';
        } elseif (!toneRateOk((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
            $error = 'Has llegado al límite de 30 análisis por hora. Inténtalo más tarde.';
        } elseif ($url !== '') {
            if (!preg_match('#^https?://#i', $url)) {
                $url = 'https://' . $url;
            }
            [$html, $error] = toneFetch($url);
            if ($html !== null) {
                [$plain, $lang] = ToneForAi::textFromHtml($html);
                $result = ToneForAi::analyze($plain, $lang);
            }
        } else {
            $result = ToneForAi::analyze($text, 'es');
        }
    }
    $badge = ['bien' => 'Bien', 'mejorable' => 'Mejorable', 'revisar' => 'Revisar'];
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Tono y citabilidad para IA · Radar web</title>
<style>
  :root { --ink: #000; --ink2: #2e2e2e; --red: #e30613; --grey: #6c6c6c; --line: #d9d9d9; --soft: #f5f5f5; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #fff; color: var(--ink); font: 400 1rem/1.6 Poppins, "Segoe UI", Arial, sans-serif; }
  .wrap { max-width: 52rem; margin: 0 auto; padding: 0 1rem; }
  header { background: #000; color: #fff; padding: 1.75rem 0 1.5rem; }
  .eyebrow { color: #fff; font-size: .8125rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; margin: 0; border-left: 3px solid var(--red); padding-left: .5rem; }
  h1 { font-size: 1.875rem; line-height: 1.2; margin: .5rem 0 .25rem; }
  header p.lead { color: #d0d0d0; margin: 0; }
  h2 { font-size: 1.25rem; margin: 2rem 0 .75rem; border-top: 3px solid var(--ink); padding-top: .75rem; }
  h3 { font-size: 1rem; margin: 1.25rem 0 .25rem; }
  label { display: block; font-weight: 600; margin: 1rem 0 .25rem; }
  .help { color: var(--ink2); font-size: .875rem; margin: .15rem 0 0; }
  input[type=text], textarea { width: 100%; font: inherit; padding: .65rem .75rem; border: 1px solid #8f8f8f; border-radius: 2px; }
  textarea { min-height: 8rem; resize: vertical; }
  button { margin-top: 1rem; min-height: 44px; padding: .6rem 1.5rem; font: 600 1rem Poppins, "Segoe UI", Arial, sans-serif; color: #fff; background: var(--red); border: 0; border-radius: 2px; cursor: pointer; }
  button:hover { background: #000; }
  :focus-visible { outline: 3px solid var(--ink); outline-offset: 2px; }
  header :focus-visible { outline-color: #fff; }
  .error { border-left: 3px solid var(--red); padding: .5rem .75rem; background: #fff5f5; }
  .summary { font-size: 1.125rem; font-weight: 600; margin: 0 0 .5rem; }
  .grid { list-style: none; padding: 0; margin: 0; display: grid; gap: .75rem; grid-template-columns: 1fr; }
  @media (min-width: 40rem) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  @media (min-width: 52rem) { .grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
  .card { border: 1px solid var(--line); border-top: 3px solid var(--ink); padding: .75rem; border-radius: 2px; }
  .card.is-mejorable, .card.is-revisar { border-top-color: var(--red); }
  .card__name { font-size: .8125rem; text-transform: uppercase; letter-spacing: .05em; color: var(--ink2); margin: 0; }
  .card__value { font-size: 1.5rem; font-weight: 700; margin: .1rem 0; }
  .card__state { font-size: .75rem; font-weight: 600; border: 1px solid currentColor; padding: 0 .35rem; border-radius: 2px; }
  .is-mejorable .card__state, .is-revisar .card__state { color: #b0000c; }
  .card__help { font-size: .8125rem; color: var(--ink2); margin: .35rem 0 0; }
  ol, ul.examples { padding-left: 1.25rem; }
  ul.examples li { margin: .35rem 0; }
  blockquote { margin: 0; }
  .muted { color: var(--grey); font-size: .875rem; }
  footer { border-top: 1px solid var(--line); margin-top: 3rem; padding: 1.25rem 0 2rem; }
  .visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
</style>
</head>
<body>
<header><div class="wrap">
  <p class="eyebrow">Radar web · Preparación para IA</p>
  <h1>Tono y citabilidad para IA</h1>
  <p class="lead">Si el texto de una página es concreto, verificable y fácil de citar por un asistente de IA.</p>
</div></header>
<main class="wrap" id="contenido">
  <form method="post" novalidate>
    <label for="url">URL de la página</label>
    <input id="url" name="url" type="text" inputmode="url" autocomplete="url" spellcheck="false" value="<?= $h($url) ?>" placeholder="https://www.tuweb.es" aria-describedby="url-help">
    <p id="url-help" class="help">Se analiza el contenido principal de esa página (sin menú ni pie). Si no indicas http o https, se usará https.</p>
    <label for="texto">O pega un texto</label>
    <textarea id="texto" name="texto" aria-describedby="texto-help"><?= $h($text) ?></textarea>
    <p id="texto-help" class="help">Solo se usa si la URL está vacía. Útil para revisar un texto antes de publicarlo.</p>
    <button type="submit">Analizar el tono</button>
  </form>

<?php if ($error !== null): ?>
  <h2 id="resultado" tabindex="-1">Resultado</h2>
  <p class="error" role="alert"><?= $h($error) ?></p>
<?php elseif ($result !== null): ?>
  <h2 id="resultado" tabindex="-1">Resultado<?= $url !== '' ? '<span class="visually-hidden"> de ' . $h($url) . '</span>' : '' ?></h2>
  <?php if (!$result['disponible']): ?>
    <p class="error" role="status"><?= $h($result['motivo']) ?></p>
  <?php else: ?>
    <p class="summary" role="status"><?= $h($result['resumen']) ?></p>
    <?php if ($result['poco_texto']): ?><p class="error">Poco texto (menos de 15 frases): el resultado es poco fiable.<?= $url !== '' ? ' Puede que la página cargue su contenido con JavaScript.' : '' ?></p><?php endif; ?>
    <p class="muted"><?= (int) $result['frases'] ?> frases y <?= (int) $result['palabras'] ?> palabras analizadas<?= $url !== '' ? ' de ' . $h($url) : '' ?>.<?= $result['idioma_supuesto'] && $url !== '' ? ' La página no declara idioma: se supone español.' : '' ?></p>
    <ul class="grid">
    <?php foreach ($result['indicadores'] as $i): ?>
      <li class="card is-<?= $h($i['estado']) ?>">
        <p class="card__name"><?= $h($i['nombre']) ?></p>
        <p class="card__value"><?= $h($i['valor']) ?></p>
        <span class="card__state"><?= $h($badge[$i['estado']] ?? $i['estado']) ?></span>
        <p class="card__help"><?= $h($i['explicacion']) ?></p>
      </li>
    <?php endforeach; ?>
    </ul>
    <?php if ($result['recomendaciones']): ?>
      <h3>Qué cambiaría</h3>
      <ol><?php foreach ($result['recomendaciones'] as $r): ?><li><?= $h($r) ?></li><?php endforeach; ?></ol>
    <?php endif; ?>
    <?php foreach (['citables' => 'Frases que un asistente de IA podría citar', 'sin_respaldo' => 'Afirmaciones sin respaldo', 'negativas' => 'Frases negativas para revisar'] as $k => $title): ?>
      <?php if ($result['ejemplos'][$k]): ?>
        <h3><?= $h($title) ?></h3>
        <ul class="examples"><?php foreach ($result['ejemplos'][$k] as $s): ?><li><blockquote>«<?= $h($s) ?>»</blockquote></li><?php endforeach; ?></ul>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

  <h2>Cómo se calcula</h2>
  <p>Reglas y léxico en español, en este servidor: no es un modelo de IA ni envía el texto fuera. No entiende ironía ni contexto. Los umbrales son orientativos y no puntúan. Revisa siempre a mano las frases señaladas.</p>
</main>
<footer class="wrap"><p class="muted">Radar web · Proconsi · Herramienta interna. Evaluación automática y parcial: no sustituye una revisión editorial. No se guarda ningún análisis.</p></footer>
</body>
</html>
<?php
}
