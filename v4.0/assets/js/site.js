/*
 * Sitio entero (v3.0, v4: todas las páginas). Lo arranca assets/js/app.js después de analizar la URL buscada.
 * 1. api/site.php analiza la portada y devuelve las páginas del sitio que encuentra (enlaces y sitemaps), firmadas.
 * 2. Este script las analiza EN PARALELO con api/site_page.php (de N en N). Cada página devuelve sus enlaces a
 *    otras páginas del mismo sitio, ya firmados: las nuevas se añaden a la cola hasta recorrer el sitio entero
 *    (con el tope de seguridad site_max_pages). «Terminar ya» corta y muestra el informe con lo analizado.
 * 3. Se agregan los resultados: nota media, fallos que se repiten y páginas peor puntuadas.
 * Todo se pinta con textContent: nunca se interpreta HTML de la web analizada.
 */
(function (root) {
  'use strict';

  var AREAS = ['seo', 'ia', 'accesibilidad'];
  var SEV_ORDER = { CRITICO: 0, ALTO: 1, MEDIO: 2, BAJO: 3 };

  /** Clave para no analizar dos veces la misma página (http/https, www y barra final dan igual). */
  function pageKey(u) {
    return String(u).toLowerCase().replace(/#.*$/, '').replace(/^https?:\/\/(www\.)?/, '').replace(/\/+$/, '');
  }

  /**
   * Agrega los resultados de las páginas (función pura; la prueban tests/js/site_test.js).
   * @param {Array} pages [{ok, url, blocked, ms, checks:[{id,nombre?,estado,severidad,aplica,...}], notas:{seo,ia,accesibilidad,global}}]
   * @param {Object} [opts] {previstas: nº de páginas que se pensaba analizar (portada incluida),
   *                         maxPaginas: tope de páginas que se listan en cada recomendación agrupada (20),
   *                         names: nombre de cada comprobación por id, para las páginas que no lo traen}
   */
  function aggregate(pages, opts) {
    opts = opts || {};
    var names = opts.names || {};
    var cap = Math.max(1, parseInt(opts.maxPaginas, 10) || 20);
    // Recomendaciones idénticas de varias páginas, agrupadas en una sola (clave: texto de la recomendación)
    var byFix = {};
    var addFix = function (p, c) {
      if (!c.recomendacion || c.id === 'C16' || c.id === 'C18') return;   // C16 y C18 son de todo el sitio
      var g = byFix[c.recomendacion] || (byFix[c.recomendacion] = {
        recomendacion: c.recomendacion, ids: [], nombres: [], severidad: c.severidad,
        paginas_total: 0, paginas_fallan: 0, paginas_aviso: 0, paginas: [], vistas: {}
      });
      if (g.ids.indexOf(c.id) === -1) { g.ids.push(c.id); g.nombres.push(c.nombre || names[c.id] || c.id); }
      if ((SEV_ORDER[c.severidad] === undefined ? 9 : SEV_ORDER[c.severidad]) < (SEV_ORDER[g.severidad] === undefined ? 9 : SEV_ORDER[g.severidad])) g.severidad = c.severidad;
      if (g.vistas[p.url]) return;   // una página cuenta una vez por recomendación
      g.vistas[p.url] = true;
      g.paginas_total++;
      if (c.estado === 'NO_CUMPLE') g.paginas_fallan++; else g.paginas_aviso++;
      if (g.paginas.length < cap) g.paginas.push({ url: p.url, estado: c.estado, detalle: c.detalle || '' });
    };
    // Dos URL pueden redirigir a la misma página: cuenta una sola vez
    var seen = {};
    var scored = pages.filter(function (p) {
      if (!(p.ok && !p.blocked && p.notas)) return false;
      var k = pageKey(p.url);
      if (seen[k]) return false;
      seen[k] = true;
      return true;
    });
    var mean = {};
    var min = {};
    ['seo', 'ia', 'accesibilidad', 'global'].forEach(function (a) {
      var values = scored.map(function (p) { return p.notas[a]; });
      mean[a] = values.length ? values.reduce(function (s, v) { return s + v; }, 0) / values.length : null;
      min[a] = values.length ? values.reduce(function (m, v) { return v < m ? v : m; }, values[0]) : null;
    });
    var byCheck = {};
    scored.forEach(function (p) {
      (p.checks || []).forEach(function (c) {
        var row = byCheck[c.id] || (byCheck[c.id] = {
          id: c.id, nombre: c.nombre || names[c.id] || c.id, severidad: c.severidad, recomendacion: '',
          evaluadas: 0, fallan: 0, avisos: 0, sin_datos: 0, ejemplos: []
        });
        if (c.aplica === false) return;
        if (c.estado === 'SIN_DATOS') { row.sin_datos++; return; }
        row.evaluadas++;
        if (c.estado === 'NO_CUMPLE') {
          row.fallan++;
          if (!row.recomendacion && c.recomendacion) row.recomendacion = c.recomendacion;
          if (row.ejemplos.length < 10) row.ejemplos.push({ url: p.url, detalle: c.detalle || '' });
          addFix(p, c);
        } else if (c.estado === 'AVISO') {
          row.avisos++;
          if (!row.recomendacion_aviso && c.recomendacion) row.recomendacion_aviso = c.recomendacion;
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
    // v4 · Pistas para la revisión manual: alt sospechosos y legibilidad, por página
    var conAlt = scored.filter(function (p) { return p.pistas && p.pistas.alt_sospechosos > 0; });
    var conLeg = scored.filter(function (p) { return p.pistas && typeof p.pistas.legibilidad === 'number'; });
    var pistas = {
      alt_total: conAlt.reduce(function (n, p) { return n + p.pistas.alt_sospechosos; }, 0),
      alt_paginas: conAlt.length,
      alt_peores: conAlt.slice().sort(function (a, b) { return b.pistas.alt_sospechosos - a.pistas.alt_sospechosos; }).slice(0, 10),
      legibilidad_media: conLeg.length ? conLeg.reduce(function (n, p) { return n + p.pistas.legibilidad; }, 0) / conLeg.length : null,
      legibilidad_paginas: conLeg.length,
      mas_dificiles: conLeg.slice().sort(function (a, b) { return a.pistas.legibilidad - b.pistas.legibilidad; }).slice(0, 10)
    };
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
      pistas: pistas,
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

  /**
   * Ejecuta tareas con un máximo de N a la vez; shouldStop() corta el reparto de nuevas tareas.
   * La lista puede crecer mientras se trabaja (las páginas descubiertas se añaden al final).
   */
  function pool(items, limit, worker, shouldStop) {
    return new Promise(function (resolve) {
      var next = 0;
      var running = 0;
      function launch() {
        if ((next >= items.length || shouldStop()) && running === 0) { resolve(); return; }
        while (running < limit && next < items.length && !shouldStop()) {
          var item = items[next++];
          running++;
          Promise.resolve().then(function () { return worker(item); }).catch(function () {}).then(function () { running--; launch(); });
        }
      }
      launch();
    });
  }

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { aggregate: aggregate, pool: pool, pageKey: pageKey };
    return;
  }

  // ------------------------------------------------------------------ interfaz
  var doc = root.document;
  var i18n = JSON.parse(doc.getElementById('i18n').textContent);
  var section = doc.getElementById('site-section');
  var statusBox = doc.getElementById('site-status');
  var progressWrap = doc.getElementById('site-progress-wrap');
  var progress = doc.getElementById('site-progress');
  var stopButton = doc.getElementById('site-stop');
  var errorBox = doc.getElementById('site-error-box');
  var results = doc.getElementById('site-results');
  var names = i18n.nombres_checks || {};
  var stopRequested = false;
  var currentRun = 0;

  function areaName(a) { return (i18n.areas && i18n.areas[a]) || a; }

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
  function path(u) { try { var x = new URL(u); return x.pathname + x.search || '/'; } catch (e) { return u; } }
  function duration(s) {
    s = Math.max(0, Math.round(s));
    if (s < 60) return s + ' s';
    var m = Math.floor(s / 60);
    return m < 60 ? m + ' min ' + (s % 60) + ' s' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min';
  }

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
  }

  function reset() {
    lastModel = null;
    section.hidden = false;
    errorBox.hidden = true;
    results.hidden = true;
    results.textContent = '';
    progressWrap.hidden = true;
    statusBox.textContent = '';
  }

  stopButton.addEventListener('click', function () {
    stopRequested = true;
    stopButton.disabled = true;
    statusBox.textContent = 'Terminando: se esperan las páginas que ya estaban en marcha…';
  });

  /**
   * Analiza el sitio entero de la URL buscada.
   * opts.enlace: {id, expira, firma} de la fila del historial de la URL (para guardar todo en la misma fila).
   * opts.esperar: promesa que hay que esperar antes de guardar (el informe de la URL con PageSpeed).
   */
  function start(url, opts) {
    opts = opts || {};
    var runId = ++currentRun;
    var started = Date.now();
    stopRequested = false;
    stopButton.disabled = false;
    reset();
    var deep = opts.modo === 'profundo';
    statusBox.textContent = deep
      ? 'Análisis profundo: buscando todas las páginas del sitio (enlaces y sitemap)…'
      : 'Análisis rápido: eligiendo las ' + 49 + ' páginas principales del sitio…';

    post('api/site.php', { url: url, modo: deep ? 'profundo' : 'rapido', noindex_intentional: !!opts.noindexIntencionado }).then(function (home) {
      if (runId !== currentRun) return;
      if (!home.ok) {
        statusBox.textContent = '';
        showError('No se ha podido analizar el resto del sitio: ' + ((home.error && home.error.message) || 'error.'));
        return;
      }
      var maxPages = home.sitio.max_paginas || 5000;
      var list = home.sitio.paginas.slice(0, maxPages);
      var known = {};
      known[pageKey(home.url)] = true;
      list.forEach(function (p) { known[pageKey(p.url)] = true; });
      var discovered = 0;
      var capped = false;
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
        var elapsed = (Date.now() - started) / 1000;
        var left = list.length - done;
        var eta = done > 5 && left > 0 ? ' Quedan unos ' + duration(elapsed / done * left) + (deep ? ' (pueden aparecer más páginas).' : '.') : '';
        statusBox.textContent = (deep ? 'Análisis profundo: ' + done + ' de ' + list.length + ' páginas encontradas analizadas.' : 'Análisis rápido: ' + done + ' de ' + list.length + ' páginas analizadas.')
          + ' Tiempo: ' + duration(elapsed) + '.' + eta;
      };
      announce(true);
      var launched = [];   // firmas de las páginas lanzadas (el servidor guarda sus resultados para el historial)
      // v4: el servidor puede detener el análisis (tope alcanzado o el sitio pide frenar con 429/503)
      var serverStop = null;
      var shouldStop = function () { return stopRequested || serverStop !== null || runId !== currentRun; };
      var pagesDone = pool(list, home.sitio.paralelas || 6, function (item) {
        launched.push(item.firma);
        var t0 = Date.now();
        var body = { url: item.url, run: home.sitio.run, firma: item.firma, expira: home.sitio.expira, noindex_intentional: !!home.sitio.noindex_intentional };
        // RETRY: el servidor reparte el ritmo de peticiones al sitio (Crawl-delay); se espera y se repite
        var attempt = function (tries) {
          return post('api/site_page.php', body).then(function (r) {
            if (r && !r.ok && r.error && r.error.code === 'RETRY' && tries < 20 && !shouldStop()) {
              return new Promise(function (res) { setTimeout(res, Math.min(30, Math.max(1, Number(r.reintentar) || 1)) * 1000); })
                .then(function () { return attempt(tries + 1); });
            }
            return r;
          });
        };
        return attempt(0)
          .then(function (r) {
            var code = r && !r.ok && r.error ? r.error.code : null;
            if (code === 'SITE_STOPPED' || code === 'LIMIT_REACHED' || code === 'RETRY' || code === 'RUN_UNKNOWN') {
              // No se ha analizado: fuera de la lista de guardado y del informe
              launched.splice(launched.indexOf(item.firma), 1);
              if (code !== 'RETRY' && serverStop === null) serverStop = r.error.message;
              return;
            }
            if (r && r.parado && serverStop === null) serverStop = r.parado;
            // Páginas nuevas enlazadas desde esta: a la cola, sin repetir y hasta el máximo
            (r.nuevas || []).forEach(function (n) {
              var k = pageKey(n.url);
              if (known[k]) return;
              if (list.length >= maxPages) { capped = true; return; }
              known[k] = true;
              list.push(n);
              discovered++;
            });
            delete r.nuevas;
            r.url = r.url || item.url;
            r.ms = r.ms || (Date.now() - t0);
            pages.push(r);
          })
          .catch(function () { pages.push({ ok: false, url: item.url, error: { message: 'Error de red.' } }); })
          .then(function () { done++; progress.max = Math.max(1, list.length); progress.value = done; announce(false); });
      }, shouldStop);
      // v4: sin las pruebas con Chrome (eran informativas y no cambiaban la nota); api/browser.php sigue disponible
      return pagesDone.then(function () {
        if (runId !== currentRun) return;
        progressWrap.hidden = true;
        var seconds = Math.round((Date.now() - started) / 1000);
        var info = { encontradas: list.length, descubiertas: discovered, tope: capped ? maxPages : 0, cortado: (stopRequested || serverStop !== null) && pages.length < list.length, parado: serverStop };
        statusBox.textContent = (deep ? 'Análisis profundo' : 'Análisis rápido') + ' del sitio terminado en ' + duration(seconds) + ': ' + (done + 1) + ' páginas.';
        render(home, pages, info, seconds);
        saveHistory(home, launched, opts);
      });
    }).catch(function () {
      if (runId !== currentRun) return;
      statusBox.textContent = '';
      showError('No se ha podido conectar con el analizador para recorrer el sitio.');
    });
  }

  /** Cancela el análisis del sitio en marcha (al empezar otra búsqueda). */
  function cancel() {
    currentRun++;
    stopRequested = true;
    section.hidden = true;
  }

  /** Informe guardado en el historial: {home, pages, chromeResult, psiResult?, seconds, planned?}. */
  function showSaved(inf, fecha) {
    reset();
    var home = inf.home;
    home.chromeResult = inf.chromeResult || null;
    home.psiResult = inf.psiResult || null;
    home.guardado = fecha;
    var pages = inf.pages || [];
    statusBox.textContent = '';
    var descubiertas = Math.max(0, pages.length - (home.sitio.candidatas || 0));
    render(home, pages, { encontradas: inf.planned || pages.length, descubiertas: descubiertas, tope: 0, cortado: false }, inf.seconds || 0);
  }

  function homeAsPage(home) {
    var p = home.puntuacion;
    return {
      ok: true, url: home.url, blocked: home.blocked, ms: home.sitio.tiempo_portada_ms, checks: home.checks, portada: true, titulo: home.titulo, pistas: home.pistas || null,
      notas: { seo: p.areas.seo.puntuacion, ia: p.areas.ia.puntuacion, accesibilidad: p.areas.accesibilidad.puntuacion, global: p.global.puntuacion }
    };
  }

  /** Área de los puntos del sitio que no son una comprobación (C16 y C18 van por bloque_checks). */
  var EXTRA_AREA = { titulos: 'seo', error404: 'accesibilidad' };

  /**
   * v4 · Resultado del sitio, en sencillo: cabecera breve, nota global y tres tarjetas (SEO, GEO, UX) que al
   * abrirlas listan lo que falla o falta en esa área, con cuántas páginas afecta y cómo arreglarlo. La lista
   * de páginas va en el CSV (descargas, al final de la pantalla).
   */
  var lastModel = null;   // último resultado del sitio en datos (lo usa el PDF del análisis, assets/js/pdf.js)

  function render(home, pages, info, seconds) {
    var all = [homeAsPage(home)].concat(pages);
    lastModel = null;
    var agg = aggregate(all, { names: names, previstas: (info.encontradas || pages.length) + 1, maxPaginas: i18n.grupo_max_paginas });
    results.textContent = '';

    var modo = home.sitio.modo === 'rapido' ? 'Análisis rápido (portada y páginas principales)'
      : home.sitio.modo === 'profundo' ? 'Análisis profundo (todas las páginas encontradas)' : 'Análisis del sitio';
    results.appendChild(el('div', { class: 'report-head' }, [
      el('h2', { id: 'site-results-title', tabindex: '-1', text: 'Resultado del sitio' }),
      el('ul', { class: 'meta-list' }, [
        el('li', { text: modo + ': ' + agg.analizadas + ' páginas con nota en ' + duration(seconds) + '.' }),
        info.cortado ? el('li', { text: 'Se ha terminado antes de tiempo: quedaron ' + (info.encontradas - pages.length) + ' páginas sin analizar.' }) : null,
        info.tope ? el('li', { text: 'Se ha llegado al máximo de ' + info.tope + ' páginas por análisis: el sitio tiene más.' }) : null,
        info.parado ? el('li', { text: 'Detenido por el servidor: ' + info.parado }) : null,
        agg.bloqueadas || agg.errores ? el('li', { text: 'Sin nota: ' + [agg.bloqueadas ? agg.bloqueadas + ' con acceso bloqueado' : '', agg.errores ? agg.errores + ' que no se han podido descargar' : ''].filter(Boolean).join(' y ') + ' (están en el CSV).' }) : null
      ])
    ]));

    var metaLines = [modo + ': ' + agg.analizadas + ' páginas con nota en ' + duration(seconds) + '.'];
    if (info.cortado) metaLines.push('Se ha terminado antes de tiempo: quedaron ' + (info.encontradas - pages.length) + ' páginas sin analizar.');
    if (info.tope) metaLines.push('Se ha llegado al máximo de ' + info.tope + ' páginas por análisis: el sitio tiene más.');
    if (info.parado) metaLines.push('Detenido por el servidor: ' + info.parado);

    if (home.blocked || agg.analizadas === 0) {
      lastModel = { url: home.url, bloqueado: true, meta: metaLines, mensaje: 'No calculable: el sitio ha bloqueado el acceso automático.' };
      results.appendChild(el('p', { class: 'notice notice--warn', text: 'No calculable: el sitio ha bloqueado el acceso automático. ' + ((home.blocked && home.blocked.message) || '') }));
      addCsv(all);
      results.hidden = false;
      return;
    }

    // Lo que falla o falta, repartido por área (el área a la que más resta cada comprobación)
    var blockOf = i18n.bloque_checks || {};
    var byArea = { seo: [], ia: [], accesibilidad: [] };
    agg.checks.forEach(function (c) {
      if (!(c.fallan > 0 || c.avisos > 0) || c.id === 'C16' || c.id === 'C18') return;
      var a = blockOf[c.id] || 'seo';
      if (byArea[a]) byArea[a].push(checkItem(c));
    });
    // Puntos de todo el sitio: enlaces rotos y sitemap (en la portada), títulos repetidos y página 404
    (home.checks || []).forEach(function (c) {
      if ((c.id !== 'C16' && c.id !== 'C18') || c.aplica === false || (c.estado !== 'NO_CUMPLE' && c.estado !== 'AVISO')) return;
      byArea[blockOf[c.id] || 'seo'].push(siteItem(c.id + ' · ' + c.nombre, c.estado, (c.detalle || '') + ' (se comprueba en la portada)', c.recomendacion));
    });
    var dup = agg.titulos_repetidos;
    if (dup.length) {
      var dupPages = dup.reduce(function (n, g) { return n + g.urls.length; }, 0);
      byArea[EXTRA_AREA.titulos].push(siteItem('Títulos repetidos', 'NO_CUMPLE',
        dupPages + ' de ' + agg.con_titulo + ' páginas comparten title con otra. Por ejemplo: ' + dup.slice(0, 3).map(function (g) {
          return '«' + g.titulo + '» (' + g.urls.slice(0, 3).map(path).join(', ') + (g.urls.length > 3 ? '…' : '') + ')';
        }).join('; ') + '.', 'Escribe un title distinto para cada página, que diga de qué trata.'));
    }
    var c404 = home.sitio.pagina_404;
    if (c404 && (c404.estado === 'NO_CUMPLE' || c404.estado === 'AVISO')) {
      byArea[EXTRA_AREA.error404].push(siteItem('Página de error 404', c404.estado, c404.detalle || '', null));
    }

    // Nota global y tarjetas
    var rel = agg.fiabilidad;
    results.appendChild(el('p', { class: 'rw-global' }, [
      'Nota global del sitio: ', el('strong', { text: fmt(agg.media.global) + ' / 100' }),
      ' · media de las ' + agg.analizadas + ' páginas con nota (peor página: ' + fmt(agg.minima.global) + ').'
    ]));
    AREAS.forEach(function (a) { sortItems(byArea[a]); });
    var cards = el('ul', { class: 'site-cards' }, AREAS.map(function (a) {
      return el('li', {}, [areaCard(a, agg, byArea[a])]);
    }));
    accordion(cards);
    results.appendChild(cards);
    results.appendChild(el('p', { class: 'help', text: 'Abre cada tarjeta para ver lo que falla o falta en esa área, de más a menos páginas afectadas.' }));

    // Revisión manual (CLAUDE.md: señalar siempre qué requiere revisión manual)
    var pi = agg.pistas;
    var manual = [];
    if (pi.alt_paginas) manual.push(pi.alt_total + ' imágenes con un texto alternativo sospechoso en ' + pi.alt_paginas + ' páginas (columna «Alt sospechosos» del CSV)');
    if (pi.legibilidad_paginas) manual.push('legibilidad media del texto ' + fmt(pi.legibilidad_media) + ' de 100 (60 o más es lo recomendable; columna «Legibilidad» del CSV)');
    var manualText = 'Revisión manual (no puntúa): ' + (manual.length ? manual.join('; ') + '. ' : '')
      + 'Una persona debe revisar además la calidad de los textos alternativos, el orden real del teclado, el contraste sobre imágenes y la calidad de los subtítulos.';
    // Panel de notas: fiabilidad y revisión manual, cada una en su línea
    results.appendChild(el('div', { class: 'site-notes' }, [
      el('p', {}, [el('strong', { text: 'Fiabilidad: ' }), 'análisis ' + rel.estado + ', evaluados ' + rel.evaluados + ' de ' + rel.posibles + ' puntos'
        + (rel.sin_datos ? ' (' + rel.sin_datos + ' sin datos, que no restan)' : '') + '. Contraste y Core Web Vitals (PageSpeed) solo se miden en la primera página.']),
      el('p', {}, [el('strong', { text: 'Revisión manual (no puntúa): ' }), (manual.length ? manual.join('; ') + '. ' : '')
        + 'Una persona debe revisar además la calidad de los textos alternativos, el orden real del teclado, el contraste sobre imágenes y la calidad de los subtítulos.'])
    ]));

    lastModel = {
      url: home.url, bloqueado: false, meta: metaLines,
      global: agg.media.global, peor_global: agg.minima.global, analizadas: agg.analizadas,
      areas: AREAS.map(function (a) {
        return { id: a, nombre: areaName(a), nota: agg.media[a], peor: agg.minima[a], puntos: byArea[a].map(function (x) { return x.datos; }) };
      }),
      fiabilidad: 'Fiabilidad: análisis ' + rel.estado + ', evaluados ' + rel.evaluados + ' de ' + rel.posibles + ' puntos'
        + (rel.sin_datos ? ' (' + rel.sin_datos + ' sin datos, que no restan)' : '') + '. Contraste y Core Web Vitals (PageSpeed) solo se miden en la primera página.',
      manual: manualText
    };

    addCsv(all);
    results.hidden = false;
  }

  /** Elemento de la lista de una tarjeta para una comprobación de las páginas. */
  function checkItem(c) {
    var estado = c.fallan > 0 ? 'NO_CUMPLE' : 'AVISO';
    var count = c.fallan > 0
      ? 'No cumple en ' + c.fallan + ' de ' + c.evaluadas + ' páginas' + (c.avisos ? ' (y ' + c.avisos + ' con aviso)' : '')
      : 'Aviso en ' + c.avisos + ' de ' + c.evaluadas + ' páginas';
    var examples = (c.ejemplos || []).slice(0, 3).map(function (x) { return path(x.url); });
    var fix = c.recomendacion || c.recomendacion_aviso || '';
    return itemOf({
      titulo: c.id + ' · ' + c.nombre,
      estado: estado,
      linea: ((i18n.estados && i18n.estados[estado]) || estado) + ' · gravedad ' + String((i18n.severidades && i18n.severidades[c.severidad]) || c.severidad).toLowerCase(),
      texto: count + '.' + (examples.length ? ' Por ejemplo: ' + examples.join(', ') + (c.fallan > examples.length ? '…' : '') + '.' : ''),
      arreglo: fix
    }, [estado === 'NO_CUMPLE' ? 0 : 1, -(c.fallan || c.avisos), SEV_ORDER[c.severidad] === undefined ? 9 : SEV_ORDER[c.severidad]]);
  }

  /** Elemento de una tarjeta a partir de sus datos (los mismos datos van al PDF). */
  function itemOf(d, orden) {
    return {
      orden: orden,
      datos: d,
      node: el('li', { class: 'site-item' }, [
        el('p', { class: 'site-item__name' }, [el('strong', { text: d.titulo }), ' — ' + d.linea]),
        d.texto ? el('p', { text: d.texto }) : null,
        d.arreglo ? el('p', { text: 'Cómo arreglarlo: ' + d.arreglo }) : null
      ])
    };
  }

  /** Elemento de la lista de una tarjeta para un punto de todo el sitio. */
  function siteItem(name, estado, detail, fix) {
    return itemOf({
      titulo: name, estado: estado,
      linea: ((i18n.estados && i18n.estados[estado]) || estado) + ' · todo el sitio',
      texto: detail || '', arreglo: fix || ''
    }, [estado === 'NO_CUMPLE' ? 0 : 1, 0, 9]);
  }

  /** Tarjeta desplegable de un área: nota media, peor página y, al abrirla, lo que falla o falta. */
  function sortItems(items) {
    items.sort(function (x, y) {
      for (var i = 0; i < 3; i++) { if (x.orden[i] !== y.orden[i]) return x.orden[i] - y.orden[i]; }
      return 0;
    });
  }

  function areaCard(area, agg, items) {
    var value = agg.media[area];
    var fails = items.filter(function (x) { return x.orden[0] === 0; }).length;
    var warns = items.length - fails;
    var meter = el('span', { class: 'meter ' + (value >= 90 ? 'is-good' : value >= 50 ? 'is-mid' : 'is-low'), 'aria-hidden': 'true' }, [el('span')]);
    meter.firstChild.style.width = Math.max(0, Math.min(100, value || 0)) + '%';
    var counts = items.length
      ? [fails ? fails + (fails === 1 ? ' fallo' : ' fallos') : '', warns ? warns + (warns === 1 ? ' aviso' : ' avisos') : ''].filter(Boolean).join(' · ')
      : 'Sin errores detectables automáticamente';
    return el('details', { class: 'site-card' }, [
      el('summary', {}, [
        el('span', { class: 'site-card__label', text: areaName(area) }),
        el('span', { class: 'site-card__value' }, [fmt(value), el('span', { class: 'site-card__max', text: ' / 100' })]),
        meter,
        el('span', { class: 'site-card__meta', text: counts + ' · peor página: ' + fmt(agg.minima[area]) }),
        items.length ? el('span', { class: 'site-card__hint', text: 'Ver lo que falla o falta' }) : null
      ]),
      el('div', { class: 'site-card__body' }, items.length
        ? [el('ul', { class: 'site-items' }, items.map(function (x) { return x.node; }))]
        : [el('p', { text: 'Sin errores detectables automáticamente en ' + areaName(area) + '. Revisa también los puntos de revisión manual.' })])
    ]);
  }

  /**
   * Tarjetas en acordeón: solo una abierta. Con una abierta, las otras se compactan arriba y la abierta ocupa
   * todo el ancho debajo (clases has-open / is-open; el orden visual lo pone componentes.css).
   */
  function accordion(list) {
    var items = Array.prototype.slice.call(list.children);
    items.forEach(function (li) {
      var d = li.firstChild;
      d.addEventListener('toggle', function () {
        if (d.open) {
          items.forEach(function (other) {
            if (other !== li && other.firstChild.open) other.firstChild.open = false;
          });
        }
        items.forEach(function (x) { x.classList.toggle('is-open', x.firstChild.open); });
        list.classList.toggle('has-open', items.some(function (x) { return x.firstChild.open; }));
      });
    });
  }

  /** Botón del CSV de todas las páginas, en las descargas del final de la pantalla. */
  function addCsv(all) {
    var host = doc.getElementById('descargas-sitio');
    var box = doc.getElementById('descargas');
    if (!host) return;
    var csvButton = el('button', { type: 'button', class: 'rw-btn', text: 'Todas las páginas (CSV)' });
    csvButton.addEventListener('click', function () { downloadCsv(all); });
    host.textContent = '';
    host.appendChild(el('h3', { text: 'El sitio' }));
    host.appendChild(el('div', { class: 'report-actions rw-context__actions' }, [csvButton]));
    host.appendChild(el('p', { class: 'help', text: 'Una fila por página: notas, estado de cada comprobación y pistas de revisión manual.' }));
    if (box) box.hidden = false;
  }

  /**
   * Historial: manda al servidor qué páginas se han analizado; el servidor junta sus propios resultados,
   * recalcula la media y lo guarda en la misma fila que la URL buscada (opts.enlace).
   */
  function saveHistory(home, launched, opts) {
    var reg = home.sitio && home.sitio.registro;
    if (!reg) return;
    var note = el('p', { class: 'help site-save-note', text: 'Guardando el análisis del sitio en el historial…' });
    results.appendChild(note);
    Promise.resolve(opts.esperar).catch(function () {}).then(function () {
      return post('api/site_record.php', {
        url: home.url, expira: home.sitio.expira, firma: reg.firma, paginas: launched,
        enlace: opts.enlace || null
      });
    }).then(function (r) {
      if (!r.ok || !r.activo) {
        note.textContent = (r.error && r.error.message) || 'El historial no está activado.';
        return;
      }
      note.textContent = 'Guardado en el historial (' + r.paginas + ' páginas con nota) durante ' + r.meses + ' meses, junto con el informe de la URL.'
        + (r.sin_resultado ? ' Aviso: ' + r.sin_resultado + ' página(s) analizadas no se han podido incluir porque el servidor ya no tenía su resultado.' : '');
    }).catch(function () {
      note.textContent = 'No se ha podido guardar el análisis del sitio en el historial.';
    });
  }

  function csvCell(v) {
    var s = v === null || v === undefined ? '' : String(v);
    // Evita que una hoja de cálculo interprete fórmulas
    if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
    return '"' + s.replace(/"/g, '""') + '"';
  }

  /**
   * CSV de todas las páginas, con cabecera corporativa (marca, qué es, sitio y fecha) y columnas en español.
   * Las notas llevan coma decimal para Excel en español; los estados, en palabras.
   */
  function downloadCsv(all) {
    var ids = [];
    all.forEach(function (p) { (p.checks || []).forEach(function (c) { if (ids.indexOf(c.id) < 0) ids.push(c.id); }); });
    ids.sort();
    var num = function (v) { return typeof v === 'number' ? (Math.round(v * 10) / 10).toLocaleString('es-ES') : ''; };
    var state = function (c) { return c.aplica === false ? 'No aplica' : ((i18n.estados && i18n.estados[c.estado]) || c.estado); };
    var site = all[0] ? all[0].url : '';
    var rows = [['Proconsi · Radar web'], ['Páginas analizadas del sitio'], ['Sitio', site],
      ['Descargado', new Date().toLocaleString('es-ES', { dateStyle: 'long', timeStyle: 'short' })],
      ['Evaluación automática y parcial. No sustituye una auditoría manual.'], [],
      ['URL', 'Resultado', 'Nota global', areaName('seo'), areaName('ia'), areaName('accesibilidad'), 'Tiempo (s)', 'Alt sospechosos', 'Legibilidad']
        .concat(ids.map(function (id) { return id + ' ' + (names[id] || ''); }))];
    all.forEach(function (p) {
      var states = {};
      (p.checks || []).forEach(function (c) { states[c.id] = state(c); });
      var n = p.notas || {};
      var pi = p.pistas || {};
      rows.push([p.url, !p.ok ? 'Error al descargar' : p.blocked ? 'Acceso bloqueado' : 'Con nota', num(n.global), num(n.seo), num(n.ia), num(n.accesibilidad),
        typeof p.ms === 'number' ? num(p.ms / 1000) : '', pi.alt_sospechosos === undefined ? '' : pi.alt_sospechosos, num(pi.legibilidad)]
        .concat(ids.map(function (id) { return states[id] || ''; })));
    });
    var text = '\ufeff' + rows.map(function (r) { return r.map(csvCell).join(';'); }).join('\r\n') + '\r\n';
    var href = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
    var host = '';
    try { host = new URL(site).hostname.replace(/^www\./, ''); } catch (e) { host = 'sitio'; }
    var a = el('a', { href: href, download: 'proconsi-radar-sitio-' + host.replace(/[^a-z0-9.\-]/gi, '') + '-' + new Date().toISOString().slice(0, 10) + '.csv' });
    doc.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(href); }, 1000);
  }

  root.RadarSite = { start: start, cancel: cancel, showSaved: showSaved, model: function () { return lastModel; }, aggregate: aggregate, homeAsPage: homeAsPage };
})(typeof window !== 'undefined' ? window : this);
