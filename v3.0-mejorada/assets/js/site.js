/*
 * v3.0 · Modo «sitio completo».
 * 1. api/site.php analiza la portada y devuelve una muestra de páginas firmadas.
 * 2. Este script analiza esas páginas EN PARALELO con api/site_page.php (de N en N).
 * 3. Se agregan los resultados: nota media, fallos que se repiten y páginas peor puntuadas.
 * Todo se pinta con textContent: nunca se interpreta HTML de la web analizada.
 */
(function (root) {
  'use strict';

  var AREAS = ['seo', 'ia', 'accesibilidad'];
  var SEV_ORDER = { CRITICO: 0, ALTO: 1, MEDIO: 2, BAJO: 3 };

  /**
   * Agrega los resultados de las páginas (función pura; la prueban tests/js/site_test.js).
   * @param {Array} pages [{ok, url, blocked, ms, checks:[{id,nombre,estado,severidad,aplica,...}], notas:{seo,ia,accesibilidad,global}}]
   * @param {Object} [opts] {previstas: nº de páginas que se pensaba analizar (portada incluida),
   *                         maxPaginas: tope de páginas que se listan en cada recomendación agrupada (20)}
   */
  function aggregate(pages, opts) {
    opts = opts || {};
    var cap = Math.max(1, parseInt(opts.maxPaginas, 10) || 20);
    // Recomendaciones idénticas de varias páginas, agrupadas en una sola (clave: texto de la recomendación)
    var byFix = {};
    var addFix = function (p, c) {
      if (!c.recomendacion || c.id === 'C16' || c.id === 'C18') return;   // C16 y C18 son de todo el sitio
      var g = byFix[c.recomendacion] || (byFix[c.recomendacion] = {
        recomendacion: c.recomendacion, ids: [], nombres: [], severidad: c.severidad,
        paginas_total: 0, paginas_fallan: 0, paginas_aviso: 0, paginas: [], vistas: {}
      });
      if (g.ids.indexOf(c.id) === -1) { g.ids.push(c.id); g.nombres.push(c.nombre || c.id); }
      if ((SEV_ORDER[c.severidad] === undefined ? 9 : SEV_ORDER[c.severidad]) < (SEV_ORDER[g.severidad] === undefined ? 9 : SEV_ORDER[g.severidad])) g.severidad = c.severidad;
      if (g.vistas[p.url]) return;   // una página cuenta una vez por recomendación
      g.vistas[p.url] = true;
      g.paginas_total++;
      if (c.estado === 'NO_CUMPLE') g.paginas_fallan++; else g.paginas_aviso++;
      if (g.paginas.length < cap) g.paginas.push({ url: p.url, estado: c.estado, detalle: c.detalle || '' });
    };
    // Dos URL de la muestra pueden redirigir a la misma página: cuenta una sola vez
    var seen = {};
    var key = function (u) { return String(u).toLowerCase().replace(/^https?:\/\/(www\.)?/, '').replace(/\/+$/, ''); };
    var scored = pages.filter(function (p) {
      if (!(p.ok && !p.blocked && p.notas)) return false;
      var k = key(p.url);
      if (seen[k]) return false;
      seen[k] = true;
      return true;
    });
    var mean = {};
    var min = {};
    ['seo', 'ia', 'accesibilidad', 'global'].forEach(function (a) {
      var values = scored.map(function (p) { return p.notas[a]; });
      mean[a] = values.length ? values.reduce(function (s, v) { return s + v; }, 0) / values.length : null;
      min[a] = values.length ? Math.min.apply(null, values) : null;
    });
    var byCheck = {};
    scored.forEach(function (p) {
      (p.checks || []).forEach(function (c) {
        var row = byCheck[c.id] || (byCheck[c.id] = {
          id: c.id, nombre: c.nombre, severidad: c.severidad, recomendacion: '',
          evaluadas: 0, fallan: 0, avisos: 0, sin_datos: 0, ejemplos: []
        });
        if (c.aplica === false) return;
        if (c.estado === 'SIN_DATOS') { row.sin_datos++; return; }
        row.evaluadas++;
        if (c.estado === 'NO_CUMPLE') {
          row.fallan++;
          if (!row.recomendacion && c.recomendacion) row.recomendacion = c.recomendacion;
          if (row.ejemplos.length < 5) row.ejemplos.push({ url: p.url, detalle: c.detalle || '' });
          addFix(p, c);
        } else if (c.estado === 'AVISO') {
          row.avisos++;
          addFix(p, c);
        }
      });
    });
    var checks = Object.keys(byCheck).map(function (k) { return byCheck[k]; });
    // Títulos repetidos entre páginas (WEB-08 de la v1.0)
    var byTitle = {};
    scored.forEach(function (p) {
      var t = String(p.titulo || '').trim().toLowerCase();
      if (t) (byTitle[t] = byTitle[t] || { titulo: p.titulo, urls: [] }).urls.push(p.url);
    });
    var titulosRepetidos = Object.keys(byTitle).map(function (k) { return byTitle[k]; })
      .filter(function (g) { return g.urls.length > 1; })
      .sort(function (a, b) { return b.urls.length - a.urls.length; });
    // Primero lo que más páginas falla, desempatando por gravedad
    checks.sort(function (a, b) {
      return (b.fallan / (b.evaluadas || 1)) - (a.fallan / (a.evaluadas || 1))
        || (SEV_ORDER[a.severidad] || 9) - (SEV_ORDER[b.severidad] || 9)
        || (a.id < b.id ? -1 : 1);
    });
    // Fiabilidad de la nota media (mismas reglas que Reliability::site en el servidor): no cambia ninguna nota
    var evaluated = 0;
    var possible = 0;
    scored.forEach(function (p) {
      (p.checks || []).forEach(function (c) {
        if (c.aplica === false) return;
        possible++;
        if (c.estado !== 'SIN_DATOS') evaluated++;
      });
    });
    var blockedPages = pages.filter(function (p) { return p.ok && p.blocked; }).length;
    var failedPages = pages.filter(function (p) { return !p.ok; }).length;
    var duplicates = pages.filter(function (p) { return p.ok && !p.blocked && p.notas; }).length - scored.length;
    var planned = typeof opts.previstas === 'number' ? opts.previstas : pages.length;
    var expected = Math.max(scored.length, planned - duplicates);
    var homeBlocked = !!(pages[0] && pages[0].portada && pages[0].blocked);
    var fiabilidad = {
      estado: homeBlocked ? 'bloqueado' : (scored.length < expected || possible > evaluated ? 'parcial' : 'completo'),
      evaluados: evaluated,
      posibles: possible,
      sin_datos: possible - evaluated,
      porcentaje: possible ? Math.round(1000 * evaluated / possible) / 10 : null,
      paginas: { con_nota: scored.length, previstas: expected, bloqueadas: blockedPages, errores: failedPages }
    };
    // Primero la corrección que afecta a más páginas; a igualdad, los fallos antes que los avisos, la más grave y el id
    var recomendaciones = Object.keys(byFix).map(function (k) {
      var g = byFix[k];
      delete g.vistas;
      g.paginas_mas = g.paginas_total - g.paginas.length;
      return g;
    }).sort(function (a, b) {
      return b.paginas_total - a.paginas_total
        || b.paginas_fallan - a.paginas_fallan
        || (SEV_ORDER[a.severidad] === undefined ? 9 : SEV_ORDER[a.severidad]) - (SEV_ORDER[b.severidad] === undefined ? 9 : SEV_ORDER[b.severidad])
        || (a.ids[0] < b.ids[0] ? -1 : 1);
    });
    return {
      paginas: scored,
      recomendaciones: recomendaciones,
      fiabilidad: fiabilidad,
      analizadas: scored.length,
      bloqueadas: blockedPages,
      errores: failedPages,
      media: mean,
      minima: min,
      checks: checks,
      titulos_repetidos: titulosRepetidos,
      con_titulo: Object.keys(byTitle).reduce(function (n, k) { return n + byTitle[k].urls.length; }, 0),
      peores: scored.slice().sort(function (a, b) { return a.notas.global - b.notas.global; })
    };
  }

  /** Ejecuta tareas con un máximo de N a la vez; shouldStop() corta el reparto de nuevas tareas. */
  function pool(items, limit, worker, shouldStop) {
    return new Promise(function (resolve) {
      var next = 0;
      var running = 0;
      function launch() {
        if ((next >= items.length || shouldStop()) && running === 0) { resolve(); return; }
        while (running < limit && next < items.length && !shouldStop()) {
          var item = items[next++];
          running++;
          Promise.resolve(worker(item)).catch(function () {}).then(function () { running--; launch(); });
        }
      }
      launch();
    });
  }

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { aggregate: aggregate, pool: pool };
    return;
  }

  // ------------------------------------------------------------------ interfaz
  var doc = root.document;
  var i18n = JSON.parse(doc.getElementById('i18n').textContent);
  var form = doc.getElementById('site-form');
  var input = doc.getElementById('site-url');
  var submit = doc.getElementById('site-submit');
  var fieldError = doc.getElementById('site-error');
  var statusBox = doc.getElementById('site-status');
  var progressWrap = doc.getElementById('site-progress-wrap');
  var progress = doc.getElementById('site-progress');
  var stopButton = doc.getElementById('site-stop');
  var errorBox = doc.getElementById('site-error-box');
  var results = doc.getElementById('site-results');
  var stopRequested = false;

  function el(tag, attrs, children) {
    var n = doc.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (attrs[k] === null || attrs[k] === undefined) return;
      if (k === 'text') n.textContent = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) {
      if (c === null || c === undefined) return;
      n.appendChild(typeof c === 'string' ? doc.createTextNode(c) : c);
    });
    return n;
  }
  function fmt(v) { return v === null || v === undefined ? '—' : (Math.round(v * 10) / 10).toLocaleString('es-ES'); }
  function pct(a, b) { return b ? Math.round(a / b * 100) + ' %' : '—'; }
  function path(u) { try { var x = new URL(u); return x.pathname + x.search || '/'; } catch (e) { return u; } }

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: { message: 'Respuesta no válida del servidor (' + r.status + ').' } }; });
    });
  }

  function showError(msg) {
    errorBox.textContent = msg;
    errorBox.hidden = false;
    errorBox.focus();
  }

  // Al imprimir se abren los desplegables (que no quede nada oculto en el PDF) y luego se dejan como estaban
  var openedForPrint = [];
  root.addEventListener('beforeprint', function () {
    openedForPrint = Array.prototype.filter.call(results.querySelectorAll('details:not([open])'), function (d) { d.open = true; return true; });
  });
  root.addEventListener('afterprint', function () {
    openedForPrint.forEach(function (d) { d.open = false; });
    openedForPrint = [];
  });

  // v3.0 · Ver un informe guardado en el historial (sitio.php?informe=ID)
  var savedId = parseInt(new URLSearchParams(root.location.search).get('informe') || '', 10);
  if (savedId > 0) {
    statusBox.textContent = 'Cargando el informe guardado…';
    post('api/history_report.php', { id: savedId }).then(function (r) {
      if (!r.ok || r.tipo !== 'sitio') {
        statusBox.textContent = '';
        showError((r.error && r.error.message) || 'Este informe no es de un análisis de sitio.');
        return;
      }
      var inf = r.informe;
      var home = inf.home;
      home.chromeResult = inf.chromeResult;
      home.psiResult = inf.psiResult;
      home.guardado = r.fecha;
      input.value = home.url;
      statusBox.textContent = 'Informe guardado del ' + new Date(r.fecha).toLocaleString('es-ES', { dateStyle: 'long', timeStyle: 'short' }) + '. Muestra cómo estaba el sitio ese día; para verlo hoy, pulsa «Analizar sitio».';
      render(home, inf.pages || [], inf.planned || (inf.pages || []).length, inf.seconds || 0);
    }).catch(function () {
      statusBox.textContent = '';
      showError('No se ha podido cargar el informe guardado.');
    });
  }

  stopButton.addEventListener('click', function () {
    stopRequested = true;
    stopButton.disabled = true;
    statusBox.textContent = 'Terminando: se esperan las páginas que ya estaban en marcha…';
  });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var url = input.value.trim();
    fieldError.hidden = true;
    input.removeAttribute('aria-invalid');
    if (!url) {
      fieldError.textContent = 'Escribe la dirección del sitio.';
      fieldError.hidden = false;
      input.setAttribute('aria-invalid', 'true');
      input.focus();
      return;
    }
    run(url, doc.getElementById('site-noindex').checked);
  });

  function run(url, noindex) {
    var started = Date.now();
    stopRequested = false;
    stopButton.disabled = false;
    errorBox.hidden = true;
    results.hidden = true;
    results.textContent = '';
    submit.disabled = true;
    statusBox.textContent = 'Analizando la portada y buscando páginas del sitio…';

    post('api/site.php', { url: url, noindex_intentional: noindex }).then(function (home) {
      if (!home.ok) {
        submit.disabled = false;
        statusBox.textContent = '';
        showError((home.error && home.error.message) || 'No se ha podido analizar el sitio.');
        return;
      }
      var list = home.sitio.paginas;
      var pages = [];
      var done = 0;
      progress.max = Math.max(1, list.length);
      progress.value = 0;
      progressWrap.hidden = list.length === 0;
      var lastAnnounce = 0;
      var announce = function (force) {
        // Se anuncia como mucho cada 3 s para no saturar a los lectores de pantalla
        if (!force && Date.now() - lastAnnounce < 3000) return;
        lastAnnounce = Date.now();
        statusBox.textContent = 'Portada analizada. Páginas: ' + done + ' de ' + list.length + '.';
      };
      announce(true);
      // Pruebas con Chrome en la portada, a la vez que las páginas (si el servidor las tiene activadas)
      var chrome = home.sitio.chrome
        ? post('api/browser.php', { url: home.sitio.chrome.url, firma: home.sitio.chrome.firma, expira: home.sitio.expira })
          .catch(function () { return { ok: false, error: { message: 'Error de red.' } }; })
        : Promise.resolve(null);
      // PageSpeed (contraste y Core Web Vitals) solo de la portada: tarda hasta 75 s, así que va a la vez que el resto
      var psi = post('api/psi.php', { url: home.url })
        .catch(function () { return { ok: false, error: { message: 'Error de red.' } }; });
      var launched = [];   // firmas de las páginas lanzadas (el servidor guarda sus resultados para el historial)
      var pagesDone = pool(list, home.sitio.paralelas || 6, function (item) {
        launched.push(item.firma);
        var t0 = Date.now();
        return post('api/site_page.php', { url: item.url, firma: item.firma, expira: home.sitio.expira, noindex_intentional: noindex })
          .then(function (r) { r.url = r.url || item.url; r.ms = r.ms || (Date.now() - t0); pages.push(r); })
          .catch(function () { pages.push({ ok: false, url: item.url, error: { message: 'Error de red.' } }); })
          .then(function () { done++; progress.value = done; announce(false); });
      }, function () { return stopRequested; });
      pagesDone.then(function () {
        statusBox.textContent = 'Páginas terminadas (' + done + '). Esperando a las pruebas de la portada (Chrome y PageSpeed)…';
      });
      return Promise.all([pagesDone, chrome, psi]).then(function (both) {
        home.chromeResult = both[1];
        home.psiResult = both[2];
        submit.disabled = false;
        progressWrap.hidden = true;
        var seconds = Math.round((Date.now() - started) / 1000);
        statusBox.textContent = 'Análisis terminado en ' + seconds + ' s: portada y ' + done + ' páginas.';
        render(home, pages, list.length, seconds);
        saveHistory(home, launched);
      });
    }).catch(function () {
      submit.disabled = false;
      statusBox.textContent = '';
      showError('No se ha podido conectar con el analizador.');
    });
  }

  function homeAsPage(home) {
    var p = home.puntuacion;
    return {
      ok: true, url: home.url, blocked: home.blocked, ms: home.sitio.tiempo_portada_ms, checks: home.checks, portada: true, titulo: home.titulo,
      notas: { seo: p.areas.seo.puntuacion, ia: p.areas.ia.puntuacion, accesibilidad: p.areas.accesibilidad.puntuacion, global: p.global.puntuacion }
    };
  }

  /**
   * Matriz páginas × puntos de un bloque (DESIGN.md §5): estado de cada punto en cada página, filas ordenadas
   * por número de fallos. Solo con 5 páginas con nota o más; si no, null (no se dibuja). Los puntos de todo el
   * sitio (C16, C18) no van: no son de cada página. La matriz es la propia tabla de datos.
   */
  var MATRIX_MIN_PAGES = 5;
  function matrixFigure(agg, area) {
    var C = root.RadarComponents;
    var pages = agg.paginas || [];
    if (!C || pages.length < MATRIX_MIN_PAGES) return null;
    var blockOf = i18n.bloque_checks || {};
    var names = {};
    pages.forEach(function (p) {
      (p.checks || []).forEach(function (c) {
        if (blockOf[c.id] === area && c.id !== 'C16' && c.id !== 'C18') names[c.id] = c.nombre || c.id;
      });
    });
    var ids = Object.keys(names).sort();
    if (!ids.length) return null;
    var rows = pages.map(function (p) {
      var cells = {};
      var fails = 0;
      (p.checks || []).forEach(function (c) {
        if (!names[c.id]) return;
        cells[c.id] = c.aplica === false ? 'NA' : c.estado;
        if (c.aplica !== false && c.estado === 'NO_CUMPLE') fails++;
      });
      return { texto: path(p.url), enlace: p.url, celdas: cells, fallos: fails };
    }).sort(function (a, b) { return b.fallos - a.fallos || (a.texto < b.texto ? -1 : 1); });
    // Conclusión: el punto que falla en más páginas. En el 80 % o más, lo probable es la plantilla
    var failCount = function (id) { return rows.filter(function (r) { return r.celdas[id] === 'NO_CUMPLE'; }).length; };
    var top = ids.slice().sort(function (a, b) { return failCount(b) - failCount(a) || (a < b ? -1 : 1); })[0];
    var topFails = failCount(top);
    var area_ = (i18n.areas && i18n.areas[area]) || area;
    var title = !topFails
      ? 'Ningún fallo de ' + area_ + ' en las ' + pages.length + ' páginas con nota'
      : top + ' (' + names[top] + ') falla en ' + topFails + ' de ' + pages.length + ' páginas'
        + (topFails >= pages.length * 0.8 ? ': probablemente está en la plantilla' : ': el fallo depende de cada página');
    var textos = {};
    Object.keys(i18n.estados || {}).forEach(function (k) { textos[k] = i18n.estados[k]; });
    textos.NA = 'No aplica';
    var capId = 'cap-matriz-' + area;
    return el('div', { class: 'rw' }, [el('figure', { class: 'rw-figure', 'aria-labelledby': capId }, [
      el('figcaption', { id: capId, class: 'rw-figure__title', text: title }),
      el('p', { class: 'rw-figure__summary', text: 'Estado de cada punto de ' + area_ + ' (columnas) en cada página con nota (filas), con las páginas que más fallan arriba. Un fallo en todas las páginas suele estar en la plantilla o en un componente común.' }),
      C.matrix({ titulo: 'Puntos de ' + area_ + ' por página', columnas: ids.map(function (id) { return { id: id, texto: names[id] }; }), filas: rows, textos: textos })
    ])]);
  }

  function render(home, pages, planned, seconds) {
    var all = [homeAsPage(home)].concat(pages);
    var agg = aggregate(all, { previstas: planned + 1, maxPaginas: i18n.grupo_max_paginas });
    var siteChecks = (home.checks || []).filter(function (c) { return c.id === 'C16' || c.id === 'C18'; });
    results.textContent = '';

    results.appendChild(el('div', { class: 'report-head' }, [
      el('h2', { id: 'site-results-title', tabindex: '-1', text: 'Informe del sitio' }),
      el('ul', { class: 'meta-list' }, [
        el('li', { text: 'Sitio: ' + home.url }),
        home.guardado ? el('li', { text: 'Informe guardado del ' + new Date(home.guardado).toLocaleString('es-ES', { dateStyle: 'long', timeStyle: 'short' }) + ' (no se ha vuelto a analizar)' }) : null,
        el('li', { text: 'Páginas analizadas: ' + agg.analizadas + ' (portada y ' + (agg.analizadas - (home.blocked ? 0 : 1)) + ' de la muestra; ' + planned + ' previstas de ' + home.sitio.candidatas + ' encontradas)' }),
        el('li', { text: 'Origen de la muestra: ' + home.sitio.desde_enlaces + ' enlaces de la portada y ' + home.sitio.desde_sitemap + ' URL de ' + home.sitio.sitemaps_leidos + ' archivo(s) de sitemap' }),
        el('li', { text: 'Tiempo total: ' + seconds + ' s' }),
        agg.bloqueadas ? el('li', { text: 'Páginas con acceso bloqueado (no puntúan): ' + agg.bloqueadas }) : null,
        agg.errores ? el('li', { text: 'Páginas que no se han podido descargar (no puntúan): ' + agg.errores }) : null
      ].concat((home.sitio.notas || []).map(function (n) { return el('li', { text: n }); })))
    ]));

    if (home.blocked || agg.analizadas === 0) {
      results.appendChild(el('p', { class: 'notice notice--warn', text: 'No calculable: el sitio ha bloqueado el acceso automático. ' + ((home.blocked && home.blocked.message) || '') }));
      results.hidden = false;
      results.querySelector('h2').focus();
      return;
    }

    // Notas medias
    results.appendChild(el('h3', { text: 'Nota media del sitio' }));
    results.appendChild(el('ul', { class: 'scores' }, AREAS.map(function (a) {
      return scoreCard(i18n.areas[a] || a, agg.media[a], agg.minima[a], false);
    }).concat([scoreCard('Nota global', agg.media.global, agg.minima.global, true)])));
    results.appendChild(el('p', { class: 'help', text: 'Media de las ' + agg.analizadas + ' páginas analizadas. «Peor página» es la nota más baja de la muestra.' }));
    var rel = agg.fiabilidad;
    results.appendChild(el('p', { class: 'help', text: 'Fiabilidad de las notas · análisis ' + rel.estado + ': ' + rel.paginas.con_nota + ' de '
      + rel.paginas.previstas + ' páginas previstas con nota; evaluados ' + rel.evaluados + ' de ' + rel.posibles + ' puntos en esas páginas'
      + (rel.sin_datos ? ' (' + rel.sin_datos + ' sin datos, que no restan).' : '.') }));

    // ¿El fallo es de una página o de la plantilla? Matriz de los puntos de SEO (5 páginas o más)
    var seoMatrix = matrixFigure(agg, 'seo');
    if (seoMatrix) {
      results.appendChild(el('h3', { text: 'Puntos de SEO por página' }));
      results.appendChild(seoMatrix);
    }

    // Fallos que se repiten
    results.appendChild(el('h3', { text: 'Fallos por comprobación' }));
    var failing = agg.checks.filter(function (c) { return (c.fallan > 0 || c.avisos > 0) && c.id !== 'C16' && c.id !== 'C18'; });
    if (!failing.length) {
      results.appendChild(el('p', { text: 'Sin errores detectables automáticamente en las páginas de la muestra.' }));
    } else {
      results.appendChild(el('div', { class: 'table-wrap' }, [el('table', { class: 'data-table' }, [
        el('caption', { class: 'visually-hidden', text: 'Comprobaciones con fallos o avisos en el sitio' }),
        el('thead', {}, [el('tr', {}, ['Comprobación', 'Gravedad', 'No cumple', 'Avisos', 'Evaluadas'].map(function (h) { return el('th', { scope: 'col', text: h }); }))]),
        el('tbody', {}, failing.map(function (c) {
          return el('tr', {}, [
            el('th', { scope: 'row', text: c.id + ' · ' + c.nombre }),
            el('td', { text: (i18n.severidades && i18n.severidades[c.severidad]) || c.severidad }),
            el('td', { class: 'num', text: c.fallan + ' (' + pct(c.fallan, c.evaluadas) + ')' }),
            el('td', { class: 'num', text: String(c.avisos) }),
            el('td', { class: 'num', text: String(c.evaluadas) })
          ]);
        }))
      ])]));
      // Recomendaciones agrupadas: cada corrección una sola vez, con las páginas a las que afecta
      results.appendChild(el('h3', { text: 'Correcciones, de más a menos páginas afectadas' }));
      results.appendChild(el('p', { class: 'help', text: 'Cada recomendación aparece una vez, con el número de páginas de la muestra en las que se ha detectado. Arreglarla en la plantilla o el componente común suele corregir todas a la vez. Las recomendaciones no cambian la nota.' }));
      agg.recomendaciones.forEach(function (g) {
        var counts = [];
        if (g.paginas_fallan) counts.push(g.paginas_fallan + ' no cumplen');
        if (g.paginas_aviso) counts.push(g.paginas_aviso + ' con aviso');
        results.appendChild(el('details', { class: 'fold' }, [
          el('summary', {}, [el('span', { class: 'fold__title', text: g.paginas_total + (g.paginas_total === 1 ? ' página' : ' páginas') + ' · '
            + g.ids.map(function (id, i) { return id + ' ' + g.nombres[i]; }).join(', ') })]),
          el('div', { class: 'fold__body' }, [
            el('p', { text: 'Afecta a ' + g.paginas_total + ' de ' + agg.analizadas + ' páginas analizadas (' + counts.join('; ') + '). Gravedad: '
              + ((i18n.severidades && i18n.severidades[g.severidad]) || g.severidad) + '.' }),
            el('p', { text: 'Cómo arreglarlo: ' + g.recomendacion }),
            el('ul', {}, g.paginas.map(function (x) {
              return el('li', {}, [el('a', { href: x.url, rel: 'noopener noreferrer', text: path(x.url) }),
                (x.estado === 'AVISO' ? ' (aviso)' : '') + (x.detalle ? ': ' + x.detalle : '')]);
            })),
            g.paginas_mas > 0 ? el('p', { class: 'help', text: 'Y ' + g.paginas_mas + (g.paginas_mas === 1 ? ' página más.' : ' páginas más.') + ' La lista completa está en la descarga CSV de páginas.' }) : null
          ])
        ]));
      });
    }

    // Comprobaciones de todo el sitio: C16 y C18 en la portada, página 404 y títulos repetidos
    var stateText = function (c) { return (i18n.estados && i18n.estados[c.estado]) || c.estado; };
    var siteItems = siteChecks.map(function (c) {
      return el('li', { text: c.id + ' · ' + c.nombre + ': ' + stateText(c) + '. ' + (c.detalle || '') });
    });
    if (home.sitio.pagina_404) {
      var c404 = home.sitio.pagina_404;
      siteItems.push(el('li', { text: 'Página de error 404: ' + stateText(c404) + '. ' + c404.detalle }));
    }
    var dupTitles = agg.titulos_repetidos;
    var dupPages = dupTitles.reduce(function (n, g) { return n + g.urls.length; }, 0);
    siteItems.push(el('li', {}, [
      'Títulos únicos: ' + (dupTitles.length
        ? 'No cumple. ' + dupPages + ' de ' + agg.con_titulo + ' páginas comparten title con otra (' + dupTitles.length + ' títulos repetidos).'
        : stateText({ estado: 'CUMPLE' }) + '. Ninguna de las ' + agg.con_titulo + ' páginas repite el title de otra.'),
      dupTitles.length ? el('ul', {}, dupTitles.slice(0, 5).map(function (g) {
        return el('li', { text: '«' + g.titulo + '»: ' + g.urls.map(path).join(', ') });
      })) : null
    ]));
    results.appendChild(el('h3', { text: 'Comprobaciones de todo el sitio' }));
    results.appendChild(el('ul', {}, siteItems));
    results.appendChild(el('p', { class: 'help', text: 'Enlaces rotos y sitemap se miran en la portada; la página 404 con una dirección inventada; los títulos, entre las páginas de la muestra. No cambian la nota media.' }));

    // Pruebas con Chrome (informativas)
    if (home.chromeResult) {
      results.appendChild(el('h3', { text: 'Pruebas con Chrome en la portada (informativas, no cambian la nota)' }));
      if (!home.chromeResult.ok) {
        results.appendChild(el('p', { text: 'No se han podido hacer: ' + ((home.chromeResult.error && home.chromeResult.error.message) || 'error') }));
      } else {
        results.appendChild(el('ul', {}, home.chromeResult.checks.map(function (c) {
          var estado = c.aplica === false ? 'No aplica' : ((i18n.estados && i18n.estados[c.estado]) || c.estado);
          return el('li', {}, [el('strong', { text: c.nombre + ': ' + estado + '.' }), ' ' + c.detalle]);
        })));
        results.appendChild(el('p', { class: 'help', text: 'Medido con Chrome sin ventana en ' + (Math.round(home.chromeResult.ms / 100) / 10).toLocaleString('es-ES') + ' s: pulsa Tab, cambia el ancho a 640 y 320 px, busca el aviso de cookies, prueba el carrusel y envía vacío un formulario (nunca con datos).' }));
      }
    }

    // PageSpeed de la portada (informativo en el modo sitio: las demás páginas no lo tienen)
    if (home.psiResult) {
      results.appendChild(el('h3', { text: 'PageSpeed en la portada (contraste y Core Web Vitals)' }));
      var psiOk = home.psiResult.ok && home.psiResult.checks;
      if (!psiOk) {
        results.appendChild(el('p', { text: 'No se ha podido consultar: ' + ((home.psiResult.error && home.psiResult.error.message) || 'error') }));
      } else {
        results.appendChild(el('ul', {}, home.psiResult.checks.map(function (c) {
          var estado = c.aplica === false ? 'No aplica' : ((i18n.estados && i18n.estados[c.estado]) || c.estado);
          return el('li', {}, [el('strong', { text: c.id + ' · ' + c.nombre + ': ' + estado + '.' }), ' ' + (c.detalle || '')]);
        })));
        if (!(home.psiResult.psi && home.psiResult.psi.ok)) {
          results.appendChild(el('p', { class: 'help', text: 'PageSpeed Insights no ha devuelto datos (sin clave de API o cuota agotada). Para medirlo, añade psi_api_key en la configuración.' }));
        }
      }
      results.appendChild(el('p', { class: 'help', text: 'No cambia la nota media del sitio, porque solo se mide en la portada. Para una página concreta, usa «Analizar una URL».' }));
    }

    // Páginas, de peor a mejor
    results.appendChild(el('h3', { text: 'Páginas analizadas (de peor a mejor)' }));
    results.appendChild(el('div', { class: 'table-wrap' }, [el('table', { class: 'data-table' }, [
      el('caption', { class: 'visually-hidden', text: 'Notas de cada página analizada' }),
      el('thead', {}, [el('tr', {}, ['Página', 'Global', 'SEO', 'IA', 'Accesibilidad', 'Tiempo'].map(function (h) { return el('th', { scope: 'col', text: h }); }))]),
      el('tbody', {}, agg.peores.map(function (p) {
        return el('tr', {}, [
          el('th', { scope: 'row' }, [el('a', { href: p.url, rel: 'noopener noreferrer', text: p.portada ? 'Portada (' + path(p.url) + ')' : path(p.url) })]),
          el('td', { class: 'num', text: fmt(p.notas.global) }),
          el('td', { class: 'num', text: fmt(p.notas.seo) }),
          el('td', { class: 'num', text: fmt(p.notas.ia) }),
          el('td', { class: 'num', text: fmt(p.notas.accesibilidad) }),
          el('td', { class: 'num', text: (Math.round((p.ms || 0) / 100) / 10).toLocaleString('es-ES') + ' s' })
        ]);
      }))
    ])]));

    var problems = all.filter(function (p) { return !p.ok || p.blocked; });
    if (problems.length) {
      results.appendChild(el('h3', { text: 'Páginas sin nota' }));
      results.appendChild(el('ul', {}, problems.map(function (p) {
        return el('li', { text: path(p.url) + ': ' + (p.blocked ? (p.blocked.message || 'acceso bloqueado') : ((p.error && p.error.message) || 'error')) });
      })));
    }

    var csvButton = el('button', { type: 'button', class: 'button button--secondary', text: 'Descargar páginas (CSV)' });
    csvButton.addEventListener('click', function () { downloadCsv(all); });
    var pdfButton = el('button', { type: 'button', class: 'button button--secondary', text: 'Informe del sitio (PDF)' });
    pdfButton.addEventListener('click', function () { root.print(); });
    results.appendChild(el('section', { class: 'downloads no-print', 'aria-labelledby': 'site-downloads-title' }, [
      el('h3', { id: 'site-downloads-title', text: 'Descargas' }),
      el('div', { class: 'report-actions' }, [csvButton, pdfButton]),
      el('p', { class: 'help', text: 'El PDF se crea con el diálogo de impresión: elige «Guardar como PDF». Los archivos se generan en tu navegador; no pasan por el servidor. El CSV usa «;» y UTF-8 para abrirlo en Excel en español.' })
    ]));

    results.hidden = false;
    results.querySelector('h2').focus();
  }

  /**
   * Historial (v3.0): manda al servidor qué páginas se han analizado; el servidor junta sus propios resultados,
   * recalcula la media y guarda la fila con el informe completo. Después se muestran los análisis anteriores.
   */
  function saveHistory(home, launched) {
    var reg = home.sitio && home.sitio.registro;
    if (!reg) return;
    var section = el('section', { class: 'fold-host', 'aria-labelledby': 'site-history-title' }, [
      el('h3', { id: 'site-history-title', text: 'Análisis anteriores de este sitio' }),
      el('p', { class: 'help', text: 'Guardando este análisis en el historial…' })
    ]);
    var downloads = results.querySelector('.downloads');
    results.insertBefore(section, downloads || null);
    // Solo se mandan las firmas: los resultados los tiene ya el servidor
    var body = {
      url: home.url, expira: home.sitio.expira, firma: reg.firma, paginas: launched,
      psi_firma: (home.psiResult && home.psiResult.firma) || null
    };
    post('api/site_record.php', body).then(function (r) {
      section.removeChild(section.lastChild);
      if (!r.ok || !r.activo) {
        section.appendChild(el('p', { text: (r.error && r.error.message) || 'El historial no está activado.' }));
        return;
      }
      section.appendChild(el('p', { class: 'help', text: 'Guardado con ' + r.paginas + ' páginas, con este informe completo, durante ' + r.meses + ' meses. Puedes volver a verlo desde «Historial».'
        + (r.sin_resultado ? ' Aviso: ' + r.sin_resultado + ' página(s) analizadas no se han podido incluir porque el servidor ya no tenía su resultado.' : '') }));
      if (!r.anteriores.length) {
        section.appendChild(el('p', { text: 'Es el primer análisis guardado de este sitio.' }));
        return;
      }
      section.appendChild(el('div', { class: 'table-wrap' }, [el('table', { class: 'data-table data-table--history' }, [
        el('caption', { class: 'visually-hidden', text: 'Análisis anteriores de este sitio' }),
        el('thead', {}, [el('tr', {}, ['Fecha', 'Global', 'SEO', 'IA', 'Accesibilidad', 'Páginas'].map(function (h) { return el('th', { scope: 'col', text: h }); }))]),
        el('tbody', {}, r.anteriores.map(function (a) {
          return el('tr', {}, [
            el('th', { scope: 'row', text: new Date(a.fecha).toLocaleString('es-ES', { dateStyle: 'short', timeStyle: 'short' }) }),
            el('td', { class: 'num', text: a.bloqueado ? 'bloqueado' : fmt(a.nota_global) }),
            el('td', { class: 'num', text: fmt(a.nota_seo) }),
            el('td', { class: 'num', text: fmt(a.nota_ia) }),
            el('td', { class: 'num', text: fmt(a.nota_accesibilidad) }),
            el('td', { class: 'num', text: String(a.paginas) + (a.fiabilidad ? ' · ' + a.fiabilidad.estado : '') })
          ]);
        }))
      ])]));
    }).catch(function () {
      section.removeChild(section.lastChild);
      section.appendChild(el('p', { text: 'No se ha podido guardar este análisis en el historial.' }));
    });
  }

  function scoreCard(label, value, worst, isGlobal) {
    var meter = el('div', { class: 'meter ' + (value >= 90 ? 'is-good' : value >= 50 ? 'is-mid' : 'is-low'), 'aria-hidden': 'true' }, [el('span')]);
    meter.firstChild.style.width = Math.max(0, Math.min(100, value || 0)) + '%';
    return el('li', { class: 'score' + (isGlobal ? ' score--global' : '') }, [
      el('p', { class: 'score__label', text: label }),
      el('p', { class: 'score__value' }, [fmt(value), el('span', { class: 'score__max', text: ' / 100' })]),
      meter,
      el('p', { class: 'score__coverage', text: 'Peor página: ' + fmt(worst) })
    ]);
  }

  function csvCell(v) {
    var s = v === null || v === undefined ? '' : String(v);
    // Evita que una hoja de cálculo interprete fórmulas
    if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
    return '"' + s.replace(/"/g, '""') + '"';
  }

  function downloadCsv(all) {
    var ids = [];
    all.forEach(function (p) { (p.checks || []).forEach(function (c) { if (ids.indexOf(c.id) < 0) ids.push(c.id); }); });
    ids.sort();
    var rows = [['url', 'estado', 'global', 'seo', 'ia', 'accesibilidad', 'ms'].concat(ids)];
    all.forEach(function (p) {
      var states = {};
      (p.checks || []).forEach(function (c) { states[c.id] = c.aplica === false ? 'NO_APLICA' : c.estado; });
      var n = p.notas || {};
      rows.push([p.url, !p.ok ? 'error' : p.blocked ? 'bloqueada' : 'ok', n.global, n.seo, n.ia, n.accesibilidad, p.ms]
        .concat(ids.map(function (id) { return states[id] || ''; })));
    });
    var text = '﻿' + rows.map(function (r) { return r.map(csvCell).join(';'); }).join('\r\n');
    var href = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
    var a = el('a', { href: href, download: 'sitio-' + new Date().toISOString().slice(0, 10) + '.csv' });
    doc.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(href); }, 1000);
  }
})(typeof window !== 'undefined' ? window : this);
