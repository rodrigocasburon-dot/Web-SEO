/* Pruebas de AnalyzerExport (función 08). Escribe en #resultado un JSON {ok, fallos}. */
(function () {
  'use strict';
  var ok = 0;
  var fallos = [];
  function same(expected, actual, name) {
    var e = JSON.stringify(expected);
    var a = JSON.stringify(actual);
    if (e === a) ok++;
    else fallos.push(name + ': esperado ' + e + ', obtenido ' + a);
  }
  function check(name, fn) {
    try { fn(); } catch (err) { fallos.push(name + ': excepción ' + err.message); }
  }
  var X = window.AnalyzerExport;

  check('inyección de fórmulas', function () {
    same('"\'=SUMA(A1:A2)"', X.csvCell('=SUMA(A1:A2)'), '=');
    same('"\'+34 600"', X.csvCell('+34 600'), '+');
    same('"\'-1"', X.csvCell('-1'), '-');
    same('"\'@cmd"', X.csvCell('@cmd'), '@');
    same('"\'\tx"', X.csvCell('\tx'), 'tabulador');
    same('"\'\rx"', X.csvCell('\rx'), 'retorno de carro');
    same('"a=b"', X.csvCell('a=b'), 'solo al principio');
    same('""', X.csvCell(null), 'null');
    same('"dice ""hola""; adiós"', X.csvCell('dice "hola"; adiós'), 'comillas y punto y coma');
  });

  check('CSV con BOM, «;» y CRLF', function () {
    var csv = X.toCsv([['a', 'b'], ['1;2', 'ñ']]);
    same(65279, csv.charCodeAt(0), 'BOM');
    same('\uFEFF"a";"b"\r\n"1;2";"ñ"\r\n', csv, 'contenido');
  });

  check('filas: columnas pedidas y textos legibles', function () {
    var i18n = {
      areas: { seo: 'SEO', ia: 'Preparación para IA', accesibilidad: 'Accesibilidad' },
      estados: { NO_CUMPLE: 'Fallo', AVISO: 'Aviso', CUMPLE: 'Sin errores detectables automáticamente', SIN_DATOS: 'Sin datos' },
      severidades: { CRITICO: 'Crítica', ALTO: 'Alta' },
      niveles: { ALTO: 'Alto', MEDIO: 'Medio', BAJO: 'Bajo' }
    };
    var checks = [
      { id: 'C06', nombre: 'Texto alternativo', detalle: '2 imágenes sin atributo alt.',
        pilares: { seo: 0.2, ia: 0.2, accesibilidad: 0.6 }, estado: 'NO_CUMPLE', aplica: true, severidad: 'ALTO',
        valor_medido: { imagenes: 8, sin_alt: 2, ratio: 0.25, lista: ['a', 'b'] }, umbral: 'Todas con alt', criterio: 'WCAG 1.1.1',
        evidencia: [{ selector: 'img#logo', extracto: '<img src="=x.png">', linea: 12 }, { selector: null, extracto: 'nota', linea: null }],
        recomendacion: '=HYPERLINK("http://x")', impacto: 'ALTO', esfuerzo: 'BAJO' },
      { id: 'C13', pilares: { seo: 1, ia: 0, accesibilidad: 0 }, estado: 'CUMPLE', aplica: false, severidad: 'ALTO',
        valor_medido: null, umbral: null, criterio: '', evidencia: [], recomendacion: '', impacto: 'MEDIO', esfuerzo: 'MEDIO' }
    ];
    var rows = X.buildRows(checks, i18n);
    same(['id', 'comprobación', 'área', 'estado', 'detalle', 'severidad', 'valor medido', 'umbral', 'criterio', 'evidencia', 'recomendación', 'impacto', 'esfuerzo'], rows[0], 'cabecera');
    same(['C06', 'Texto alternativo', 'SEO 20 %; Preparación para IA 20 %; Accesibilidad 60 %', 'Fallo', '2 imágenes sin atributo alt.', 'Alta',
      'imagenes: 8; sin alt: 2; ratio: 0,25; lista: a, b', 'Todas con alt', 'WCAG 1.1.1',
      'img#logo: <img src="=x.png"> (línea 12) | nota', '=HYPERLINK("http://x")', 'Alto', 'Bajo'], rows[1], 'fila C06');
    same(['C13', '', 'SEO 100 %', 'No aplica', '', 'Alta', '', '', '', '', '', 'Medio', 'Medio'], rows[2], 'fila no aplica');
    var csv = X.toCsv(rows);
    same(true, csv.indexOf('"\'=HYPERLINK(""http://x"")"') !== -1, 'la recomendación con fórmula se neutraliza en el CSV');
    same(3, csv.split('\r\n').length - 1, 'una línea por fila');
  });

  check('JSON completo con metadatos', function () {
    var json = JSON.parse(X.buildJson({ url: 'https://e.com/', checks: [{ id: 'C01' }] }, { exportado: '2026-01-02T03:04:05.000Z', provisional: true }));
    same('Analizador web de Proconsi', json.herramienta, 'herramienta');
    same('2026-01-02T03:04:05.000Z', json.exportado, 'fecha');
    same(true, json.puntuacion_provisional, 'provisional');
    same('https://e.com/', json.url, 'url');
    same('C01', json.checks[0].id, 'checks');
  });

  check('nombre de archivo seguro', function () {
    var d = new Date(2026, 10, 6, 9, 5);
    same('analisis-www.proconsi.com-20261106-0905.csv', X.fileName('https://www.proconsi.com/a?b=1', d, 'csv'), 'normal');
    same('analisis-pagina-20261106-0905.json', X.fileName('no es url', d, 'json'), 'URL no válida');
    same('analisis-xn--espaa-rta.es-20261106-0905.csv', X.fileName('https://españa.es/', d, 'csv'), 'dominio internacional');
  });

  check('valores medidos', function () {
    same('', X.valueText(null), 'null');
    same('sí', X.valueText(true), 'booleano');
    same('1234,5', X.valueText(1234.5), 'número sin miles');
    same('a: 1 | b: 2', X.valueText([{ a: 1 }, { b: 2 }]), 'lista de objetos');
  });

  // v3.0: una web bloqueada no se exporta con notas de 100
  check('JSON con acceso bloqueado, sin notas', function () {
    var json = JSON.parse(X.buildJson({ url: 'https://e.com/', blocked: { reason: 'captcha' }, puntuacion: { global: { puntuacion: 100 } } }, {}));
    same(false, json.puntuacion.calculable, 'no calculable');
    same(undefined, json.puntuacion.global, 'sin nota global');
  });

  document.getElementById('resultado').textContent = JSON.stringify({ ok: ok, fallos: fallos });
})();
