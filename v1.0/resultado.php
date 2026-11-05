<?php
require 'lib/db.php';
require 'lib/motor.php';
require 'lib/plantilla.php';

const ETIQUETAS = [
    'cumple' => 'Cumple',
    'no_cumple' => 'No cumple',
    'aviso' => 'Aviso',
    'sin_datos' => 'Sin datos',
    'no_aplica' => 'No aplica',
];
const BLOQUES = ['seo' => 'SEO', 'geo' => 'GEO', 'ux' => 'UX'];

$db = conectar(cargar_config());
$consulta = $db->prepare('SELECT a.url, a.es_local, p.id AS pagina_id, p.url AS url_final, p.codigo, p.tiempo_ms
    FROM analisis a JOIN paginas p ON p.analisis_id = a.id WHERE a.id = ?');
$consulta->execute([(int) ($_GET['id'] ?? 0)]);
$analisis = $consulta->fetch(PDO::FETCH_ASSOC);

if (!$analisis) {
    http_response_code(404);
    cabecera('Análisis no encontrado');
    echo '<section class="inicio"><h1>Análisis no encontrado</h1><p>Los análisis se borran a las 24 horas. <a href="index.php">Haz uno nuevo</a>.</p></section>';
    pie();
    exit;
}

$consulta = $db->prepare('SELECT punto_id, estado, detalle FROM resultados WHERE pagina_id = ?');
$consulta->execute([$analisis['pagina_id']]);
$filas = array_column($consulta->fetchAll(PDO::FETCH_ASSOC), null, 'punto_id');

$checklist = cargar_checklist();
$notas = calcular_notas(array_column($filas, 'estado', 'punto_id'), $checklist);

// Primero lo que hay que arreglar y, dentro de eso, lo más grave.
$orden_estado = ['no_cumple' => 0, 'aviso' => 1, 'sin_datos' => 2, 'cumple' => 3, 'no_aplica' => 4];
$orden_gravedad = array_flip(array_keys($checklist['pesos']));
$puntos = [];
$pendientes = 0;
foreach ($checklist['puntos'] as $punto) {
    $fila = $filas[$punto['id']] ?? ['estado' => 'pendiente', 'detalle' => ''];
    if ($fila['estado'] === 'pendiente') {
        $pendientes++;
        continue;
    }
    $puntos[] = $punto + $fila;
}
usort($puntos, fn($a, $b) => [$orden_estado[$a['estado']], $orden_gravedad[$a['gravedad']]]
    <=> [$orden_estado[$b['estado']], $orden_gravedad[$b['gravedad']]]);

cabecera('Resultado del análisis');
?>
<section>
    <p class="etiqueta">Resultado del análisis</p>
    <h1 class="url"><?= h($analisis['url_final']) ?></h1>
    <p class="datos">
        Código <?= (int) $analisis['codigo'] ?> · <?= (int) $analisis['tiempo_ms'] ?> ms ·
        <?= $analisis['es_local'] ? 'Negocio local' : 'No es negocio local' ?>
    </p>

    <div class="notas">
        <?php foreach (BLOQUES as $clave => $nombre): ?>
            <div class="nota">
                <span class="nombre"><?= $nombre ?></span>
                <span class="valor"><?= $notas[$clave] ?? '—' ?></span>
                <span class="escala"><?= $notas[$clave] === null ? 'Sin datos' : 'de 100' ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($pendientes): ?>
        <p class="aviso-parcial"><?= $pendientes ?> de <?= count($checklist['puntos']) ?> comprobaciones aún no están programadas y no cuentan en estas notas.</p>
    <?php endif; ?>
</section>

<section>
    <h2>Puntos analizados</h2>
    <div class="tabla">
    <table>
        <thead>
            <tr><th scope="col">Estado</th><th scope="col">Punto</th><th scope="col">Qué se ha encontrado</th></tr>
        </thead>
        <tbody>
        <?php foreach ($puntos as $p): ?>
            <tr>
                <td><span class="estado <?= h($p['estado']) ?>"><?= ETIQUETAS[$p['estado']] ?></span></td>
                <td><strong><?= h($p['titulo']) ?></strong><br><span class="suave"><?= h($p['id']) ?> · <?= h(ucfirst($p['gravedad'])) ?></span></td>
                <td>
                    <?= h($p['detalle']) ?>
                    <?php if (in_array($p['estado'], ['no_cumple', 'aviso'], true)): ?>
                        <br><span class="suave">Cómo arreglarlo: <?= h($p['arreglo']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section>
    <h2>Revisión manual</h2>
    <p>La herramienta no puede comprobar estos puntos. Debe revisarlos una persona.</p>
    <ul>
        <?php foreach ($checklist['revision_manual'] as $r): ?>
            <li><strong><?= h($r['titulo']) ?>.</strong> <?= h($r['detalle']) ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="index.php">Analizar otra web</a></p>
</section>
<?php
pie();
