// Pruebas de los componentes visuales (assets/js/rw-componentes.js). Sin red: datos inventados para la prueba.
(function () {
  var C = window.RadarComponents;
  var zona = document.getElementById('zona');

  // Estado: muestra + icono + texto; «sin datos» y desconocidos no se dibujan como éxito ni como fallo
  var s = C.status('NO_CUMPLE');
  assert.ok(s.classList.contains('rw-st--fail'));
  assert.strictEqual(s.querySelector('.rw-status__icon').textContent, '✕');
  assert.strictEqual(s.querySelector('.rw-status__text').textContent, 'Fallo');
  assert.strictEqual(s.querySelector('.rw-swatch').getAttribute('aria-hidden'), 'true');
  assert.ok(C.status('AVISO').classList.contains('rw-st--warn'));
  assert.ok(C.status('CUMPLE').classList.contains('rw-st--ok'));
  assert.ok(C.status('SIN_DATOS').classList.contains('rw-st--nodata'));
  assert.ok(C.status('NA').classList.contains('rw-st--na'));
  assert.ok(C.status('RARO').classList.contains('rw-st--nodata'), 'estado desconocido = sin datos');
  var compact = C.status('AVISO', { compacto: true, texto: 'C10: Aviso' });
  assert.strictEqual(compact.querySelector('.rw-visually-hidden').textContent, 'C10: Aviso', 'compacto: texto para lectores de pantalla');
  assert.strictEqual(compact.querySelector('.rw-status__icon'), null);

  // Escapado: el texto de la web analizada nunca se interpreta como HTML
  var evil = '<img src=x onerror=alert(1)>';
  var t = C.table({ titulo: evil, columnas: [{ texto: 'a' }, { texto: 'b', num: true }], filas: [[evil, 3]] });
  zona.appendChild(t);
  assert.strictEqual(zona.querySelectorAll('img').length, 0, 'sin elementos inyectados');
  assert.strictEqual(t.querySelector('tbody th').textContent, evil);
  assert.strictEqual(t.querySelector('tbody th').getAttribute('scope'), 'row');
  assert.ok(t.querySelector('tbody td').classList.contains('rw-num'));
  zona.textContent = '';

  // Medidor: ancho acotado a 0–100, decorativo salvo con etiqueta
  assert.strictEqual(C.meter(150).firstChild.style.width, '100%');
  assert.strictEqual(C.meter(-5).firstChild.style.width, '0%');
  assert.strictEqual(C.meter(42.5).getAttribute('aria-hidden'), 'true');
  assert.strictEqual(C.meter(42.5, { etiqueta: 'SEO 42,5 de 100' }).getAttribute('role'), 'img');

  // Barra segmentada: solo tramos con valor, máx. 5, leyenda con texto y cifra
  var seg = C.segmentedBar([
    { clave: 'fail', texto: 'Fallo', valor: 3 }, { clave: 'warn', texto: 'Aviso', valor: 0 },
    { clave: 'ok', texto: 'Sin errores', valor: 5 }, { clave: 'nodata', texto: 'Sin datos', valor: 1 },
    { clave: 'na', texto: 'No aplica', valor: 1 }, { clave: 'sev-alta', texto: 'Extra', valor: 2 }, { clave: 'ok', texto: 'Sexto', valor: 1 }
  ]);
  assert.strictEqual(seg.segmentos.length, 5, 'máximo 5 tramos');
  assert.strictEqual(seg.node.querySelectorAll('.rw-seg').length, 5);
  assert.strictEqual(seg.node.querySelector('.rw-seg').style.flexGrow, '3');
  assert.strictEqual(seg.node.querySelector('.rw-legend li').textContent.replace(/\s+/g, ' ').trim(), '✕Fallo: 3', 'icono (oculto) + texto + cifra');
  assert.ok(seg.node.querySelector('.rw-seg.rw-sev-alta'), 'claves de gravedad');

  // Barras ordenadas: de mayor a menor, máx. 5, la mayor al 100 %
  var bars = C.rankedBars([{ texto: 'a', valor: 2 }, { texto: 'b', valor: 8 }, { texto: 'c', valor: 4 }, { texto: 'd', valor: 1 }, { texto: 'e', valor: 3 }, { texto: 'f', valor: 0.5 }]);
  var labels = Array.prototype.map.call(bars.querySelectorAll('.rw-bars__label'), function (n) { return n.textContent; });
  assert.strictEqual(labels.join(''), 'bcead', 'de mayor a menor y sin el sexto');
  assert.strictEqual(bars.querySelector('.rw-bars__bar').style.width, '100%');
  assert.strictEqual(bars.querySelectorAll('.rw-bars__bar')[1].style.width, '50%');
  assert.strictEqual(bars.querySelector('.rw-bars__value').textContent, '8,0');

  // Bala: marcador en su posición y franja escrita
  var b = C.bullet({ nombre: 'LCP', valor: 3000, valorTexto: '3,0 s', bueno: 2500, deficiente: 4000, maximo: 6000, franja: 'mejorable' });
  assert.strictEqual(b.querySelector('.rw-bullet__marker').style.left, '50%');
  assert.strictEqual(Math.round(parseFloat(b.querySelector('.rw-bullet__band--good').style.width) * 10), 417, 'franja «bueno» hasta 2500 de 6000');
  assert.strictEqual(b.querySelector('.rw-bullet__value').textContent, '3,0 s · mejorable');

  // Figura: conclusión, visual oculto a lectores de pantalla, resumen y tabla equivalente
  var f = C.figure({ titulo: '7 de 25 puntos no cumplen', resumen: 'Resumen', visual: seg.node, tabla: { columnas: [{ texto: 'Estado' }, { texto: 'Puntos', num: true }], filas: [['Fallo', 7]] } });
  assert.strictEqual(f.tagName, 'FIGURE');
  assert.strictEqual(f.querySelector('figcaption').textContent, '7 de 25 puntos no cumplen');
  assert.strictEqual(f.getAttribute('aria-labelledby'), f.querySelector('figcaption').id);
  assert.strictEqual(f.querySelector('.rw-figure__visual').getAttribute('aria-hidden'), 'true');
  assert.ok(f.querySelector('details table caption'), 'tabla equivalente con título');

  // Sin datos suficientes: motivo escrito, no un gráfico vacío
  assert.strictEqual(C.empty('Core Web Vitals', 'PageSpeed no tiene datos de esta URL.').textContent.indexOf('Sin datos suficientes: PageSpeed'), 'Core Web Vitals'.length);

  // Matriz: cabeceras con scope, celdas con texto oculto, sin datos por defecto
  var m = C.matrix({ titulo: 'Matriz', columnas: [{ id: 'C10', texto: 'Title' }, { id: 'C12', texto: 'Canonical' }],
    filas: [{ texto: '/a', enlace: 'https://e.com/a', celdas: { C10: 'NO_CUMPLE' } }] });
  assert.strictEqual(m.querySelectorAll('tbody td').length, 2);
  assert.ok(m.querySelectorAll('tbody td')[1].querySelector('.rw-st--nodata'), 'celda sin dato = sin datos');
  assert.strictEqual(m.querySelector('tbody td .rw-visually-hidden').textContent, 'C10: Fallo');
  assert.strictEqual(m.querySelector('tbody th').getAttribute('scope'), 'row');
  assert.strictEqual(m.querySelector('.rw-matrix-wrap').getAttribute('tabindex'), '0', 'región desplazable con teclado');

  // Diferencia y minigráfico
  assert.strictEqual(C.delta(70, null), null);
  assert.ok(C.delta(74, 70).classList.contains('rw-delta--up'));
  assert.strictEqual(C.delta(74, 70).textContent, '▲ +4,0 (sube) respecto al análisis anterior');
  assert.ok(C.delta(68, 70).classList.contains('rw-delta--down'));
  assert.strictEqual(C.sparkline([50, 60]), null, 'menos de 3 análisis: sin minigráfico');
  var sp = C.sparkline([50, 60, 72.5]);
  assert.strictEqual(sp.namespaceURI, 'http://www.w3.org/2000/svg');
  assert.strictEqual(sp.querySelector('text').textContent, '72,5');
  assert.strictEqual(sp.getAttribute('role'), 'img');
})();
