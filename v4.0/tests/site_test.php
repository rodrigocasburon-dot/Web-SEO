<?php
// v3.0 · Pruebas del modo sitio (sin red): elección de la muestra, sitemaps y firmas.

test('SitePlanner: lee sitemaps XML, índices y de texto', function () {
    $xml = '<?xml version="1.0"?><urlset><url><loc>https://example.com/a</loc></url><url><loc><![CDATA[https://example.com/b?x=1&amp;y=2]]></loc></url></urlset>';
    $r = SitePlanner::parseSitemap($xml);
    assertSame(false, $r['indice']);
    assertSame(['https://example.com/a', 'https://example.com/b?x=1&y=2'], $r['urls']);
    $idx = SitePlanner::parseSitemap('<sitemapindex><sitemap><loc>https://example.com/s1.xml</loc></sitemap></sitemapindex>');
    assertSame(true, $idx['indice']);
    assertSame(['https://example.com/x', 'http://example.com/y'], SitePlanner::parseSitemap("https://example.com/x\nhttp://example.com/y\n")['urls']);
});

test('SitePlanner: resuelve enlaces relativos y descarta mailto, tel y anclas', function () {
    $html = '<a href="/a">a</a><a href="b/c">b</a><a href="../d">d</a><a href="#top">t</a><a href="mailto:x@y.z">m</a><a href="//example.com/e">e</a>';
    $links = SitePlanner::linksFrom($html, 'https://example.com/dir/pagina');
    assertSame(['https://example.com/a', 'https://example.com/dir/b/c', 'https://example.com/d', 'https://example.com/e'], $links);
});

test('SitePlanner: solo páginas del mismo sitio, sin parámetros ni archivos, respetando robots.txt', function () {
    $robots = RobotsTxt::parse("User-agent: *\nDisallow: /privado/\n");
    $in = ['https://example.com/a', 'https://www.example.com/b', 'https://otro.com/c', 'https://example.com/d?page=2',
        'https://example.com/e.pdf', 'https://example.com/privado/f', 'ftp://example.com/g', 'https://example.com/a/'];
    assertSame(['https://example.com/a', 'https://www.example.com/b'], SitePlanner::filter($in, 'example.com', $robots, 'proconsianalyzer'));
});

test('SitePlanner: la muestra reparte entre secciones y respeta el máximo', function () {
    $links = ['https://e.com/contacto'];
    $sitemap = [];
    for ($i = 0; $i < 100; $i++) {
        $sitemap[] = "https://e.com/producto/$i";
    }
    $sitemap[] = 'https://e.com/blog/uno';
    $sample = SitePlanner::sample($links, $sitemap, 5);
    assertSame(5, count($sample));
    assertTrue(in_array('https://e.com/contacto', $sample, true), 'incluye la sección enlazada desde la portada');
    assertTrue(in_array('https://e.com/blog/uno', $sample, true), 'la sección pequeña no queda fuera');
    assertSame('es/blog', SitePlanner::section('https://e.com/es/blog/post'));
    assertSame('/', SitePlanner::section('https://e.com/'));
    assertSame(['a', 'c'], SitePlanner::spread(['a', 'b', 'c', 'd'], 2));
});

test('SitePlanner: un índice enorme solo lee el nº de archivos configurado', function () {
    $calls = [];
    $fetch = function (string $url, array $o = []) use (&$calls) {
        $calls[] = $url;
        if (substr($url, -12) === '/sitemap.xml') {
            $locs = '';
            for ($i = 0; $i < 300; $i++) {
                $locs .= "<sitemap><loc>https://e.com/s$i.xml</loc></sitemap>";
            }
            return ['ok' => true, 'status' => 200, 'body' => "<sitemapindex>$locs</sitemapindex>"];
        }
        return ['ok' => true, 'status' => 200, 'body' => '<urlset><url><loc>' . str_replace('.xml', '/p', $url) . '</loc></url></urlset>'];
    };
    $plan = SitePlanner::plan('https://e.com/', '<a href="/x">x</a>', null, $fetch, ['site_sitemap_files' => 4, 'site_max_pages' => 10]);
    assertSame(4, count($calls), 'índice + 3 archivos');
    assertSame(4, $plan['sitemaps_leidos']);
    assertSame('https://e.com/x', $plan['paginas'][0]);
});

test('SiteToken: la firma caduca y no vale para otra URL', function () {
    $t = new SiteToken(str_repeat('k', 32));
    $sig = $t->sign('https://e.com/a', 2000);
    assertTrue($t->verify('https://e.com/a', 2000, $sig, 1000));
    assertTrue(!$t->verify('https://e.com/b', 2000, $sig, 1000), 'otra URL');
    assertTrue(!$t->verify('https://e.com/a', 2000, $sig, 3000), 'caducada');
    assertTrue(!$t->verify('https://e.com/a', 9999, $sig, 1000), 'otra caducidad');
});

