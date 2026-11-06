<?php
// UX-26 Calidad de subtítulos: los archivos VTT se abren, tienen texto, orden y ritmo legible.
return function (array $p): array {
    $pistas = $p['xpath']->query('//video/track[not(@kind) or @kind="subtitles" or @kind="captions"][@src]');
    if ($pistas->length === 0) {
        return preg_match('/youtube(-nocookie)?\.com\/embed|player\.vimeo\.com/i', $p['html'])
            ? sin_datos('Los vídeos están en YouTube o Vimeo y sus subtítulos no se pueden leer desde aquí.')
            : no_aplica('La página no tiene archivos de subtítulos.');
    }
    $segundos = fn(array $m, int $i) => (int) $m[$i] * 3600 + (int) $m[$i + 1] * 60 + (int) $m[$i + 2] + (int) $m[$i + 3] / 1000;
    $patron = '/(?:(\d+):)?(\d{2}):(\d{2})[.,](\d{3})\s*-->\s*(?:(\d+):)?(\d{2}):(\d{2})[.,](\d{3})[^\n]*\n(.*?)(?=\n\s*\n|\z)/s';
    foreach ($pistas as $i => $pista) {
        if ($i >= 3) {
            break;
        }
        $direccion = url_absoluta($p['url'], $pista->getAttribute('src'));
        try {
            $archivo = descargar($direccion, $p['config']);
        } catch (RuntimeException) {
            return no_cumple("No se puede abrir el archivo de subtítulos $direccion.");
        }
        if ($archivo['codigo'] !== 200) {
            return no_cumple("El archivo de subtítulos $direccion devuelve el código {$archivo['codigo']}.");
        }
        preg_match_all($patron, str_replace("\r", '', $archivo['cuerpo']), $bloques, PREG_SET_ORDER);
        if (!$bloques) {
            return no_cumple("El archivo de subtítulos $direccion no tiene ningún texto con tiempos.");
        }
        $anterior = 0;
        $rapidos = 0;
        foreach ($bloques as $b) {
            $inicio = $segundos($b, 1);
            $fin = $segundos($b, 5);
            if ($fin <= $inicio || $inicio < $anterior) {
                return aviso('Los tiempos de los subtítulos están desordenados, así que pueden no ir sincronizados.');
            }
            $anterior = $inicio;
            $rapidos += mb_strlen(trim(strip_tags($b[9]))) / ($fin - $inicio) > 25 ? 1 : 0;
        }
        if ($rapidos * 5 > count($bloques)) {
            return aviso("$rapidos de " . count($bloques) . ' subtítulos pasan demasiado rápido para leerlos.');
        }
    }
    return cumple('Los subtítulos se abren, tienen tiempos en orden y un ritmo legible.');
};
