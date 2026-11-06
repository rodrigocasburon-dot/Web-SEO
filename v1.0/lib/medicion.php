<?php
// Mide en páginas de muestra lo que no se puede saber leyendo el código: con Chrome y con IA.

const PUNTOS_NAVEGADOR = ['UX-21', 'UX-22', 'UX-23', 'UX-24', 'UX-25', 'UX-27'];
const PUNTOS_IA = ['UX-20', 'SEO-26'];

// La portada, las páginas con formulario o carrusel y, si queda sitio, las primeras de la web.
function paginas_de_muestra(PDO $db, int $analisis_id, int $maximo): array
{
    $consulta = $db->prepare("SELECT p.id, p.url, MAX(r.punto_id = 'UX-24' AND r.estado IN ('cumple', 'aviso')) AS formulario,
            MAX(r.punto_id = 'UX-25' AND r.estado <> 'no_aplica') AS carrusel
        FROM paginas p LEFT JOIN resultados r ON r.pagina_id = p.id
        WHERE p.analisis_id = ? AND p.estado = 'hecha' AND p.codigo = 200 GROUP BY p.id, p.url ORDER BY p.id");
    $consulta->execute([$analisis_id]);
    $paginas = $consulta->fetchAll(PDO::FETCH_ASSOC);
    if (!$paginas) {
        return [];
    }
    $muestra = [$paginas[0]];
    $resto = array_slice($paginas, 1);
    $con_formulario = array_slice(array_filter($resto, fn($p) => $p['formulario']), 0, 2);
    $con_carrusel = array_slice(array_filter($resto, fn($p) => $p['carrusel']), 0, 1);
    foreach ([...$con_formulario, ...$con_carrusel, ...$resto] as $pagina) {
        if (count($muestra) < $maximo && !in_array($pagina['id'], array_column($muestra, 'id'), true)) {
            $muestra[] = $pagina;
        }
    }
    return $muestra;
}

// Cambia lo estimado por lo medido. $medidas es pagina_id => punto_id => resultado.
function guardar_medidas(PDO $db, array $analisis, array $medidas, array $puntos): void
{
    $checklist = cargar_checklist();
    $por_id = array_column($checklist['puntos'], null, 'id');
    $valores = $checklist['valor_resultado'];
    $portada = array_key_first($medidas);

    $db->beginTransaction();
    $borrar = $db->prepare('DELETE r FROM resultados r JOIN paginas p ON p.id = r.pagina_id WHERE p.analisis_id = ? AND r.punto_id = ?');
    $insertar = $db->prepare('INSERT INTO resultados (pagina_id, punto_id, estado, detalle) VALUES (?, ?, ?, ?)');
    foreach ($puntos as $punto) {
        $filas = [];
        foreach ($medidas as $pagina_id => $resultados) {
            if (isset($resultados[$punto])) {
                $filas[$pagina_id] = $resultados[$punto];
            }
        }
        if (!$filas) {
            continue;
        }
        $borrar->execute([$analisis['id'], $punto]);
        // Los puntos de toda la web guardan una fila: la peor que se ha medido.
        if ($por_id[$punto]['alcance'] === 'dominio') {
            uasort($filas, fn($a, $b) => ($valores[$a['estado']] ?? 2) <=> ($valores[$b['estado']] ?? 2));
            $filas = [$portada => reset($filas)];
        }
        foreach ($filas as $pagina_id => $resultado) {
            $insertar->execute([$pagina_id, $punto, $resultado['estado'], $resultado['detalle']]);
        }
    }
    $db->commit();
}

// Devuelve el número de páginas medidas. Sin Chrome, se quedan las estimaciones.
function medir_con_navegador(PDO $db, array $analisis, array $config): int
{
    if (ruta_chrome($config) === null) {
        return 0;
    }
    $medidas = [];
    $fin = microtime(true) + $config['navegador_segundos'];
    $codigo = file_get_contents(__DIR__ . '/medidas.js');
    $chrome = null;
    try {
        $chrome = new Navegador($config);
        foreach (paginas_de_muestra($db, $analisis['id'], $config['muestra_paginas']) as $i => $pagina) {
            if (microtime(true) > $fin) {
                break;
            }
            try {
                $medidas[$pagina['id']] = medir_pagina($chrome, $pagina['url'], $codigo, $i === 0);
            } catch (RuntimeException $e) {
                error_log("Web-SEO: no se ha medido {$pagina['url']}: {$e->getMessage()}");
            }
        }
    } catch (RuntimeException $e) {
        // Se conserva lo que se haya medido antes del fallo.
        error_log("Web-SEO: Chrome ha fallado: {$e->getMessage()}");
    } finally {
        $chrome?->cerrar();
    }
    if ($medidas) {
        guardar_medidas($db, $analisis, $medidas, PUNTOS_NAVEGADOR);
    }
    return count($medidas);
}

function medir_pagina(Navegador $chrome, string $url, string $codigo, bool $es_portada): array
{
    $chrome->tamano(null);
    $chrome->ir($url);
    $chrome->evaluar($codigo);

    $resultados = [];
    $cookies = medir_cookies($chrome);
    if ($es_portada) {
        $resultados['UX-27'] = $cookies;
    }
    // Algunos avisos recargan la página al cerrarse y se pierden las medidas.
    $chrome->evaluar($codigo);
    $resultados += medir_teclado($chrome);
    $resultados['UX-25'] = medir_carrusel($chrome);
    $resultados['UX-23'] = medir_reflow($chrome);
    $chrome->tamano(null);
    // Va la última porque deja la página con errores a la vista.
    $formulario = medir_formulario($chrome);
    if ($formulario) {
        $resultados['UX-24'] = $formulario;
    }
    return $resultados;
}

function medir_cookies(Navegador $chrome): array
{
    $aviso = $chrome->evaluar('__medir.cookies()');
    for ($i = 0; $i < 3 && !$aviso['aceptar'] && !$aviso['rechazar']; $i++) {
        $chrome->mover_raton();
        $chrome->pausa(800);
        $aviso = $chrome->evaluar('__medir.cookies()');
    }
    $chrome->evaluar('document.activeElement && document.activeElement.blur()');
    if (!$aviso['aceptar'] && !$aviso['rechazar']) {
        return no_aplica('Chrome no ha visto ningún aviso de cookies con botones en la página.');
    }
    if ($aviso['rechazar']) {
        $resultado = $aviso['aceptar']
            ? cumple('Medido con Chrome: aceptar y rechazar están a un clic.')
            : cumple('Medido con Chrome: el aviso permite rechazar con un clic.');
    } elseif ($aviso['configurar']) {
        $chrome->evaluar('__medir.pulsar("configurar")');
        $chrome->pausa(900);
        $segundo = $chrome->evaluar('__medir.cookies()');
        $resultado = $segundo['rechazar']
            ? aviso('Medido con Chrome: aceptar es un clic y rechazar necesita al menos dos.')
            : aviso('Medido con Chrome: aceptar es un clic y no aparece ningún botón de rechazar al abrir la configuración.');
    } else {
        $resultado = aviso('Medido con Chrome: el aviso permite aceptar y no ofrece rechazar.');
    }
    // Se quita el aviso para que no estorbe en las demás medidas.
    $chrome->evaluar('__medir.cookies(); __medir.pulsar("rechazar"); __medir.pulsar("aceptar")');
    $chrome->pausa(1500);
    return $resultado;
}

// Pulsa Tab como una persona y anota por dónde pasa el foco.
function medir_teclado(Navegador $chrome): array
{
    $total = (int) $chrome->evaluar('__medir.enfocables()');
    $vistos = [];
    $anterior = null;
    $atrapado = false;
    $invisibles = 0;
    $saltos = 0;
    $tapados = [];
    for ($i = 0; $i < 40; $i++) {
        $chrome->tecla_tab();
        $chrome->pausa(70);
        $foco = $chrome->evaluar('__medir.foco()');
        if ($foco === null || $foco['etiqueta'] === 'IFRAME') {
            break;
        }
        if (isset($vistos[$foco['id']])) {
            // Volver al principio tras recorrerlo todo es normal. Antes de tiempo, es una trampa.
            $atrapado = count($vistos) < min($total, 40) / 2;
            break;
        }
        $vistos[$foco['id']] = true;
        $invisibles += $foco['visible'] ? 0 : 1;
        // Subir a otra columna es normal. Subir dentro de la misma columna, no.
        if ($anterior !== null && $foco['y'] < $anterior['y'] - 300 && abs($foco['x'] - $anterior['x']) < 100) {
            $saltos++;
        }
        $anterior = $foco;
        if ($foco['tapado']) {
            $tapados[] = $foco;
        }
    }
    $recorridos = count($vistos);
    if ($recorridos === 0) {
        return ['UX-21' => $total > 0
            ? no_cumple("Medido con Chrome: hay $total controles visibles y Tab no llega a ninguno.")
            : no_aplica('La página no tiene controles que reciban el foco.'),
            'UX-22' => no_aplica('Tab no ha llegado a ningún control.')];
    }
    $problemas = array_filter([
        $invisibles ? "controles que reciben el foco sin verse: $invisibles" : '',
        $saltos >= 3 ? "el foco salta hacia atrás $saltos veces" : '',
    ]);
    $teclado = match (true) {
        $atrapado => no_cumple("Medido con Chrome: el foco se queda dando vueltas en $recorridos de $total controles."),
        (bool) $problemas => aviso("Medido con Chrome en $recorridos controles: " . implode('; ', $problemas) . '.'),
        default => cumple("Medido con Chrome: Tab recorre $recorridos controles en orden y sin trampas."),
    };
    return ['UX-21' => $teclado, 'UX-22' => $tapados
        ? no_cumple('Medido con Chrome: ' . count($tapados) . " de $recorridos controles quedan tapados por {$tapados[0]['tapador']}, como \"{$tapados[0]['nombre']}\".")
        : cumple("Medido con Chrome: ninguno de los $recorridos controles queda tapado al recibir el foco.")];
}

// WCAG pide que todo quepa en 320 px. Son 1280 px de pantalla con zoom al 400 %.
function medir_reflow(Navegador $chrome): array
{
    foreach ([640, 320] as $ancho) {
        $chrome->tamano($ancho, 640);
        $medida = $chrome->evaluar('__medir.reflow()');
        if ($medida['sobra'] > 1) {
            return no_cumple("Medido con Chrome: a $ancho px de ancho aparece scroll horizontal, sobran {$medida['sobra']} px.");
        }
        if ($medida['cortados'] > 0) {
            return aviso("Medido con Chrome: a $ancho px hay {$medida['cortados']} textos cortados, como \"{$medida['ejemplo']}\".");
        }
    }
    return cumple('Medido con Chrome: la página cabe a 640 y 320 px sin scroll horizontal ni textos cortados.');
}

function medir_carrusel(Navegador $chrome): array
{
    $carrusel = $chrome->evaluar('__medir.carrusel()');
    return match (true) {
        $carrusel === null => no_aplica('Chrome no ha visto carruseles en la página.'),
        !$carrusel['boton'] => aviso('Medido con Chrome: hay un carrusel y no tiene botones visibles.'),
        !$carrusel['cambia'] => aviso('Medido con Chrome: el botón del carrusel no cambia nada al pulsarlo.'),
        default => cumple('Medido con Chrome: el carrusel se mueve con su botón, sin arrastrar.'),
    };
}

// Devuelve null si la página no tiene un formulario que probar.
function medir_formulario(Navegador $chrome): ?array
{
    try {
        $f = $chrome->evaluar('__medir.formulario()');
    } catch (RuntimeException) {
        return sin_datos('El formulario se envía sin validar en la página: el error solo se vería enviando datos, y no se envían.');
    }
    if ($f === null) {
        return null;
    }
    if ($f['tipo'] === 'navegador') {
        return cumple("Medido con Chrome: al dejar un campo vacío, el navegador avisa con texto: \"{$f['mensaje']}\".");
    }
    if ($f['avisos'] || $f['nuevo']) {
        return cumple('Medido con Chrome: al enviarlo vacío aparece un mensaje de texto' . ($f['avisos'] ? ": \"{$f['avisos'][0]}\"." : '.'));
    }
    return $f['color'] || $f['invalidos']
        ? aviso('Medido con Chrome: al enviarlo vacío solo cambia el aspecto de los campos, sin ningún texto.')
        : sin_datos('Al enviarlo vacío no cambia nada en la página: el error solo se vería enviando datos, y no se envían.');
}

// Devuelve el número de páginas valoradas. Sin clave de IA, se quedan las estimaciones.
function medir_con_ia(PDO $db, array $analisis, array $config): int
{
    if ($config['ia_clave'] === '') {
        return 0;
    }
    $medidas = [];
    $imagenes = [];
    foreach (paginas_de_muestra($db, $analisis['id'], $config['muestra_paginas']) as $fila) {
        try {
            $descarga = descargar($fila['url'], $config);
            $pagina = crear_pagina($descarga, $fila['url'], $config, $analisis['robots'], false);
            $medidas[$fila['id']] = [];
            $intencion = valorar_intencion($pagina, $config);
            if ($intencion) {
                $medidas[$fila['id']]['SEO-26'] = $intencion;
            }
            foreach (imagenes_con_alt($pagina) as $imagen) {
                if (count($imagenes) < $config['ia_imagenes'] && !isset($imagenes[$imagen['url']])) {
                    $imagenes[$imagen['url']] = $imagen + ['pagina' => $fila['id']];
                }
            }
        } catch (RuntimeException) {
            continue;
        }
    }
    try {
        foreach (valorar_imagenes(array_values($imagenes), $config) as $pagina_id => $resultado) {
            $medidas[$pagina_id]['UX-20'] = $resultado;
        }
    } catch (RuntimeException) {
        // Las imágenes siguen con su estimación.
    }
    $medidas = array_filter($medidas);
    if ($medidas) {
        guardar_medidas($db, $analisis, $medidas, PUNTOS_IA);
    }
    return count($medidas);
}