test('CheckRunner: en las páginas de un sitio no se repiten C16 ni C18', function () {
    $ctx = CheckContext::fromFetch([
        'ok' => true, 'final_url' => 'https://e.com/', 'requested_url' => 'https://e.com/', 'status' => 200,
        'headers' => ['content-type' => 'text/html'], 'content_type' => 'text/html',
        'body' => '<html lang="es"><title>t</title><h1>h</h1></html>',
        'truncated' => false, 'bytes' => 10, 'redirects' => [], 'hops' => [], 'timings' => [], 'blocked' => null, 'error' => null, 'cert' => null,
    ]);
    $ids = array_map(fn($c) => $c['id'], (new CheckRunner())->runSitePage($ctx));
    assertTrue(!in_array('C16', $ids, true) && !in_array('C18', $ids, true));
    assertSame(count(CheckRunner::checks()) - 2, count($ids));
});

test('CurlTransport: usa el cacert incluido si php.ini no indica uno', function () {
    assertTrue(is_file(__DIR__ . '/../certs/cacert.pem'));
    $opts = CurlTransport::caOptions();
    if (trim((string) ini_get('curl.cainfo')) === '' && trim((string) ini_get('openssl.cafile')) === '') {
        assertTrue(isset($opts[CURLOPT_CAINFO]));
    } else {
        assertSame([], $opts);
    }
});

test('SiteToken: el resultado firmado no se puede cambiar (notas, fallos o URL)', function () {
    $t = new SiteToken(str_repeat('k', 32));
    $notas = ['seo' => 97.4, 'ia' => 98.4, 'accesibilidad' => 51.0, 'global' => 81.5];
    $f = ['C06' => 'ALTO', 'C04' => 'CRITICO'];
    $sig = $t->signResult('p1', 'https://e.com/a', $notas, $f, false);
    assertTrue($t->verifyResult('p1', 'https://e.com/a', $notas, ['C04' => 'CRITICO', 'C06' => 'ALTO'], false, $sig), 'el orden de los fallos no importa');
    assertTrue(!$t->verifyResult('p1', 'https://e.com/a', ['global' => 100] + $notas, $f, false, $sig), 'nota cambiada');
    assertTrue(!$t->verifyResult('p1', 'https://e.com/a', $notas, ['C06' => 'ALTO'], false, $sig), 'fallo quitado');
    assertTrue(!$t->verifyResult('p2', 'https://e.com/a', $notas, $f, false, $sig), 'de otro análisis');
    assertSame(['C04' => 'CRITICO'], SiteToken::failing([
        ['id' => 'C04', 'estado' => 'NO_CUMPLE', 'severidad' => 'CRITICO', 'aplica' => true],
        ['id' => 'C05', 'estado' => 'AVISO', 'severidad' => 'MEDIO'],
        ['id' => 'C21', 'estado' => 'NO_CUMPLE', 'severidad' => 'MEDIO', 'aplica' => false],
    ]));
});

test('Historial: análisis de sitio aparte de los de una URL; lista con filtro', function () {
    $h = new History(historyDb(), str_repeat('a', 64));
    $now = historyUtc('2026-11-09 10:00:00');
    $idUrl = $h->record('https://e.com/', (new Scorer())->score(historyChecks()), historyChecks(), false, $now);
    $id1 = $h->recordSite('https://e.com/', ['seo' => 90.04, 'ia' => 80, 'accesibilidad' => 70, 'global' => 80], ['C04' => 'CRITICO', 'C06' => 'ALTO', 'C22' => 'MEDIO'], 12, false, $now->modify('+1 hour'));
    $id2 = $h->recordSite('https://e.com/', null, [], 0, true, $now->modify('+2 hours'));
    $prev = $h->previous('https://www.e.com', $id2, 10, 'sitio');
    assertSame(1, count($prev), 'solo los de sitio');
    assertSame(90.0, $prev[0]['nota_seo']);
    assertSame(12, $prev[0]['paginas']);
    assertSame(1, $prev[0]['criticos']);
    assertSame(1, count($h->previous('https://e.com/', 0)), 'los de una URL no se mezclan');
    $all = $h->latest();
    assertSame([$id2, $id1, $idUrl], array_column($all, 'id'));
    assertSame(true, $all[0]['bloqueado']);
    assertSame(null, $all[0]['nota_global']);
    assertSame(2, count($h->latest('', 'sitio')));
    assertSame(0, count($h->latest('otra.com')));
    assertSame(0, count($h->latest('e_com')), 'el _ se busca literal, no como comodín');
});

