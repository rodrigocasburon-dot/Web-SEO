<?php
// SEO-26 Intención de búsqueda: el inicio del texto responde a lo que prometen el title y el H1.
return function (array $p): array {
    $texto = $p['texto_principal'];
    if (preg_match('/lorem ipsum|en construcción|under construction|coming soon|texto de ejemplo/iu', $texto)) {
        return no_cumple('La página tiene texto de relleno o está en construcción.');
    }
    // El H1 no cuenta: si no, todas las páginas responderían a su propio título.
    $h1 = trim((string) $p['xpath']->evaluate('string(//h1[1])'));
    $lista = palabras($h1 !== '' ? str_replace($h1, '', $texto) : $texto);
    if (count($lista) < 100) {
        return no_aplica('La página tiene muy poco texto para compararlo con su título.');
    }
    $title = preg_split('/\s[|·–—-]\s/u', (string) $p['xpath']->evaluate('string(//head/title)'))[0];
    $temas = array_unique(palabras_utiles($title . ' ' . $h1));
    if (count($temas) < 2) {
        return sin_datos('El title y el H1 no tienen palabras suficientes para saber de qué trata la página.');
    }
    // Se compara el principio de cada palabra para aceptar singular y plural.
    $raiz = fn(string $palabra) => mb_substr($palabra, 0, 5);
    $inicio = array_map($raiz, array_slice($lista, 0, 150));
    $encontrados = array_filter($temas, fn($tema) => in_array($raiz($tema), $inicio, true));
    $porcentaje = (int) round(count($encontrados) / count($temas) * 100);
    return $porcentaje >= 50
        ? cumple("El inicio del texto trata el $porcentaje % de los temas del title y el H1.")
        : aviso("El inicio del texto solo trata el $porcentaje % de los temas del title y el H1: puede no responder a lo que se busca.");
};
