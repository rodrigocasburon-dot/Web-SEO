<?php
require 'lib/carga.php';

const ETIQUETAS = [
    'cumple' => 'Cumple',
    'no_cumple' => 'No cumple',
    'aviso' => 'Aviso',
    'sin_datos' => 'Sin datos',
    'no_aplica' => 'No aplica',
];
const NOTAS = ['seo' => 'SEO', 'geo' => 'GEO', 'ux' => 'UX'];
const BLOQUES = ['SEO' => 'SEO', 'GEO' => 'GEO', 'UX' => 'UX y accesibilidad', 'WEB' => 'Toda la web'];

$db = conectar(cargar_config());
$id = (int) ($_GET['id'] ?? 0);
$consulta = $db->prepare('SELECT * FROM analisis WHERE id = ?');
$consulta->execute([$id]);
$analisis = $consulta->fetch(PDO::FETCH_ASSOC);

if (!$analisis) {
    http_response_code(404);
    cabecera('Análisis no encontrado');
    echo '<section class="inicio"><h1>Análisis no encontrado</h1><p>Los análisis se borran a las 24 horas. <a href="index.php">Haz uno nuevo</a>.</p></section>';
    pie();
    exit;
}
if ($analisis['estado'] !== 'terminado') {
    header("Location: progreso.php?id=$id");
    exit;
}

$consulta = $db->prepare('SELECT r.punto_id, r.estado, r.detalle, p.url FROM resultados r
    JOIN paginas p ON p.id = r.pagina_id WHERE p.analisis_id = ? ORDER BY p.id');
$consulta->execute([$id]);
$por_punto = [];
foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $por_punto[$fila['punto_id']][] = $fila;
}

$consulta = $db->prepare("SELECT COUNT(*) FROM paginas WHERE analisis_id = ? AND estado = 'hecha'");
$consulta->execute([$id]);
$total_paginas = (int) $consulta->fetchColumn();

$checklist = cargar_checklist();
$resumen = [];
$puntos = [];
$sin_ejecutar = 0;
foreach ($checklist['puntos'] as $punto) {
    $resumen[$punto['id']] = resumir_punto($punto, $por_punto[$punto['id']] ?? [], $checklist['valor_resultado']);
    if ($resumen[$punto['id']]['estado'] === 'pendiente') {
        $sin_ejecutar++;
        continue;
    }
    $puntos[] = $punto + $resumen[$punto['id']];
}
$notas = calcular_notas($resumen, $checklist);

// Primero lo que hay que arreglar y, dentro de eso, lo más grave.
$orden_estado = ['no_cumple' => 0, 'aviso' => 1, 'sin_datos' => 2, 'cumple' => 3, 'no_aplica' => 4];
$orden_gravedad = array_flip(array_keys($checklist['pesos']));
usort($puntos, fn($a, $b) => [$orden_estado[$a['estado']], $orden_gravedad[$a['gravedad']]]
    <=> [$orden_estado[$b['estado']], $orden_gravedad[$b['gravedad']]]);

$problemas = array_values(array_filter($puntos, fn($p) => in_array($p['estado'], ['no_cumple', 'aviso'], true)));
$prioridades = $problemas;
usort($prioridades, fn($a, $b) => [$orden_gravedad[$a['gravedad']], $orden_estado[$a['estado']]]
    <=> [$orden_gravedad[$b['gravedad']], $orden_estado[$b['estado']]]);
$criticos = array_values(array_filter($problemas, fn($p) => $p['gravedad'] === 'critica'));
$fallos = count(array_filter($problemas, fn($p) => $p['estado'] === 'no_cumple'));
$sin_datos = count(array_filter($puntos, fn($p) => $p['estado'] === 'sin_datos'));

cabecera('Informe de auditoría');
?>
<section>
    <p class="etiqueta">Informe de auditoría</p>
    <h1 class="url"><?= h($analisis['url']) ?></h1>
    <p class="datos">
        <?= $total_paginas ?> páginas analizadas · <?= $analisis['es_local'] ? 'Negocio local' : 'No es negocio local' ?> ·
        <?= h(date('d/m/Y H:i', strtotime($analisis['creado']))) ?>
    </p>

    <div class="notas">
        <?php foreach (NOTAS as $clave => $nombre): ?>
            <div class="nota">
                <span class="nombre"><?= $nombre ?></span>
                <span class="valor"><?= $notas[$clave] ?? '—' ?></span>
                <span class="escala"><?= $notas[$clave] === null ? 'Sin datos' : 'de 100' ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="acciones no-imprimir">
        <button type="button" onclick="window.print()">Guardar como PDF</button>
        <a href="index.php">Analizar otra web</a>
    </p>
</section>