test('Historial: guarda el informe completo comprimido y lo devuelve igual', function () {
    $h = new History(historyDb(), str_repeat('a', 64));
    $id = $h->record('https://e.com/', (new Scorer())->score(historyChecks()), historyChecks(), false, historyUtc('2026-11-09 10:00:00'));
    assertSame(null, $h->report($id), 'sin informe todavía');
    assertSame(false, $h->latest()[0]['tiene_informe']);
    $rep = ['data' => ['url' => 'https://e.com/', 'texto' => str_repeat('ñandú «x» ', 2000)], 'psiMerged' => false];
    $h->saveReport($id, $rep);
    $got = $h->report($id);
    assertSame('url', $got['tipo']);
    assertSame('2026-11-09T10:00:00Z', $got['fecha']);
    assertSame($rep, $got['informe']);
    assertSame(true, $h->latest()[0]['tiene_informe']);
    assertSame(null, $h->report($id + 99));
});

test('RunStore: guarda 1 h en storage/ y no devuelve lo que no existe', function () {
    $dir = tempDir('runstore');
    putenv('WEBSEO_STORAGE_DIR=' . $dir);
    try {
        RunStore::put('page', 'firma-1', ['ok' => true, 'url' => 'https://e.com/a']);
        assertSame(['ok' => true, 'url' => 'https://e.com/a'], RunStore::get('page', 'firma-1'));
        assertSame(null, RunStore::get('page', 'otra'));
        assertSame(null, RunStore::get('chrome', 'firma-1'), 'cada tipo por separado');
        assertSame(null, RunStore::get('page', ''));
    } finally {
        putenv('WEBSEO_STORAGE_DIR');
    }
});

test('v4 · SiteToken: la firma de una página va ligada a su análisis', function () {
    $t = new SiteToken(str_repeat('k', 32));
    $sig = $t->sign(SiteToken::pageScope('aaa', 'https://e.com/a'), 2000);
    assertTrue($t->verify(SiteToken::pageScope('aaa', 'https://e.com/a'), 2000, $sig, 1000));
    assertTrue(!$t->verify(SiteToken::pageScope('bbb', 'https://e.com/a'), 2000, $sig, 1000), 'otro análisis');
    assertTrue(!$t->verify('https://e.com/a', 2000, $sig, 1000), 'firma de la v3.0 (sin análisis)');
});

test('v4 · Historial: el sitio entero se añade a la fila de la URL buscada', function () {
    $h = new History(historyDb(), str_repeat('a', 64));
    $id = $h->record('https://e.com/', (new Scorer())->score(historyChecks()), historyChecks(), false, historyUtc('2026-11-10 10:00:00'));
    assertSame(false, $h->attachSite($id, 3, ['pages' => []]), 'sin informe de la URL no hay fila que completar');
    $h->saveReport($id, ['data' => ['url' => 'https://e.com/'], 'psiMerged' => false]);
    assertTrue($h->attachSite($id, 3, ['pages' => [['url' => 'https://e.com/a']], 'medias' => ['global' => 70.5]]));
    $got = $h->report($id);
    assertSame('https://e.com/', $got['informe']['data']['url'], 'conserva el informe de la URL');
    assertSame(70.5, $got['informe']['sitio']['medias']['global']);
    $row = $h->latest()[0];
    assertSame(3, $row['paginas']);
    assertSame('url', $row['tipo'], 'una búsqueda = una fila');
    assertSame(1, count($h->latest()));
    $raw = $h->reportJson($id);
    assertSame($got['informe'], json_decode($raw['json'], true), 'el JSON sin decodificar es el mismo informe');
    assertSame(null, $h->reportJson($id + 1));
});

test('v4 · SitePlanner: sin tope de 500 páginas (5.000 por defecto, máx. 20.000)', function () {
    assertSame(5000, SitePlanner::maxPages([]));
    assertSame(20000, SitePlanner::maxPages(['site_max_pages' => 999999]));
    $sitemap = [];
    for ($i = 0; $i < 1200; $i++) {
        $sitemap[] = "https://e.com/p/$i";
    }
    assertSame(1200, count(SitePlanner::sample([], $sitemap, SitePlanner::maxPages([]))));
});

