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
  /** Filas de la tabla de páginas en pantalla (todas van en el CSV) */
  var MAX_ROWS = 200;

  /** Clave para no analizar dos veces la misma página (http/https, www y barra final dan igual). */
  function pageKey(u) {
    return String(u).toLowerCase().replace(/#.*$/, '').replace(/^https?:\/\/(www\.)?/, '').replace(/\/+$/, '');
  }

  /**
   * Agrega los resultados de las páginas (función pura; la prueban tests/js/site_test.js).
   * @param {Array} pages [{ok, url, blocked, ms, checks:[{id,nombre?,estado,severidad,aplica,...}], notas:{seo,ia,accesibilidad,global}}]
   * @param {Object} names (opcional) nombre de cada comprobación por id, para las páginas que no lo traen
   */
  function aggregate(pages, names) {
    names = names || {};
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
        } else if (c.estado === 'AVISO') {
          row.avisos++;
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
    return {
      pistas: pistas,
      analizadas: scored.length,
      bloqueadas: pages.filter(function (p) { return p.ok && p.blocked; }).length,
      errores: pages.filter(function (p) { return !p.ok; }).length,
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
  var perfil = 'general';
  var lastRender = null;   // para volver a pintar al cambiar de departamento

  function ownerName(id) {
    var k = root.RadarFocus ? root.RadarFocus.owner(id, i18n) : '';
    return (i18n.responsables || {})[k] || '';
  }
  function myTeam() {
    return ((i18n.departamentos || {})[perfil] || {}).responsable || null;
  }

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
  function pct(a, b) { return b ? Math.round(a / b * 100) + ' %' : '—'; }
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

    post('api/site.php', { url: url, modo: deep ? 'profundo' : 'rapido' }).then(function (home) {
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
      // Pruebas con Chrome en la portada, a la vez que las páginas (si el servidor las tiene activadas)
      var chrome = home.sitio.chrome
        ? post('api/browser.php', { url: home.sitio.chrome.url, firma: home.sitio.chrome.firma, expira: home.sitio.expira })
          .catch(function () { return { ok: false, error: { message: 'Error de red.' } }; })
        : Promise.resolve(null);
      var launched = [];   // firmas de las páginas lanzadas (el servidor guarda sus resultados para el historial)
      var shouldStop = function () { return stopRequested || runId !== currentRun; };
      var pagesDone = pool(list, home.sitio.paralelas || 6, function (item) {
        launched.push(item.firma);
        var t0 = Date.now();
        return post('api/site_page.php', { url: item.url, run: home.sitio.run, firma: item.firma, expira: home.sitio.expira, descubrir: deep })
          .then(function (r) {
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
      return Promise.all([pagesDone, chrome]).then(function (both) {
        if (runId !== currentRun) return;
        home.chromeResult = both[1];
        progressWrap.hidden = true;
        var seconds = Math.round((Date.now() - started) / 1000);
        var info = { encontradas: list.length, descubiertas: discovered, tope: capped ? maxPages : 0, cortado: stopRequested && done < list.length };
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

  /** v4 · Cambia el departamento y vuelve a pintar el informe del sitio (no se vuelve a analizar). */
  function setPerfil(p) {
    perfil = p || 'general';
    if (lastRender) {
      var saveNote = results.querySelector('.site-save-note');
      render.apply(null, lastRender);
      if (saveNote) results.insertBefore(saveNote, results.querySelector('.downloads'));
    }
  }

  function render(home, pages, info, seconds) {
    lastRender = [home, pages, info, seconds];
    var all = [homeAsPage(home)].concat(pages);
    var agg = aggregate(all, names);
    var siteChecks = (home.checks || []).filter(function (c) { return c.id === 'C16' || c.id === 'C18'; });
    results.textContent = '';

    results.appendChild(el('div', { class: 'report-head' }, [
      el('h2', { id: 'site-results-title', tabindex: '-1', text: home.sitio.modo === 'rapido' ? 'El sitio: páginas principales' : home.sitio.modo === 'profundo' ? 'Todo el sitio' : 'El sitio' }),
      el('ul', { class: 'meta-list' }, [
        el('li', { text: 'Sitio: ' + home.url }),
        home.sitio.modo ? el('li', { text: home.sitio.modo === 'rapido' ? 'Análisis rápido: la portada y las ' + home.sitio.max_paginas + ' páginas principales, repartidas por secciones' : 'Análisis profundo: todas las páginas que se han encontrado' }) : null,
        home.guardado ? el('li', { text: 'Informe guardado del ' + new Date(home.guardado).toLocaleString('es-ES', { dateStyle: 'long', timeStyle: 'short' }) + ' (no se ha vuelto a analizar)' }) : null,
        el('li', { text: 'Páginas analizadas con nota: ' + agg.analizadas + ' de ' + (pages.length + 1) + ' recorridas' }),
        el('li', { text: 'Origen: ' + home.sitio.desde_enlaces + ' enlaces de la portada, ' + home.sitio.desde_sitemap + ' URL de ' + home.sitio.sitemaps_leidos + ' archivo(s) de sitemap' + (info.descubiertas ? ' y ' + info.descubiertas + ' encontradas al recorrer las páginas' : '') }),
        el('li', { text: 'Tiempo total: ' + duration(seconds) }),
        info.cortado ? el('li', { text: 'Se ha terminado antes de tiempo: quedaron ' + (info.encontradas - pages.length) + ' páginas sin analizar.' }) : null,
        info.tope ? el('li', { text: 'Se ha llegado al máximo de ' + info.tope + ' páginas por análisis: el sitio tiene más.' }) : null,
        agg.bloqueadas ? el('li', { text: 'Páginas con acceso bloqueado (no puntúan): ' + agg.bloqueadas }) : null,
        agg.errores ? el('li', { text: 'Páginas que no se han podido descargar (no puntúan): ' + agg.errores }) : null
      ].concat((home.sitio.notas || []).map(function (n) { return el('li', { text: n }); })))
    ]));

    if (home.blocked || agg.analizadas === 0) {
      results.appendChild(el('p', { class: 'notice notice--warn', text: 'No calculable: el sitio ha bloqueado el acceso automático. ' + ((home.blocked && home.blocked.message) || '') }));
      results.hidden = false;
      return;
    }

    // Notas medias
    results.appendChild(el('h3', { text: 'Nota media del sitio' }));
    results.appendChild(el('ul', { class: 'scores' }, AREAS.map(function (a) {
      return scoreCard(areaName(a), agg.media[a], agg.minima[a], false);
    }).concat([scoreCard('Nota global', agg.media.global, agg.minima.global, true)])));
    results.appendChild(el('p', { class: 'help', text: 'Media de las ' + agg.analizadas + ' páginas analizadas. «Peor página» es la nota más baja del sitio.' }));

    // Fallos que se repiten
    results.appendChild(el('h3', { text: 'Fallos por comprobación en todo el sitio' }));
    var failing = agg.checks.filter(function (c) { return (c.fallan > 0 || c.avisos > 0) && c.id !== 'C16' && c.id !== 'C18'; });
    // v4: con un departamento elegido, primero lo que le toca
    var team = myTeam();
    if (team && root.RadarFocus) {
      var mine = function (c) { return root.RadarFocus.owner(c.id, i18n) === team ? 0 : 1; };
      failing = failing.slice().sort(function (a, b) { return mine(a) - mine(b); });
      var own = failing.filter(function (c) { return mine(c) === 0; }).length;
      results.appendChild(el('p', { class: 'help', text: own
        ? 'Primero, las ' + own + ' comprobaciones que suelen corresponder a ' + ((i18n.departamentos[perfil] || {}).nombre || '') + '.'
        : 'Ningún fallo del sitio corresponde normalmente a ' + ((i18n.departamentos[perfil] || {}).nombre || '') + '.' }));
    }
    if (!failing.length) {
      results.appendChild(el('p', { text: 'Sin errores detectables automáticamente en las páginas del sitio.' }));
    } else {
      results.appendChild(el('div', { class: 'table-wrap' }, [el('table', { class: 'data-table' }, [
        el('caption', { class: 'visually-hidden', text: 'Comprobaciones con fallos o avisos en el sitio' }),
        el('thead', {}, [el('tr', {}, ['Comprobación', 'Quién lo arregla', 'Gravedad', 'No cumple', 'Avisos', 'Evaluadas'].map(function (h) { return el('th', { scope: 'col', text: h }); }))]),
        el('tbody', {}, failing.map(function (c) {
          return el('tr', {}, [
            el('th', { scope: 'row', text: c.id + ' · ' + c.nombre }),
            el('td', { text: ownerName(c.id) }),
            el('td', { text: (i18n.severidades && i18n.severidades[c.severidad]) || c.severidad }),
            el('td', { class: 'num', text: c.fallan + ' (' + pct(c.fallan, c.evaluadas) + ')' }),
            el('td', { class: 'num', text: String(c.avisos) }),
            el('td', { class: 'num', text: String(c.evaluadas) })
          ]);
        }))
      ])]));
      failing.filter(function (c) { return c.fallan > 0; }).forEach(function (c) {
        results.appendChild(el('details', { class: 'fold' }, [
          el('summary', {}, [el('span', { class: 'fold__title', text: c.id + ' · ' + c.nombre + ': falla en ' + c.fallan + ' de ' + c.evaluadas + ' páginas' })]),
          el('div', { class: 'fold__body' }, [
            (perfil === 'marketing' || perfil === 'direccion' || perfil === 'diseno') && (i18n.enfoque || {})[c.id]
              ? el('p', { text: 'Por qué importa: ' + i18n.enfoque[c.id][1] }) : null,
            c.recomendacion ? el('p', { text: 'Recomendación: ' + c.recomendacion }) : null,
            el('ul', {}, c.ejemplos.map(function (x) { return el('li', {}, [el('a', { href: x.url, rel: 'noopener noreferrer', text: path(x.url) }), ': ' + x.detalle]); })),
            c.fallan > c.ejemplos.length ? el('p', { class: 'help', text: 'Se muestran ' + c.ejemplos.length + ' ejemplos; la lista completa está en el CSV.' }) : null
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
      dupTitles.length ? el('ul', {}, dupTitles.slice(0, 10).map(function (g) {
        var shown = g.urls.slice(0, 5).map(path).join(', ');
        return el('li', { text: '«' + g.titulo + '»: ' + shown + (g.urls.length > 5 ? ' y ' + (g.urls.length - 5) + ' más' : '') });
      })) : null
    ]));
    results.appendChild(el('h3', { text: 'Comprobaciones de todo el sitio' }));
    results.appendChild(el('ul', {}, siteItems));
    results.appendChild(el('p', { class: 'help', text: 'Enlaces rotos y sitemap se miran en la portada; la página 404 con una dirección inventada; los títulos, entre todas las páginas. No cambian la nota media.' }));

    // v4 · Pistas para la revisión manual (sin IA, no puntúan)
    var pi = agg.pistas;
    if (pi.alt_paginas || pi.legibilidad_paginas) {
      var motivos = i18n.motivos_alt || {};
      results.appendChild(el('h3', { text: 'Pistas para la revisión manual' }));
      results.appendChild(el('p', { class: 'help', text: 'Reglas automáticas, sin IA: dicen por dónde empezar a revisar, no si está bien. No cambian la nota.' }));
      results.appendChild(el('h4', { text: 'Textos alternativos sospechosos (RM-01)' }));
      results.appendChild(pi.alt_paginas
        ? el('p', { text: pi.alt_total + ' imágenes con un texto alternativo sospechoso (nombre de archivo, palabra genérica, repetido…) en ' + pi.alt_paginas + ' páginas. Las que más tienen:' })
        : el('p', { text: 'Ninguna página tiene textos alternativos con pinta de nombre de archivo, genéricos o repetidos. Falta ver si describen bien cada imagen.' }));
      if (pi.alt_paginas) {
        results.appendChild(el('ul', {}, pi.alt_peores.map(function (p) {
          var ex = (p.pistas.alt_ejemplos || []).map(function (x) { return '«' + x.alt + '» (' + String(motivos[x.motivo] || x.motivo).toLowerCase() + ')'; }).join(', ');
          return el('li', {}, [el('a', { href: p.url, rel: 'noopener noreferrer', text: path(p.url) }), ': ' + p.pistas.alt_sospechosos + (ex ? '. Por ejemplo: ' + ex : '')]);
        })));
      }
      if (pi.legibilidad_paginas) {
        var lvl = function (v) { return v >= 90 ? 'muy fácil' : v >= 80 ? 'fácil' : v >= 70 ? 'bastante fácil' : v >= 60 ? 'normal' : v >= 50 ? 'bastante difícil' : v >= 30 ? 'difícil' : 'muy difícil'; };
        results.appendChild(el('h4', { text: 'Legibilidad del contenido (RM-09)' }));
        results.appendChild(el('p', { text: 'Media del sitio: ' + fmt(pi.legibilidad_media) + ' de 100 (' + lvl(pi.legibilidad_media) + '), en ' + pi.legibilidad_paginas + ' páginas con texto suficiente. Las más difíciles de leer:' }));
        results.appendChild(el('ul', {}, pi.mas_dificiles.slice(0, 5).map(function (p) {
          return el('li', {}, [el('a', { href: p.url, rel: 'noopener noreferrer', text: path(p.url) }), ': ' + fmt(p.pistas.legibilidad) + ' (' + lvl(p.pistas.legibilidad) + ')']);
        })));
        results.appendChild(el('p', { class: 'help', text: 'Índice de Fernández-Huerta: 60 o más es lo recomendable para una web general. Mide frases y palabras largas, no la calidad del contenido.' }));
      }
    }

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

    // PageSpeed (solo en informes antiguos de «Analizar sitio»; ahora va en el informe de la URL)
    if (home.psiResult && home.psiResult.ok && home.psiResult.checks) {
      results.appendChild(el('h3', { text: 'PageSpeed en la portada (contraste y Core Web Vitals)' }));
      results.appendChild(el('ul', {}, home.psiResult.checks.map(function (c) {
        var estado = c.aplica === false ? 'No aplica' : ((i18n.estados && i18n.estados[c.estado]) || c.estado);
        return el('li', {}, [el('strong', { text: c.id + ' · ' + c.nombre + ': ' + estado + '.' }), ' ' + (c.detalle || '')]);
      })));
    }

    // Páginas, de peor a mejor
    results.appendChild(el('h3', { text: 'Páginas analizadas (de peor a mejor)' }));
    var rows = agg.peores.slice(0, MAX_ROWS);
    results.appendChild(el('div', { class: 'table-wrap' }, [el('table', { class: 'data-table' }, [
      el('caption', { class: 'visually-hidden', text: 'Notas de cada página analizada' }),
      el('thead', {}, [el('tr', {}, ['Página', 'Global', areaName('seo'), areaName('ia'), areaName('accesibilidad'), 'Tiempo'].map(function (h) { return el('th', { scope: 'col', text: h }); }))]),
      el('tbody', {}, rows.map(function (p) {
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
    if (agg.peores.length > rows.length) {
      results.appendChild(el('p', { class: 'help', text: 'Se muestran las ' + rows.length + ' páginas con peor nota de ' + agg.peores.length + '. Todas están en el CSV.' }));
    }

    var problems = all.filter(function (p) { return !p.ok || p.blocked; });
    if (problems.length) {
      results.appendChild(el('h3', { text: 'Páginas sin nota (' + problems.length + ')' }));
      results.appendChild(el('ul', {}, problems.slice(0, MAX_ROWS).map(function (p) {
        return el('li', { text: path(p.url) + ': ' + (p.blocked ? (p.blocked.message || 'acceso bloqueado') : ((p.error && p.error.message) || 'error')) });
      })));
    }

    var csvButton = el('button', { type: 'button', class: 'button button--secondary', text: 'Descargar todas las páginas (CSV)' });
    csvButton.addEventListener('click', function () { downloadCsv(all); });
    results.appendChild(el('section', { class: 'downloads no-print', 'aria-labelledby': 'site-downloads-title' }, [
      el('h3', { id: 'site-downloads-title', text: 'Descargas del sitio' }),
      el('div', { class: 'report-actions' }, [csvButton]),
      el('p', { class: 'help', text: 'El CSV se genera en tu navegador; usa «;» y UTF-8 para abrirlo en Excel en español.' })
    ]));

    results.hidden = false;
  }

  /**
   * Historial: manda al servidor qué páginas se han analizado; el servidor junta sus propios resultados,
   * recalcula la media y lo guarda en la misma fila que la URL buscada (opts.enlace).
   */
  function saveHistory(home, launched, opts) {
    var reg = home.sitio && home.sitio.registro;
    if (!reg) return;
    var note = el('p', { class: 'help site-save-note', text: 'Guardando el análisis del sitio en el historial…' });
    var downloads = results.querySelector('.downloads');
    results.insertBefore(note, downloads || null);
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
      note.textContent = 'Guardado en el historial (' + r.paginas + ' páginas con nota) durante ' + r.meses + ' meses, junto con el informe de la URL.';
    }).catch(function () {
      note.textContent = 'No se ha podido guardar el análisis del sitio en el historial.';
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
    var rows = [['url', 'estado', 'global', 'seo', 'geo', 'ux', 'ms', 'alt_sospechosos', 'legibilidad'].concat(ids)];
    all.forEach(function (p) {
      var states = {};
      (p.checks || []).forEach(function (c) { states[c.id] = c.aplica === false ? 'NO_APLICA' : c.estado; });
      var n = p.notas || {};
      var pi = p.pistas || {};
      rows.push([p.url, !p.ok ? 'error' : p.blocked ? 'bloqueada' : 'ok', n.global, n.seo, n.ia, n.accesibilidad, p.ms, pi.alt_sospechosos, pi.legibilidad]
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

  root.RadarSite = { start: start, cancel: cancel, showSaved: showSaved, setPerfil: setPerfil, aggregate: aggregate, homeAsPage: homeAsPage };
})(typeof window !== 'undefined' ? window : this);