<section>
    <h2>Resumen</h2>
    <p>
        Se han analizado <?= $total_paginas ?> páginas con <?= count($puntos) ?> comprobaciones automáticas.
        Hay <?= $fallos ?> puntos que no se cumplen y <?= count($problemas) - $fallos ?> avisos<?= $criticos ? ', ' . count($criticos) . ' de gravedad crítica' : '' ?>.
        <?php if ($prioridades): ?>
            Lo más urgente es «<?= h($prioridades[0]['titulo']) ?>».
        <?php else: ?>
            No se han detectado errores automáticamente.
        <?php endif; ?>
    </p>
</section>

<?php if ($criticos): ?>
<section>
    <h2>Problemas críticos</h2>
    <ul>
        <?php foreach ($criticos as $p): ?>
            <li><strong><?= h($p['titulo']) ?>.</strong> <?= h($p['detalle']) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($prioridades): ?>
<section>
    <h2>Qué arreglar primero</h2>
    <ol>
        <?php foreach (array_slice($prioridades, 0, 10) as $p): ?>
            <li><strong><?= h($p['titulo']) ?>.</strong> <?= h($p['arreglo']) ?></li>
        <?php endforeach; ?>
    </ol>
</section>
<?php endif; ?>

<section>
    <h2>Checklist completa</h2>
    <?php foreach (BLOQUES as $prefijo => $nombre): ?>
        <h3><?= h($nombre) ?></h3>
        <div class="tabla">
        <table>
            <thead>
                <tr><th scope="col">Estado</th><th scope="col">Punto</th><th scope="col">Qué se ha encontrado</th></tr>
            </thead>
            <tbody>
            <?php foreach ($puntos as $p): ?>
                <?php if (!str_starts_with($p['id'], $prefijo . '-')) continue; ?>
                <tr>
                    <td><span class="estado <?= h($p['estado']) ?>"><?= ETIQUETAS[$p['estado']] ?></span></td>
                    <td><strong><?= h($p['titulo']) ?></strong><br><span class="suave"><?= h($p['id']) ?> · <?= h(ucfirst(str_replace('critica', 'crítica', $p['gravedad']))) ?></span></td>
                    <td>
                        <?= h($p['detalle']) ?>
                        <?php if (in_array($p['estado'], ['no_cumple', 'aviso'], true)): ?>
                            <br><span class="suave">Cómo arreglarlo: <?= h($p['arreglo']) ?></span>
                        <?php endif; ?>
                        <?php if ($p['afectadas'] && $p['evaluadas'] > 1): ?>
                            <ul class="paginas-afectadas">
                                <?php foreach ($p['afectadas'] as $url): ?><li><?= h($url) ?></li><?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endforeach; ?>
</section>

<section>
    <h2>Revisión manual</h2>
    <p>La herramienta no puede comprobar estos puntos. Debe revisarlos una persona.</p>
    <ul>
        <?php foreach ($checklist['revision_manual'] as $r): ?>
            <li><strong><?= h($r['titulo']) ?>.</strong> <?= h($r['detalle']) ?></li>
        <?php endforeach; ?>
    </ul>
</section>

<section>
    <h2>Limitaciones del análisis</h2>
    <ul>
        <li>Es una evaluación automática basada en WCAG 2.2 AA. «Sin errores» significa sin errores detectables automáticamente; no certifica el cumplimiento de WCAG ni de la ley.</li>
        <li>La nota GEO mide la preparación técnica para la búsqueda con IA, no si una IA cita realmente la web.</li>
        <li>Los pesos son una decisión de diseño de esta herramienta: ningún buscador publica los suyos.</li>
        <li>Se lee el HTML inicial, sin ejecutar JavaScript. El contenido que se genera después no se analiza.</li>
        <?php if (!$analisis['pagespeed']): ?>
            <li>No se han obtenido datos de PageSpeed, así que la velocidad, el contraste y el tamaño de los botones salen como «sin datos».</li>
        <?php endif; ?>
        <?php if ($sin_datos): ?>
            <li><?= $sin_datos ?> puntos no se han podido comprobar y no cuentan en las notas.</li>
        <?php endif; ?>
        <?php if ($sin_ejecutar): ?>
            <li><?= $sin_ejecutar ?> comprobaciones no se han ejecutado porque la web no devolvió contenido válido.</li>
        <?php endif; ?>
    </ul>
</section>

<section>
    <h2>Glosario</h2>
    <dl>
        <?php foreach (require 'lib/glosario.php' as $termino => $definicion): ?>
            <dt><?= h($termino) ?></dt>
            <dd><?= h($definicion) ?></dd>
        <?php endforeach; ?>
    </dl>
</section>
<?php
pie();
