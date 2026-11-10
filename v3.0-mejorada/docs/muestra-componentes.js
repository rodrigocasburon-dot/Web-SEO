// docs/muestra-componentes.html: todos los componentes con DATOS DE EJEMPLO (inventados, no son de ninguna web).
(function () {
  'use strict';
  var C = window.RadarComponents;
  var el = C.el;
  var host = document.getElementById('muestra');

  function section(title, nodes) {
    host.appendChild(el('section', {}, [el('h2', { text: title })].concat(nodes)));
  }
  function card(node) { return el('div', { class: 'rw-card' }, [node]); }
  var EJ = ' (DATOS DE EJEMPLO)';

  // Estados
  section('Estados', [
    el('div', { class: 'rw-sample__row' }, ['CUMPLE', 'AVISO', 'NO_CUMPLE', 'SIN_DATOS', 'NA'].map(function (s) { return C.status(s); })),
    el('p', { class: 'rw-help', text: 'Versión compacta (celdas de la matriz):' }),
    el('div', { class: 'rw-sample__row' }, ['CUMPLE', 'AVISO', 'NO_CUMPLE', 'SIN_DATOS', 'NA'].map(function (s) { return C.status(s, { compacto: true }); })),
    el('p', { class: 'rw-help', text: 'Gravedad:' }),
    el('div', { class: 'rw-sample__row' }, ['BAJO', 'MEDIO', 'ALTO', 'CRITICO'].map(function (s) { return C.severityChip(s); }))
  ]);

  // Notas
  var notas = [['SEO', 72.4, 'Evaluados 15 de 16 puntos', [61, 66.5, 70, 72.4]], ['Preparación para IA', 81, 'Evaluados 9 de 9 puntos', [85, 81]], ['Accesibilidad', 47, 'Evaluados 9 de 10 puntos', [52, 49, 47]]];
  section('Las tres notas' + EJ, [
    el('ul', { class: 'rw-scores' }, notas.map(function (n) {
      var prev = n[3].length > 1 ? n[3][n[3].length - 2] : null;
      return el('li', { class: 'rw-card rw-score' }, [
        el('p', { class: 'rw-score__label', text: n[0] }),
        el('p', { class: 'rw-score__value' }, [C.num(n[1]), el('span', { class: 'rw-score__max', text: ' / 100' })]),
        C.meter(n[1]),
        el('p', { class: 'rw-score__coverage', text: n[2] }),
        el('div', { class: 'rw-score__extra' }, [C.delta(n[1], prev), C.sparkline(n[3], { etiqueta: 'Evolución de ' + n[0] + ': ' + n[3].join(', ') })]),
        n[0] === 'Preparación para IA' ? el('p', { class: 'rw-score__sub', text: 'Preparación técnica: no mide si los motores de IA citan la web.' }) : null
      ]);
    })),
    el('p', { class: 'rw-global' }, ['Nota global: ', el('strong', { text: '65,6 / 100' }), ' · media ponderada (35 % SEO, 30 % Preparación para IA, 35 % Accesibilidad).'])
  ]);

  // Barra de estados y gravedad
  var estados = C.segmentedBar([
    { clave: 'fail', texto: 'Fallo', valor: 3 }, { clave: 'warn', texto: 'Aviso', valor: 2 },
    { clave: 'ok', texto: 'Sin errores detectables automáticamente', valor: 8 }, { clave: 'nodata', texto: 'Sin datos', valor: 1 }, { clave: 'na', texto: 'No aplica', valor: 1 }
  ]);
  var gravedad = C.segmentedBar([
    { clave: 'sev-critica', texto: 'Crítica', valor: 1 }, { clave: 'sev-alta', texto: 'Alta', valor: 1 }, { clave: 'sev-media', texto: 'Media', valor: 1 }
  ], { baja: true });
  section('Barras segmentadas' + EJ, [el('div', { class: 'rw-sample__grid' }, [
    card(C.figure({ clave: 'm-estados', titulo: '3 de los 15 puntos de este bloque tienen fallo', resumen: 'Fallo 3; aviso 2; sin errores 8; sin datos 1; no aplica 1.', resumenOculto: true, visual: estados.node,
      tabla: { columnas: [{ texto: 'Estado' }, { texto: 'Puntos', num: true }], filas: [['Fallo', 3], ['Aviso', 2], ['Sin errores detectables automáticamente', 8], ['Sin datos', 1], ['No aplica', 1]] } })),
    card(C.figure({ clave: 'm-grav', titulo: '3 fallos de SEO: 1 de gravedad crítica, 1 de gravedad alta y 1 de gravedad media', resumen: 'Fallos según su gravedad.', resumenOculto: true, visual: gravedad.node,
      tabla: { columnas: [{ texto: 'Gravedad' }, { texto: 'Fallos', num: true }], filas: [['Crítica', 1], ['Alta', 1], ['Media', 1]] } }))
  ])]);

  // Qué arreglar primero y Core Web Vitals
  var fixes = [{ texto: 'Nombres accesibles (C04)', valor: 25.4 }, { texto: 'Etiqueta title (C10)', valor: 8 }, { texto: 'Texto alternativo en imágenes (C06)', valor: 8 }, { texto: 'Encabezados (C05)', valor: 4 }, { texto: 'Meta description (C15)', valor: 1 }];
  var cwv = [
    { nombre: 'LCP', valor: 3100, valorTexto: '3,1 s', bueno: 2500, deficiente: 4000, maximo: 6000, franja: 'mejorable' },
    { nombre: 'INP', valor: 160, valorTexto: '160 ms', bueno: 200, deficiente: 500, maximo: 800, franja: 'bueno' },
    { nombre: 'CLS', valor: 0.31, valorTexto: '0,31', bueno: 0.1, deficiente: 0.25, maximo: 0.4, franja: 'deficiente' }
  ];
  section('Barras ordenadas y gráfico de bala' + EJ, [el('div', { class: 'rw-sample__grid' }, [
    card(C.figure({ clave: 'm-fix', titulo: 'Qué arreglar primero: «Nombres accesibles» es el fallo que más resta (25,4 puntos)',
      resumen: 'Puntos que resta cada fallo, sumados en las tres notas según la fórmula de puntuación.',
      visual: C.rankedBars(fixes.map(function (f) { return { texto: f.texto, valor: f.valor, valorTexto: C.num(f.valor) + ' pts' }; })),
      tabla: { columnas: [{ texto: 'Fallo' }, { texto: 'Puntos que resta', num: true }], filas: fixes.map(function (f) { return [f.texto, C.num(f.valor)]; }) } })),
    card(C.figure({ clave: 'm-cwv', titulo: 'Core Web Vitals de usuarios reales: deficiente (LCP y CLS fuera de «bueno»)',
      resumen: 'Percentil 75 de usuarios reales. Franjas de Google, de claro a oscuro: bueno, mejorable y deficiente; la marca negra es el valor.',
      visual: el('div', { class: 'rw-bullets' }, cwv.map(C.bullet)),
      tabla: { columnas: [{ texto: 'Métrica' }, { texto: 'Valor (p75)', num: true }, { texto: 'Franja' }], filas: cwv.map(function (m) { return [m.nombre, m.valorTexto, m.franja]; }) } }))
  ])]);

  // Sin datos suficientes
  section('Sin datos suficientes' + EJ, [el('div', { class: 'rw-sample__grid' }, [
    card(C.empty('Core Web Vitals (usuarios reales)', 'no hay datos de usuarios reales (Chrome UX Report) para esta URL.'))
  ])]);

  // Matriz
  var cols = [{ id: 'C01', texto: 'Estado HTTP y HTTPS' }, { id: 'C09', texto: 'Zoom y móvil' }, { id: 'C10', texto: 'Etiqueta title' }, { id: 'C12', texto: 'URL canónica' }, { id: 'C13', texto: 'Datos estructurados' }, { id: 'C15', texto: 'Meta description' }];
  var pattern = [
    ['/', ['CUMPLE', 'CUMPLE', 'AVISO', 'CUMPLE', 'CUMPLE', 'NO_CUMPLE']],
    ['/servicios/', ['CUMPLE', 'CUMPLE', 'NO_CUMPLE', 'AVISO', 'AVISO', 'NO_CUMPLE']],
    ['/contacto/', ['CUMPLE', 'CUMPLE', 'CUMPLE', 'CUMPLE', 'AVISO', 'NO_CUMPLE']],
    ['/blog/', ['CUMPLE', 'CUMPLE', 'AVISO', 'SIN_DATOS', 'AVISO', 'NO_CUMPLE']],
    ['/blog/articulo-de-ejemplo-con-una-ruta-larga/', ['CUMPLE', 'CUMPLE', 'NO_CUMPLE', 'CUMPLE', 'CUMPLE', 'NO_CUMPLE']],
    ['/aviso-legal/', ['CUMPLE', 'NA', 'CUMPLE', 'CUMPLE', 'AVISO', 'AVISO']]
  ];
  var figMatrix = el('figure', { class: 'rw-figure', 'aria-labelledby': 'cap-m-matriz' }, [
    el('figcaption', { id: 'cap-m-matriz', class: 'rw-figure__title', text: 'C15 (Meta description) falla en 5 de 6 páginas: probablemente está en la plantilla' }),
    el('p', { class: 'rw-figure__summary', text: 'Estado de cada punto (columnas) en cada página (filas), con las páginas que más fallan arriba.' }),
    C.matrix({ titulo: 'Puntos de SEO por página', columnas: cols, filas: pattern.map(function (p) {
      var cells = {};
      cols.forEach(function (c, i) { cells[c.id] = p[1][i]; });
      return { texto: p[0], enlace: null, celdas: cells };
    }) })
  ]);
  section('Matriz páginas × puntos' + EJ, [card(figMatrix)]);

  // Tabla y botones
  section('Tabla de datos y botones' + EJ, [
    C.table({ titulo: 'Tabla equivalente de ejemplo', columnas: [{ texto: 'Punto' }, { texto: 'Estado' }, { texto: 'Puntos', num: true }], filas: [['C10 · Etiqueta title', C.status('NO_CUMPLE'), '6,4'], ['C12 · URL canónica', C.status('AVISO'), '0,0']] }),
    el('p', { class: 'rw-sample__row' }, [
      el('button', { type: 'button', class: 'rw-btn', text: 'Descargar JSON' }),
      el('button', { type: 'button', class: 'rw-btn', text: 'Descargar CSV' }),
      el('button', { type: 'button', class: 'rw-btn', text: 'Hoja de ruta (PDF)' })
    ])
  ]);
})();
