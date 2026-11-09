<?php
/**
 * v3.0 · Pruebas con Chrome de verdad (traídas de la v1.0): pulsa Tab, cambia el ancho de la ventana,
 * busca el aviso de cookies, prueba el carrusel y envía vacío un formulario (sin datos).
 * Son INFORMATIVAS: no puntúan, porque sus pesos no están validados por el grupo.
 *
 * Seguridad: Chrome ejecuta el JavaScript de la web analizada. Navegador intercepta todas sus peticiones:
 * solo deja GET/HEAD/OPTIONS a destinos que pasan UrlGuard (http/https, 80/443, sin IP privadas) y bloquea
 * WebSockets. Perfil temporal que se borra al terminar. Usar solo en red interna.
 * Desactivado por defecto: config 'chrome' => ['activo' => true].
 */
class BrowserChecks
{
    public const NAMES = [
        'B01' => 'Recorrido con teclado',
        'B02' => 'Foco no tapado',
        'B03' => 'Zoom al 400 % y reflow',
        'B04' => 'Mensajes de error en formularios',
        'B05' => 'Alternativas al arrastre en carruseles',
        'B06' => 'Rechazar cookies con un clic',
    ];

    /** Configuración en el formato que espera Navegador (v1.0). */
    public static function navegadorConfig(array $config): array
    {
        $c = (array) ($config['chrome'] ?? []);
        return ['chrome_ruta' => (string) ($c['ruta'] ?? ''), 'chrome_sin_sandbox' => !empty($c['sin_sandbox'])];
    }

    public static function available(array $config): bool
    {
        if (empty($config['chrome']['activo'])) {
            return false;
        }
        class_exists('Navegador');   // carga también ruta_chrome()
        return ruta_chrome(self::navegadorConfig($config)) !== null;
    }

    /** @return array[] registros B01…B06 (estado, detalle, aplica) */
    public static function run(string $url, array $config): array
    {
        $chrome = null;
        try {
            $chrome = new Navegador(self::navegadorConfig($config));
            $code = (string) file_get_contents(__DIR__ . '/browser/medidas.js');
            $chrome->tamano(null);
            $chrome->ir($url);
            $chrome->evaluar($code);
            $out = ['B06' => self::cookies($chrome)];
            // Algunos avisos de cookies recargan la página al cerrarse
            $chrome->evaluar($code);
            $out += self::keyboard($chrome);
            $out['B05'] = self::carousel($chrome);
            $out['B03'] = self::reflow($chrome);
            $chrome->tamano(null);
            $out['B04'] = self::form($chrome) ?? self::r('NA', 'La página no tiene un formulario que probar.');
        } finally {
            if ($chrome) {
                $chrome->cerrar();
            }
        }
        ksort($out);
        $records = [];
        foreach ($out as $id => $r) {
            $records[] = [
                'id' => $id,
                'nombre' => self::NAMES[$id],
                'estado' => $r['estado'],
                'aplica' => $r['aplica'],
                'detalle' => $r['detalle'],
                'informativa' => true,
            ];
        }
        return $records;
    }

    private static function r(string $estado, string $detalle): array
    {
        return $estado === 'NA'
            ? ['estado' => BaseCheck::OK, 'aplica' => false, 'detalle' => $detalle]
            : ['estado' => $estado, 'aplica' => true, 'detalle' => $detalle];
    }

    private static function cookies(Navegador $chrome): array
    {
        $aviso = $chrome->evaluar('__medir.cookies()');
        for ($i = 0; $i < 3 && !$aviso['aceptar'] && !$aviso['rechazar']; $i++) {
            $chrome->mover_raton();
            $chrome->pausa(800);
            $aviso = $chrome->evaluar('__medir.cookies()');
        }
        $chrome->evaluar('document.activeElement && document.activeElement.blur()');
        if (!$aviso['aceptar'] && !$aviso['rechazar']) {
            return self::r('NA', 'Chrome no ha visto ningún aviso de cookies con botones.');
        }
        if ($aviso['rechazar']) {
            $r = self::r(BaseCheck::OK, $aviso['aceptar'] ? 'Aceptar y rechazar están a un clic.' : 'El aviso permite rechazar con un clic.');
        } elseif ($aviso['configurar']) {
            $chrome->evaluar('__medir.pulsar("configurar")');
            $chrome->pausa(900);
            $segundo = $chrome->evaluar('__medir.cookies()');
            $r = self::r(BaseCheck::WARN, $segundo['rechazar']
                ? 'Aceptar es un clic y rechazar necesita al menos dos.'
                : 'Aceptar es un clic y no aparece ningún botón de rechazar al abrir la configuración.');
        } else {
            $r = self::r(BaseCheck::WARN, 'El aviso permite aceptar y no ofrece rechazar.');
        }
        $chrome->evaluar('__medir.cookies(); __medir.pulsar("rechazar"); __medir.pulsar("aceptar")');
        $chrome->pausa(1500);
        return $r;
    }

