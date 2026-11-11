// Pruebas de perfiles de lectura, resumen ejecutivo, pantalla y PDF (casos 1–9 de tests/perfiles_resumen.php).
// Se ejecuta en un navegador sin interfaz con window.I18N (lo escribe la prueba de PHP) y sin red.
(function () {
  'use strict';
  var I = window.I18N;
  var RP = window.RadarPerfil;
  var res = { ok: 0, fallos: [], casos: {} };
  function check(caso, name, fn) {
    try { fn(); res.ok++; res.casos[caso] = (res.casos[caso] || 0) + 1; } catch (e) { res.fallos.push(caso + ' · ' + name + ': ' + e.message); }
  }
  function same(a, b, msg) { if (JSON.stringify(a) !== JSON.stringify(b)) throw new Error((msg || '') + ' esperado ' + JSON.stringify(a) + ', obtenido ' + JSON.stringify(b)); }
  function ok(v, msg) { if (!v) throw new Error(msg || 'falso'); }

  var PERFILES = ['marketing', 'direccion', 'desarrollo'];
  var CATALOG = Object.keys(I.nombres_checks);

  /** Datos con la forma de api/analyze.php. checks: [{id, estado, severidad, impacto, esfuerzo, detalle, nombre}] */
  function makeData(checks, opts) {
    opts = opts || {};
    var notas = opts.notas || { seo: 80, ia: 80, accesibilidad: 80 };
    var cov = {};
    ['seo', 'ia', 'accesibilidad'].forEach(function (a) {
      cov[a] = { evaluados: opts.evaluados && opts.evaluados[a] !== undefined ? opts.evaluados[a] : 5 };
    });
    var areas = {};
    ['seo', 'ia', 'accesibilidad'].forEach(function (a) { areas[a] = { redondeada: notas[a], incompleta: false }; });
    return {
      url: opts.url || 'https://ejemplo.test/',
      fecha: '2026-11-11T10:00:00Z',
      blocked: null,
      checks: checks.map(function (c) {
        return { id: c.id, nombre: c.nombre || I.nombres_checks[c.id] || c.id, estado: c.estado, severidad: c.severidad || 'MEDIO', impacto: c.impacto || 'MEDIO',
          esfuerzo: c.esfuerzo || 'MEDIO', detalle: c.detalle || '', valor_medido: c.valor || null, umbral: null, recomendacion: c.recomendacion || '', aplica: c.estado !== 'NA' };
      }),
      puntuacion: { areas: areas, riesgo_alto: false, riesgo_motivos: [] },
      informe: { cobertura: cov },
      historial: opts.anterior ? { activo: true, anteriores: [opts.anterior] } : { activo: false, anteriores: [] }
    };
  }
  function model(data, opts) { return RP.model(data, Object.assign({ i18n: I }, opts || {})); }
  function allChecks(estado, sev) {
    return CATALOG.map(function (id) { return { id: id, estado: estado, severidad: sev || 'MEDIO' }; });
  }

  // ---------------------------------------------------------------- 1. mismas notas y estados en los tres perfiles
  check(1, 'notas, estados y grupos idénticos en los tres perfiles', function () {
    var cs = CATALOG.map(function (id, i) { return { id: id, estado: ['NO_CUMPLE', 'AVISO', 'CUMPLE', 'SIN_DATOS', 'NA'][i % 5], severidad: ['CRITICO', 'ALTO', 'MEDIO', 'BAJO'][i % 4] }; });
    var m = model(makeData(cs, { notas: { seo: 71.3, ia: 88, accesibilidad: 42.5 } }));
    var ref = null;
    PERFILES.forEach(function (p) {
      var s = RP.summary(m, p, I);
      var snap = {
        notas: s.notas.map(function (n) { return [n.area, n.nota, n.rango && n.rango.clave]; }),
        bloques: RP.BLOQUES.map(function (b) {
          var g = RP.groupBlock(m, b, p, I);
          return ['fallos', 'avisos', 'cumple', 'sinDatos', 'noAplica'].map(function (k) { return g[k].map(function (x) { return x.id + ':' + x.estado; }).sort(); });
        }),
        cobertura: [s.cobertura.evaluados, s.cobertura.sin_datos, s.cobertura.no_aplica]
      };
      if (ref) same(ref, snap, 'perfil ' + p); else ref = snap;
    });
  });
  check(1, 'la pantalla pinta los mismos estados con cualquier perfil', function () {
    var cs = CATALOG.map(function (id, i) { return { id: id, estado: ['NO_CUMPLE', 'AVISO', 'CUMPLE'][i % 3], severidad: ['CRITICO', 'ALTO', 'MEDIO', 'BAJO'][i % 4] }; });
    var host = document.getElementById('zona');
    window.RadarDashboard.init(I);
    window.RadarDashboard.render({ data: makeData(cs), psiMerged: true }, host);
    var ref = null;
    PERFILES.forEach(function (p) {
      document.querySelector('#zona input[value="' + p + '"]').click();
      var states = Array.prototype.map.call(host.querySelectorAll('.rw-point'), function (li) { return li.id + ':' + li.className; }).sort().join('|');
      var cards = Array.prototype.map.call(host.querySelectorAll('.rw-scorecard__value'), function (x) { return x.textContent; }).join('|');
      if (ref) same(ref, [states, cards], 'perfil ' + p); else ref = [states, cards];
    });
    ok(ref[0].length > 0, 'hay puntos pintados');
  });
  check(1, 'ningún fallo crítico se oculta: aparece en la pantalla y en el PDF con todos los perfiles', function () {
    var cs = CATALOG.map(function (id) { return { id: id, estado: 'NO_CUMPLE', severidad: 'CRITICO' }; });
    var m = model(makeData(cs));
    PERFILES.forEach(function (p) {
      var doc = window.RadarPdf.build({ titulo: m.url }, function (d) { window.RadarInformesPdf.report(d, m, p, I); }, {});
      CATALOG.forEach(function (id) { ok(doc.anchors['punto-' + id], p + ': ' + id + ' en el PDF'); });
    });
  });

  // ---------------------------------------------------------------- 2. perfil no válido
  check(2, 'perfil no válido cae al de por defecto sin reflejarse', function () {
    ['<script>alert(1)</script>', '', 'admin', null, undefined, {}, ['marketing'], 'MARKETING', ' marketing'].forEach(function (v) {
      same('desarrollo', RP.normalize(v, I.perfiles), JSON.stringify(v));
    });
    PERFILES.forEach(function (p) { same(p, RP.normalize(p, I.perfiles)); });
    var s = RP.summary(model(makeData(allChecks('CUMPLE'))), '<script>', I);
    same('desarrollo', s.perfil);
    ok(JSON.stringify(s).indexOf('<script>') === -1, 'el valor recibido no aparece en el resumen');
  });

  // ---------------------------------------------------------------- 3. casos límite del resumen
  check(3, 'sin fallos', function () {
    var s = RP.summary(model(makeData(allChecks('CUMPLE', 'ALTO'))), 'desarrollo', I);
    same([], s.prioridades);
    same('No hay fallos detectables automáticamente.', s.notaPrioridades);
    ok(s.bien.length >= 2 && s.bien.length <= 3, 'entre 2 y 3 cosas bien');
  });
  check(3, 'sin fallos críticos ni altos', function () {
    var s = RP.summary(model(makeData([{ id: 'C01', estado: 'NO_CUMPLE', severidad: 'MEDIO' }, { id: 'C02', estado: 'CUMPLE', severidad: 'CRITICO' }])), 'marketing', I);
    same(1, s.prioridades.length);
    ok(/No hay fallos críticos ni altos/.test(s.notaPrioridades), s.notaPrioridades);
  });
  check(3, 'todo sin datos', function () {
    var m = model(makeData(allChecks('SIN_DATOS'), { evaluados: { seo: 0, ia: 0, accesibilidad: 0 } }));
    var s = RP.summary(m, 'direccion', I);
    same([null, null, null], s.notas.map(function (n) { return n.nota; }));
    same('No hay datos suficientes para calcular ninguna nota.', s.titular);
    ok(s.cobertura.aviso && /faltan datos/.test(s.cobertura.aviso), 'aviso de cobertura');
    same(0, s.cobertura.evaluados);
  });
  check(3, 'un solo bloque con nota', function () {
    var m = model(makeData([{ id: 'C01', estado: 'CUMPLE', severidad: 'CRITICO' }], { notas: { seo: 92, ia: 100, accesibilidad: 100 }, evaluados: { seo: 1, ia: 0, accesibilidad: 0 } }));
    var s = RP.summary(m, 'desarrollo', I);
    same('Solo se ha podido calcular SEO: 92 (muy bueno).', s.titular.replace(I.areas.seo, 'SEO'));
  });
  check(3, 'fallo de la API (PageSpeed) declarado', function () {
    var sinPsi = allChecks('CUMPLE').map(function (c) { return I.psi_checks[c.id] ? { id: c.id, estado: 'SIN_DATOS', severidad: c.severidad } : c; });
    var s = RP.summary(model(makeData(sinPsi), { psiFallo: 'tiempo de espera agotado' }), 'desarrollo', I);
    ok(s.fallosBloque.length === 1 && /PageSpeed Insights no ha respondido \(tiempo de espera agotado\)/.test(s.fallosBloque[0]), s.fallosBloque[0]);
    ok(s.cobertura.sin_datos === 2 && s.notas.every(function (n) { return n.nota !== null; }), 'C08 y C14 sin datos; las notas se calculan igual');
    var p = RP.summary(model(makeData(sinPsi), { psiPendiente: true }), 'desarrollo', I);
    ok(/aún no ha terminado/.test(p.fallosBloque[0]) && p.cobertura.pendientes === 2, 'pendiente de PageSpeed');
  });
  check(3, 'concordancia en singular', function () {
    var cs = [{ id: 'C01', estado: 'NO_CUMPLE', severidad: 'CRITICO' }, { id: 'C02', estado: 'NO_CUMPLE', severidad: 'CRITICO' },
      { id: 'C03', estado: 'NO_CUMPLE', severidad: 'CRITICO' }, { id: 'C04', estado: 'NO_CUMPLE', severidad: 'CRITICO' },
      { id: 'C05', estado: 'AVISO', severidad: 'MEDIO' }, { id: 'C06', estado: 'NA' }];
    var s = RP.summary(model(makeData(cs)), 'desarrollo', I);
    same('Hay 1 fallo crítico más: se detalla en su bloque.', s.notaPrioridades);
    same('4 críticos, 0 altos, 0 medios y 0 bajos; 1 aviso.', s.extra.texto);
    ok(/ y 1 que no aplica\.$/.test(s.cobertura.texto), s.cobertura.texto);
    var one = RP.summary(model(makeData([{ id: 'C01', estado: 'CUMPLE' }])), 'desarrollo', I);
    ok(/^1 punto evaluado de 1,/.test(one.cobertura.texto), one.cobertura.texto);
  });

  // ---------------------------------------------------------------- 4. titular con empates
  check(4, 'titular con empates', function () {
    var h = function (n) { return RP.summary(model(makeData(allChecks('CUMPLE'), { notas: n })), 'desarrollo', I).titular; };
    var A = I.areas;
    same('Las tres notas están igualadas en 80 (bueno).', h({ seo: 80, ia: 80, accesibilidad: 80 }));
    same(A.seo + ' y ' + A.ia + ' son lo más sólido (90) y ' + A.accesibilidad + ' lo que más atención necesita (60, mejorable).', h({ seo: 90, ia: 90, accesibilidad: 60 }));
    same(A.seo + ' es lo más sólido (95) y ' + A.ia + ' y ' + A.accesibilidad + ' lo que más atención necesitan (40, crítico).', h({ seo: 95, ia: 40, accesibilidad: 40 }));
    same(A.ia + ' es lo más sólido (77,5) y ' + A.seo + ' lo que más atención necesita (50, mejorable).', h({ seo: 50, ia: 77.5, accesibilidad: 70 }));
    same('Las tres notas están igualadas en 75 (bueno).', h({ seo: 75.04, ia: 74.96, accesibilidad: 75 }), 'empate tras redondear a un decimal');
  });

  // ---------------------------------------------------------------- 5. desempate de prioridades y orden estable
  check(5, 'a igual peso, decide la relevancia del perfil', function () {
    var rel = I.perfiles.relevancia;
    var found = 0;
    PERFILES.forEach(function (p) {
      var alta = CATALOG.filter(function (id) { return rel[id] && rel[id][p] === 'alta'; });
      var baja = CATALOG.filter(function (id) { return rel[id] && rel[id][p] === 'baja'; });
      if (!alta.length || !baja.length) return;
      var a = alta[alta.length - 1], b = baja[0];   // el de relevancia alta tiene un ID mayor: el ID no decide
      var pts = model(makeData([{ id: b, estado: 'NO_CUMPLE', severidad: 'ALTO' }, { id: a, estado: 'NO_CUMPLE', severidad: 'ALTO' }])).puntos;
      same([a, b], RP.sortPoints(pts, p, I).map(function (x) { return x.id; }), p);
      found++;
    });
    ok(found >= 2, 'hay relevancias distintas en al menos dos perfiles');
  });
  check(5, 'el peso manda sobre la relevancia y el orden no depende de la llegada', function () {
    var cs = [{ id: 'C10', estado: 'NO_CUMPLE', severidad: 'BAJO' }, { id: 'C02', estado: 'NO_CUMPLE', severidad: 'CRITICO' },
      { id: 'C05', estado: 'NO_CUMPLE', severidad: 'MEDIO' }, { id: 'C04', estado: 'NO_CUMPLE', severidad: 'CRITICO' }, { id: 'C07', estado: 'NO_CUMPLE', severidad: 'ALTO' }];
    PERFILES.forEach(function (p) {
      var o1 = RP.summary(model(makeData(cs)), p, I).prioridades.map(function (x) { return x.severidad; });
      same(['CRITICO', 'CRITICO', 'ALTO'], o1, p);
      var ids1 = RP.sortPoints(model(makeData(cs)).puntos, p, I).map(function (x) { return x.id; });
      var ids2 = RP.sortPoints(model(makeData(cs.slice().reverse())).puntos, p, I).map(function (x) { return x.id; });
      same(ids1, ids2, 'orden estable ' + p);
    });
  });

  // ---------------------------------------------------------------- 6. variación con el historial
  check(6, 'variación positiva, negativa, nula y sin anterior', function () {
    var A = I.areas;
    var d = makeData(allChecks('CUMPLE'), { notas: { seo: 86, ia: 70, accesibilidad: 57 }, anterior: { fecha: '2026-11-01', nota_seo: 80, nota_ia: 70, nota_accesibilidad: 60 } });
    var s = RP.summary(model(d), 'direccion', I);
    same('Frente al análisis anterior: ' + A.seo + ' +6, ' + A.ia + ' sin cambios y ' + A.accesibilidad + ' −3.', s.variacion);
    same(['+6', 'sin cambios', '−3'], s.notas.map(function (n) { return n.variacion; }));
    var none = RP.summary(model(makeData(allChecks('CUMPLE'))), 'direccion', I);
    same(null, none.variacion);
    same([null, null, null], none.notas.map(function (n) { return n.variacion; }));
    same('+0,5', RP.deltaText(80.5, 80));
  });
  check(6, 'el gráfico de evolución solo aparece con 2 puntos o más', function () {
    var host = document.getElementById('zona2');
    window.RadarDashboard.render({ data: makeData(allChecks('CUMPLE')), psiMerged: true }, host);
    ok(!host.querySelector('#evolucion'), 'sin historial: sin gráfico');
    window.RadarDashboard.render({ data: makeData(allChecks('CUMPLE'), { anterior: { fecha: '2026-11-01', nota_seo: 70, nota_ia: 70, nota_accesibilidad: 70 } }), psiMerged: true }, host);
    var evo = host.querySelector('#evolucion');
    ok(evo && evo.querySelector('svg') && evo.querySelector('figcaption') && evo.querySelector('table'), 'gráfico con figcaption y tabla');
  });

  // ---------------------------------------------------------------- 7. cobertura de textos
  check(7, 'todos los puntos tienen texto en los tres perfiles', function () {
    CATALOG.concat(['WEB-TITULOS', 'WEB-404']).forEach(function (id) {
      PERFILES.forEach(function (p) {
        var t = RP.pointText({ id: id, recomendacion: '' }, p, I);
        ok(!t.pendiente, id + ' · ' + p + ' sin texto propio');
        ok(t.significa && t.importa && t.hacer, id + ' · ' + p + ' con significa, importa y hacer');
        if (p === 'desarrollo') ok(t.comprobar, id + ' · cómo comprobarlo');
      });
    });
  });

  // ---------------------------------------------------------------- 8. texto hostil
  var HOSTIL = '<img src=x onerror=alert(1)>) \\ «»"\'&';
  check(8, 'texto hostil de la web analizada: en pantalla solo como texto', function () {
    var cs = [{ id: 'C01', estado: 'NO_CUMPLE', severidad: 'CRITICO', nombre: 'Nombre ' + HOSTIL, detalle: 'Detalle ' + HOSTIL, valor: HOSTIL }];
    var host = document.getElementById('zona3');
    window.RadarDashboard.render({ data: makeData(cs, { url: 'https://e.test/?q=<script>x</script>' }), psiMerged: true }, host);
    ok(!host.querySelector('img') && !host.querySelector('script'), 'no se crea ningún elemento');
    ok(host.textContent.indexOf('Detalle ' + HOSTIL) !== -1, 'el texto se muestra tal cual');
    ok(host.textContent.indexOf('Nombre ' + HOSTIL) !== -1, 'el nombre se muestra tal cual');
  });
  check(8, 'texto hostil en el PDF: escapado en las cadenas del PDF', function () {
    var cs = [{ id: 'C01', estado: 'NO_CUMPLE', severidad: 'CRITICO', nombre: 'Nombre ' + HOSTIL, detalle: 'Detalle ' + HOSTIL }];
    var m = model(makeData(cs));
    var doc = window.RadarPdf.build({ titulo: m.url }, function (d) { window.RadarInformesPdf.report(d, m, 'desarrollo', I); }, {});
    var bin = doc.bytes();
    ok(bin.indexOf('onerror=alert\\(1\\)>\\)') !== -1, 'paréntesis escapados');
    ok(bin.indexOf('\\\\') !== -1, 'barra invertida escapada');
    // Cada orden de texto es una sola cadena PDF bien cerrada: ningún dato abre o cierra otra
    var bad = [];
    doc.pages.forEach(function (ops) {
      ops.forEach(function (op) {
        if (op.indexOf(' Tj ET') === -1) return;
        if (!/^BT \/F[12] [\d.]+ Tf [\d. ]+ rg [\d.\-]+ [\d.\-]+ Td \((?:[^\\()]|\\[\\()])*\) Tj ET$/.test(op)) bad.push(op.slice(0, 120));
      });
    });
    same([], bad, 'órdenes de texto mal formadas');
  });

  // ---------------------------------------------------------------- 9. índice con números de página
  function tocCheck(doc) {
    same(2, doc.passes, 'dos pasadas');
    var bin = doc.bytes();
    doc.tocEntries.forEach(function (e) {
      var sec = doc.sections.filter(function (s) { return s.key === e.key; })[0];
      same(sec.page + 1, e.pagina, e.titulo + ': página del índice = página real');
      ok(doc.pages[0].join('\n').indexOf('(' + e.pagina + ') Tj') !== -1, e.titulo + ': número escrito en la portada');
    });
    var pages = (bin.match(/\/Type \/Page /g) || []).length;
    same(doc.pages.length, pages);
    ok(bin.indexOf('/Lang (es)') !== -1 && bin.indexOf('/Outlines') !== -1 && bin.indexOf('/PageMode /UseOutlines') !== -1, 'idioma y marcadores');
    ok((bin.match(/\/Subtype \/Link/g) || []).length >= doc.tocEntries.length, 'enlaces del índice');
    return pages;
  }
  check(9, 'PDF corto (3 páginas): índice correcto', function () {
    var doc = window.RadarPdf.build({ titulo: 'https://e.test/', eyebrow: 'Prueba' }, function (d) {
      d.section('a', 'Primera'); d.p('Texto breve.');
      d.section('b', 'Segunda', { nuevaPagina: true }); d.p('Texto breve.');
      d.section('c', 'Tercera'); d.p('Texto breve.');
    }, {});
    same(3, tocCheck(doc), 'tres páginas');
    same([2, 3, 3], doc.tocEntries.map(function (e) { return e.pagina; }));
  });
  check(9, 'PDF largo (más de 10 páginas): índice correcto en informe y hoja de ruta', function () {
    var cs = CATALOG.map(function (id, i) { return { id: id, estado: i % 3 ? 'NO_CUMPLE' : 'AVISO', severidad: ['CRITICO', 'ALTO', 'MEDIO', 'BAJO'][i % 4], impacto: ['ALTO', 'MEDIO'][i % 2], esfuerzo: ['BAJO', 'MEDIO', 'ALTO'][i % 3] }; });
    var m = model(makeData(cs));
    PERFILES.forEach(function (p) {
      var s = RP.summary(m, p, I);
      var rep = window.RadarPdf.build(window.RadarInformesPdf.options('informe', m, s), function (d) { window.RadarInformesPdf.report(d, m, p, I); }, {});
      var np = tocCheck(rep);
      ok(p !== 'desarrollo' || np > 10, 'informe de desarrollo con más de 10 páginas: ' + np);
      same(1, rep.resumenPaginas, 'resumen en una página (' + p + ')');
      same(p !== 'direccion', rep.tocEntries.some(function (e) { return e.key === 'glosario'; }), 'glosario según perfil');
      var road = window.RadarPdf.build(window.RadarInformesPdf.options('hoja', m, s), function (d) { window.RadarInformesPdf.roadmap(d, m, p, I); }, {});
      tocCheck(road);
      same(['resumen', 'plazos', 'tareas', 'riesgos', 'metodo'], road.tocEntries.map(function (e) { return e.key; }));
      ok(rep.pages[0].join('\n').indexOf('Lectura: ' + I.perfiles.perfiles[p].nombre) !== -1, 'portada con «Lectura: X»');
    });
  });
  check(9, 'sin nota global en el PDF', function () {
    var m = model(makeData(allChecks('NO_CUMPLE', 'ALTO')));
    var bin = window.RadarPdf.build({ titulo: m.url }, function (d) { window.RadarInformesPdf.report(d, m, 'direccion', I); }, {}).bytes();
    // La única mención es la nota metodológica que explica que no se muestra
    ok(bin.indexOf('No se muestra una nota global') !== -1, 'nota metodológica');
    ok(!/nota global/i.test(bin.split('No se muestra una nota global').join('')), 'no aparece ninguna nota global');
    ok(!/cumple (la )?WCAG|cumple la ley/i.test(bin), 'no afirma cumplimiento');
  });

  document.getElementById('resultado').textContent = JSON.stringify(res);
})();
