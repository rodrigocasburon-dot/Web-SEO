// Pruebas de assets/js/pdf.js en Node (sin navegador): texto en WinAnsi, cortes de línea y estructura del PDF.
// Uso: node tests/js/pdf_test.js  → imprime JSON {ok, fallos}
'use strict';
var P = require('../../assets/js/pdf.js');
var ok = 0;
var fallos = [];
function check(name, fn) {
  try { fn(); ok++; } catch (e) { fallos.push(name + ': ' + e.message); }
}
function same(a, b, msg) { if (a !== b) throw new Error((msg || '') + ' esperado ' + JSON.stringify(a) + ', obtenido ' + JSON.stringify(b)); }

check('WinAnsi: acentos, eñe y signos españoles en un byte; lo que no existe se sustituye', function () {
  same('\xe1\xe9\xed\xf3\xfa\xf1\xbf\xa1\xab\xbb', P.winAnsi('áéíóúñ¿¡«»'));
  same('\x97\x85\x80', P.winAnsi('—…€'), 'guion largo, puntos suspensivos y euro');
  same('x v ->', P.winAnsi('✕ ✓ →'));
  same('a b', P.winAnsi('a\nb'));
});

check('Cortes de línea: caben en el ancho y las palabras muy largas (URL) se parten', function () {
  var lines = P.wrap('https://proconsi.com/' + new Array(60).join('ruta-larga/'), 9, false, 200);
  same(true, lines.length > 3, 'varias líneas');
  lines = P.wrap('Hola mundo', 10, false, 500);
  same(1, lines.length);
});

check('PDF: cabecera, tabla xref coherente, páginas y pie con «Página X de Y»', function () {
  var doc = P.createSync({ eyebrow: 'Radar web · Prueba', titulo: 'https://e.com/', subtitulo: ['Fecha'] });
  doc.h2('Sección');
  doc.scores([{ etiqueta: 'SEO', nota: 90, detalle: 'x' }, { etiqueta: 'GEO', nota: null, detalle: '' }, { etiqueta: 'UX', nota: 10, detalle: '' }]);
  for (var i = 0; i < 40; i++) doc.item({ titulo: 'C0' + i + ' · Punto (con paréntesis) y \\ barra', estado: i % 2 ? 'NO_CUMPLE' : 'AVISO', linea: 'Fallo', texto: 'Texto largo de prueba para ocupar sitio en la página.', arreglo: 'Arréglalo.' });
  var bin = doc.bytes();
  same(0, bin.indexOf('%PDF-1.4'), 'cabecera');
  var m = /xref\n0 (\d+)\n0000000000 65535 f \n([\s\S]*?)trailer/.exec(bin);
  var offs = m[2].trim().split('\n').map(function (l) { return parseInt(l.slice(0, 10), 10); });
  offs.forEach(function (o, k) { same(0, bin.indexOf((k + 1) + ' 0 obj', o) - o, 'objeto ' + (k + 1)); });
  same(0, bin.indexOf('xref', parseInt(/startxref\n(\d+)/.exec(bin)[1], 10)) - parseInt(/startxref\n(\d+)/.exec(bin)[1], 10), 'startxref');
  var pages = (bin.match(/\/Type \/Page /g) || []).length;
  same(true, pages >= 2, 'varias páginas');
  same(true, bin.indexOf('(P\xe1gina 1 de ' + pages + ')') !== -1, 'pie con el total de páginas');
  same(true, bin.indexOf('\\(con par\xe9ntesis\\)') !== -1 && bin.indexOf('\\\\ barra') !== -1, 'paréntesis y barra escapados');
  same(-1, bin.indexOf('cumple WCAG'), 'sin afirmaciones de cumplimiento');
});

check('Nombre de archivo corporativo y seguro', function () {
  var name = P.fileName('informe', 'https://www.proconsi.com/a?b=1');
  same(true, /^proconsi-radar-informe-proconsi\.com-\d{8}-\d{4}\.pdf$/.test(name), name);
});

console.log(JSON.stringify({ ok: ok, fallos: fallos }));