    private static function keyboard(Navegador $chrome): array
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
                // Volver al principio tras recorrerlo todo es normal; antes de tiempo, es una trampa
                $atrapado = count($vistos) < min($total, 40) / 2;
                break;
            }
            $vistos[$foco['id']] = true;
            $invisibles += $foco['visible'] ? 0 : 1;
            if ($anterior !== null && $foco['y'] < $anterior['y'] - 300 && abs($foco['x'] - $anterior['x']) < 100) {
                $saltos++;
            }
            $anterior = $foco;
            if ($foco['tapado']) {
                $tapados[] = $foco;
            }
        }
        $n = count($vistos);
        if ($n === 0) {
            return [
                'B01' => $total > 0 ? self::r(BaseCheck::FAIL, "Hay $total controles visibles y Tab no llega a ninguno.") : self::r('NA', 'La página no tiene controles que reciban el foco.'),
                'B02' => self::r('NA', 'Tab no ha llegado a ningún control.'),
            ];
        }
        $problemas = array_filter([
            $invisibles ? "controles que reciben el foco sin verse: $invisibles" : '',
            $saltos >= 3 ? "el foco salta hacia atrás $saltos veces" : '',
        ]);
        if ($atrapado) {
            $teclado = self::r(BaseCheck::FAIL, "El foco se queda dando vueltas en $n de $total controles.");
        } elseif ($problemas) {
            $teclado = self::r(BaseCheck::WARN, "Recorridos $n controles: " . implode('; ', $problemas) . '.');
        } else {
            $teclado = self::r(BaseCheck::OK, "Tab recorre $n controles en orden y sin trampas (se prueban como máximo 40).");
        }
        return [
            'B01' => $teclado,
            'B02' => $tapados
                ? self::r(BaseCheck::FAIL, count($tapados) . " de $n controles quedan tapados por {$tapados[0]['tapador']} al recibir el foco, como «{$tapados[0]['nombre']}».")
                : self::r(BaseCheck::OK, "Ninguno de los $n controles queda tapado al recibir el foco."),
        ];
    }

    /** WCAG 1.4.10: que todo quepa en 320 px (1280 px con zoom al 400 %). */
    private static function reflow(Navegador $chrome): array
    {
        foreach ([640, 320] as $ancho) {
            $chrome->tamano($ancho, 640);
            $m = $chrome->evaluar('__medir.reflow()');
            if ($m['sobra'] > 1) {
                return self::r(BaseCheck::FAIL, "A $ancho px de ancho aparece scroll horizontal: sobran {$m['sobra']} px.");
            }
            if ($m['cortados'] > 0) {
                return self::r(BaseCheck::WARN, "A $ancho px hay {$m['cortados']} textos cortados, como «{$m['ejemplo']}».");
            }
        }
        return self::r(BaseCheck::OK, 'La página cabe a 640 y 320 px sin scroll horizontal ni textos cortados.');
    }

    private static function carousel(Navegador $chrome): array
    {
        $c = $chrome->evaluar('__medir.carrusel()');
        if ($c === null) {
            return self::r('NA', 'Chrome no ha visto carruseles en la página.');
        }
        if (!$c['boton']) {
            return self::r(BaseCheck::WARN, 'Hay un carrusel y no tiene botones visibles.');
        }
        return $c['cambia']
            ? self::r(BaseCheck::OK, 'El carrusel se mueve con su botón, sin arrastrar.')
            : self::r(BaseCheck::WARN, 'El botón del carrusel no cambia nada al pulsarlo.');
    }

    private static function form(Navegador $chrome): ?array
    {
        try {
            $f = $chrome->evaluar('__medir.formulario()');
        } catch (RuntimeException $e) {
            return self::r(BaseCheck::NO_DATA, 'El formulario se envía sin validar: el error solo se vería enviando datos, y no se envían.');
        }
        if ($f === null) {
            return null;
        }
        if ($f['tipo'] === 'navegador') {
            return self::r(BaseCheck::OK, "Al dejar un campo obligatorio vacío, el navegador avisa con texto: «{$f['mensaje']}».");
        }
        if ($f['avisos'] || $f['nuevo']) {
            return self::r(BaseCheck::OK, 'Al enviarlo vacío aparece un mensaje de texto' . ($f['avisos'] ? ": «{$f['avisos'][0]}»." : '.'));
        }
        return $f['color'] || $f['invalidos']
            ? self::r(BaseCheck::WARN, 'Al enviarlo vacío solo cambia el aspecto de los campos, sin ningún texto.')
            : self::r(BaseCheck::NO_DATA, 'Al enviarlo vacío no cambia nada en la página: el error solo se vería enviando datos, y no se envían.');
    }
}