test('v4 · Departamentos: cada comprobación tiene equipo y explicación, y cada equipo existe', function () {
    $l = Lang::all();
    foreach (array_keys($l['checks']) as $id) {
        assertTrue(isset($l['enfoque'][$id]), "Falta $id en 'enfoque'");
        assertTrue(isset($l['responsables'][$l['enfoque'][$id][0]]), "Equipo desconocido en $id");
        assertTrue(mb_strlen($l['enfoque'][$id][1]) > 20, "Explicación de $id");
    }
    foreach ($l['departamentos'] as $k => $d) {
        assertTrue($d['responsable'] === null || isset($l['responsables'][$d['responsable']]), $k);
        assertTrue(stripos($d['intro'], 'cumple') === false, 'ningún texto afirma cumplimiento');
    }
});

test('v4 · Historial: los análisis anteriores llevan id e informe para poder compararlos', function () {
    $h = new History(historyDb(), str_repeat('a', 64));
    $a = $h->record('https://e.com/', (new Scorer())->score(historyChecks()), historyChecks(), false, historyUtc('2026-11-09 10:00:00'));
    $h->saveReport($a, ['data' => ['url' => 'https://e.com/']]);
    $b = $h->record('https://e.com/', (new Scorer())->score(historyChecks()), historyChecks(), false, historyUtc('2026-11-10 10:00:00'));
    $prev = $h->previous('https://e.com/', $b);
    assertSame($a, $prev[0]['id']);
    assertSame(true, $prev[0]['tiene_informe']);
});

test('v4 · Pistas RM-01: textos alternativos sospechosos (sin IA)', function () {
    assertSame('archivo', ManualHints::altReason('IMG_2034.jpg'));
    assertSame('archivo', ManualHints::altReason('banner-home-principal-2024'));
    assertSame('generico', ManualHints::altReason('Imagen'));
    assertSame('generico', ManualHints::altReason('logo'));
    assertSame('corto', ManualHints::altReason('a'));
    assertSame('redundante', ManualHints::altReason('Foto de la oficina'));
    assertSame('largo', ManualHints::altReason(str_repeat('palabra ', 25)));
    assertSame(null, ManualHints::altReason('Equipo de Proconsi en la oficina de León'));
    assertSame(null, ManualHints::altReason(''), 'alt vacío = decorativa, correcto');
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?><html lang="es"><body>'
        . '<img src="1.jpg" alt="Producto"><img src="2.jpg" alt="Producto"><img src="3.jpg" alt="producto">'
        . '<img src="4.jpg" alt="DSC_0001"><img src="5.jpg" alt=""><img src="6.jpg" alt="Mapa de la tienda" hidden>'
        . '<img src="7.jpg" alt="Fachada de la tienda de León"></body></html>');
    $r = ManualHints::altText(new DOMXPath($doc));
    assertSame(5, $r['imagenes_con_alt'], 'sin las de alt vacío ni las ocultas');
    assertSame(4, $r['sospechosos']);
    assertSame(['archivo' => 1, 'repetido' => 3], array_filter($r['por_motivo']) + []);
});

test('v4 · Pistas RM-09: sílabas en español y legibilidad de Fernández-Huerta', function () {
    foreach (['casa' => 2, 'teléfono' => 4, 'país' => 2, 'aéreo' => 4, 'guitarra' => 3, 'que' => 1, 'ciudad' => 2, 'información' => 4, 'accesibilidad' => 6] as $w => $n) {
        assertSame($n, ManualHints::syllables($w), $w);
    }
    // 100 palabras, 200 sílabas, 10 frases: 206,84 − 120 − 10,2 = 76,64
    assertSame(76.6, ManualHints::fernandezHuerta(100, 200, 10));
    assertSame('bastante fácil', ManualHints::level(76.6));
    assertSame('muy difícil', ManualHints::level(12.0));
    assertSame(null, ManualHints::fernandezHuerta(0, 0, 0));
    $easy = '<p>' . str_repeat('La casa es azul. El sol sale hoy. Me gusta el mar. ', 12) . '</p>';   // los bloques repetidos cuentan una vez
    $hard = '<p>' . str_repeat('La implementación metodológica interdisciplinaria correspondiente ', 12) . '.</p>';
    $read = function (string $body) {
        $d = new DOMDocument();
        @$d->loadHTML('<?xml encoding="utf-8"?><html lang="es"><body><main>' . $body . '</main></body></html>');
        return ManualHints::readability(new DOMXPath($d));
    };
    $a = $read($easy);
    $b = $read($hard);
    assertTrue($a['indice'] > 80 && $a['concluyente'], 'texto sencillo: fácil');
    assertTrue($b['indice'] < 30 && $b['frases_largas'] === 1 && $b['parrafos'] === 1, 'frase interminable: muy difícil');
    $d = new DOMDocument();
    @$d->loadHTML('<html lang="en"><body><p>Hello world.</p></body></html>');
    assertSame(false, ManualHints::readability(new DOMXPath($d))['disponible'], 'solo español');
});
