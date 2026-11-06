/* Analizador web — lógica de la pantalla. Sin librerías.
 * Todo dato procedente del servidor o de la web analizada se inserta con textContent, nunca con innerHTML.
 *
 * Flujo: api/analyze.php y api/psi.php se piden en paralelo. Se pinta el informe en cuanto llega el
 * análisis, con puntuación provisional; cuando llega PageSpeed (C08, C14) se recalculan puntuación e
 * informe en el servidor (api/score.php, mismo Scorer y mismo Report) y se actualiza sin mover el foco.
 *
 * Informe (diez apartados): 1 resumen ejecutivo, 2 puntuaciones, 3 problemas críticos, 4 hallazgos
 * detallados con evidencia, 5 recomendaciones priorizadas, 6 plan de acción (matriz 2×2), 7 análisis
 * complementario (vistas informativas que no puntúan), 8 glosario, 9 limitaciones (texto fijo de
 * index.php, siempre visible) y 10 evidencias del análisis. */
(function () {
  'use strict';

  var i18n = JSON.parse(document.getElementById('i18n').textContent);
  var form = document.getElementById('analyze-form');
  var input = document.getElementById('url');
  var noindex = document.getElementById('noindex');
  var keywordInput = document.getElementById('keyword');
  var button = document.getElementById('submit');
  var statusEl = document.getElementById('status');
  var errorEl = document.getElementById('error');
  var urlError = document.getElementById('url-error');
  var results = document.getElementById('results');
  var evidenceEl = document.getElementById('evidencias');

  var REQUEST_TIMEOUT_MS = 60000;
  var PSI_TIMEOUT_MS = 100000;
  var SEVERITY_ORDER = { CRITICO: 0, ALTO: 1, MEDIO: 2, BAJO: 3 };
  var LEVEL_ORDER = { ALTO: 0, MEDIO: 1, BAJO: 2 };
  var PSI_IDS = Object.keys(i18n.psi_checks || {});
  // Apartados del informe, en orden (ids de las secciones)
  var SECTIONS = [
    ['sec-resumen', 'Resumen ejecutivo'],
    ['sec-puntuaciones', 'Puntuaciones por área y global'],
    ['sec-criticos', 'Problemas críticos'],
    ['sec-hallazgos', 'Hallazgos detallados con evidencia'],
    ['sec-recomendaciones', 'Recomendaciones priorizadas'],
    ['sec-plan', 'Plan de acción por impacto y esfuerzo'],
    ['sec-complementario', 'Análisis complementario (no puntúa)'],
    ['sec-glosario', 'Glosario'],
    ['limitaciones', 'Limitaciones'],
    ['evidencias', 'Evidencias del análisis']
  ];
  // Errores que se deben a la URL escrita: se señalan en el propio campo
  var FIELD_ERRORS = ['INVALID_URL', 'SCHEME_NOT_ALLOWED', 'CREDENTIALS_IN_URL', 'PORT_NOT_ALLOWED', 'INVALID_HOST', 'PRIVATE_ADDRESS'];
  var ERROR_HINTS = {
    TIMEOUT: 'La web no ha respondido a tiempo. Puede estar caída o ser muy lenta; inténtalo de nuevo más tarde.',
    REDIRECT_LOOP: 'La URL redirige en bucle y nunca llega a una página. Revisa la configuración de redirecciones del sitio.',
    TOO_MANY_REDIRECTS: 'La URL encadena más de 5 redirecciones. Analiza directamente la dirección final o revisa las redirecciones.',
    BAD_REDIRECT: 'El servidor indica una redirección sin destino.',
    DNS_FAILED: 'No se encuentra el dominio. Comprueba que está bien escrito.',
    SSL_ERROR: 'El certificado de seguridad del sitio no es válido o la conexión segura ha fallado.',
    CONNECTION_FAILED: 'No se ha podido conectar con el servidor de la web.',
    RATE_LIMITED: '',
    INTERNAL_ERROR: 'Se ha producido un error en el analizador. Si se repite, avisa al equipo responsable.'
  };

  // Análisis en curso (uno solo: uno nuevo cancela el anterior)
  var current = null;

  // ---------- Utilidades DOM ----------

  /** Crea un elemento. attrs: class, text y cualquier otro atributo. children: nodos o textos. */
  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') node.className = v;
      else if (k === 'text') node.textContent = String(v);
      else node.setAttribute(k, v === true ? '' : String(v));
    });
    (children || []).forEach(function (c) {
      if (c === null || c === undefined) return;
      node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return node;
  }

  function fmtNumber(n, decimals) {
    return Number(n).toLocaleString('es-ES', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
  }

  function fmtDate(iso) {
    var d = new Date(iso);
    return isNaN(d.getTime()) ? '—' : d.toLocaleString('es-ES', { dateStyle: 'long', timeStyle: 'short' });
  }

  function lower(map, key) {
    return String((map && map[key]) || key || '').toLowerCase();
  }

  function prettyKey(k) {
    var s = String(k).replace(/_/g, ' ');
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  /** Representa un valor medido (escalar, lista u objeto) como nodo. */
  function fmtValue(v) {
    if (v === null || v === undefined || v === '') return document.createTextNode('—');
    if (typeof v === 'boolean') return document.createTextNode(v ? 'sí' : 'no');
    if (typeof v === 'number') return document.createTextNode(fmtNumber(v, Number.isInteger(v) ? 0 : 2));
    if (typeof v === 'string') return document.createTextNode(v);
    if (Array.isArray(v)) {
      if (!v.length) return document.createTextNode('—');
      if (v.every(function (x) { return typeof x !== 'object' || x === null; })) {
        return document.createTextNode(v.join(', '));
      }
      return el('ul', {}, v.map(function (x) { return el('li', {}, [fmtValue(x)]); }));
    }
    return el('ul', {}, Object.keys(v).map(function (k) {
      return el('li', {}, [prettyKey(k) + ': ', fmtValue(v[k])]);
    }));
  }

  /** Lista de definiciones a partir de pares [término, nodo o texto]. */
  function defList(cls, rows) {
    var dl = el('dl', { class: cls });
    rows.forEach(function (r) {
      if (!r) return;
      dl.appendChild(el('dt', { text: r[0] }));
      dl.appendChild(el('dd', {}, [typeof r[1] === 'string' ? document.createTextNode(r[1]) : r[1]]));
    });
    return dl;
  }

  // ---------- Estados de la pantalla ----------

  function setStatus(text, loading) {
    statusEl.classList.toggle('is-loading', !!loading);
    statusEl.textContent = text || '';
  }

  function setLoading(on) {
    button.disabled = on;
    results.setAttribute('aria-busy', on ? 'true' : 'false');
    setStatus(on ? 'Analizando la página, sus enlaces y su sitemap… puede tardar hasta medio minuto.' : '', on);
  }

  function clearErrors() {
    errorEl.hidden = true;
    errorEl.textContent = '';
    urlError.hidden = true;
    urlError.textContent = '';
    input.removeAttribute('aria-invalid');
  }

  function showFieldError(message) {
    urlError.textContent = message;
    urlError.hidden = false;
    input.setAttribute('aria-invalid', 'true');
    input.focus();
  }

  function showError(message, hint) {
    errorEl.textContent = '';
    errorEl.appendChild(el('p', { text: message }));
    if (hint) errorEl.appendChild(el('p', { text: hint }));
    errorEl.hidden = false;
    errorEl.focus();
  }

  function handleApiError(err) {
    var code = (err && err.code) || 'UNKNOWN';
    var message = (err && err.message) || 'No se ha podido completar el análisis.';
    if (FIELD_ERRORS.indexOf(code) !== -1) {
      showFieldError(message);
      return;
    }
    showError(message, ERROR_HINTS[code] || '');
  }

  // ---------- Peticiones ----------

  /** POST JSON con tiempo máximo. Resuelve con {status, data}; rechaza con AbortError o error de red/JSON. */
  function postJson(url, body, controller, timeoutMs) {
    var timer = setTimeout(function () { controller.abort(); }, timeoutMs);
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body),
      signal: controller.signal,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().then(function (data) {
        return { status: res.status, data: data };
      }, function () {
        throw new Error('Respuesta no válida del servidor (HTTP ' + res.status + ').');
      });
    }).finally(function () {
      clearTimeout(timer);
    });
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    clearErrors();
    var url = input.value.trim();
    if (!url) {
      showFieldError('Escribe la URL de la página que quieres analizar.');
      return;
    }
    analyze(url, noindex.checked, keywordInput.value.trim());
  });

  function analyze(url, noindexIntentional, keyword) {
    if (current) current.cancel();
    results.hidden = true;
    results.textContent = '';
    evidenceEl.hidden = true;
    evidenceEl.textContent = '';
    setLoading(true);

    var run = {
      analyzeCtrl: new AbortController(),
      psiCtrl: new AbortController(),
      data: null,          // respuesta de analyze.php (checks, puntuacion e informe se sustituyen al llegar PageSpeed)
      psi: null,           // respuesta de psi.php (ok: true)
      psiFailure: null,    // mensaje si psi.php no se pudo usar
      psiDone: false,
      view: null,          // contenedores de los apartados que se actualizan
      cancel: function () { run.analyzeCtrl.abort(); run.psiCtrl.abort(); }
    };
    current = run;

    // PageSpeed en paralelo
    postJson('api/psi.php', { url: url }, run.psiCtrl, PSI_TIMEOUT_MS).then(function (r) {
      if (r.data && r.data.ok) run.psi = r.data;
      else run.psiFailure = (r.data && r.data.error && r.data.error.message) || 'Error al consultar PageSpeed Insights.';
    }, function (e) {
      run.psiFailure = e && e.name === 'AbortError'
        ? 'PageSpeed Insights no respondió a tiempo.'
        : 'No se ha podido contactar con el servidor del analizador para consultar PageSpeed Insights.';
    }).then(function () {
      if (run !== current) return;
      run.psiDone = true;
      if (run.data) finishPsi(run);
    });

    postJson('api/analyze.php', { url: url, noindex_intentional: noindexIntentional, palabra_clave: keyword }, run.analyzeCtrl, REQUEST_TIMEOUT_MS).then(function (r) {
      if (run !== current) return;
      setLoading(false);
      if (!r.data || !r.data.ok) {
        run.psiCtrl.abort();
        current = null;
        handleApiError(r.data && r.data.error);
        return;
      }
      run.data = r.data;
      renderResults(run);
      if (run.psiDone) finishPsi(run);
      else setStatus('Análisis del HTML completado. Consultando PageSpeed Insights (contraste y Core Web Vitals)… puede tardar hasta un minuto.', true);
    }, function (e) {
      if (run !== current) return;
      setLoading(false);
      run.psiCtrl.abort();
      current = null;
      if (e && e.name === 'AbortError') {
        showError('El análisis ha tardado demasiado y se ha cancelado.', ERROR_HINTS.TIMEOUT);
      } else if (e && /Respuesta no válida/.test(e.message)) {
        showError(e.message, ERROR_HINTS.INTERNAL_ERROR);
      } else {
        showError('No se ha podido contactar con el servidor del analizador.', 'Comprueba tu conexión e inténtalo de nuevo.');
      }
    });
  }

  /** Incorpora C08 y C14 y recalcula puntuación e informe en el servidor. */
  function finishPsi(run) {
    if (!run.psi) {
      updateReport(run);
      setStatus('Análisis completado. ' + run.psiFailure + ' La puntuación no incluye contraste ni Core Web Vitals.', false);
      current = null;
      return;
    }
    var merged = run.data.checks.filter(function (c) { return PSI_IDS.indexOf(c.id) === -1; }).concat(run.psi.checks);
    var payload = merged.map(function (c) {
      return { id: c.id, estado: c.estado, ocurrencias: c.ocurrencias || 0, aplica: c.aplica !== false };
    });
    postJson('api/score.php', { checks: payload, bloqueado: !!run.data.blocked }, new AbortController(), REQUEST_TIMEOUT_MS).then(function (r) {
      if (run !== current) return;
      if (!r.data || !r.data.ok || !r.data.informe) throw new Error('score');
      run.data.checks = merged;
      run.data.puntuacion = r.data.puntuacion;
      run.data.informe = r.data.informe;
      run.psiMerged = true;
      updateReport(run);
      setStatus(run.psi.psi && run.psi.psi.ok
        ? 'Análisis completado. Puntuación e informe actualizados con los datos de PageSpeed Insights.'
        : 'Análisis completado. PageSpeed Insights no devolvió datos: contraste y Core Web Vitals figuran como «Sin datos».', false);
      current = null;
    }).catch(function () {
      if (run !== current) return;
      run.psiFailure = 'No se pudo recalcular la puntuación con los datos de PageSpeed Insights.';
      run.psi = null;
      updateReport(run);
      setStatus('Análisis completado. ' + run.psiFailure + ' Se mantiene la puntuación sin contraste ni Core Web Vitals.', false);
      current = null;
    });
  }

  // ---------- Informe ----------

  function reportSection(id, title) {
    var body = el('div');
    var node = el('section', { id: id, class: 'report-section', 'aria-labelledby': id + '-t' }, [
      el('h3', { id: id + '-t', text: title }),
      body
    ]);
    return { node: node, body: body };
  }

  function renderResults(run) {
    var data = run.data;
    var title = el('h2', { id: 'results-title', tabindex: '-1', text: 'Informe' });
    var printButton = el('button', { type: 'button', class: 'button button--secondary no-print', text: 'Imprimir o guardar como PDF' });
    printButton.addEventListener('click', function () { window.print(); });
    // 08. Exportación: se genera en el navegador con los datos actuales (tras PageSpeed, ya actualizados)
    var csvButton = el('button', { type: 'button', class: 'button button--secondary no-print', text: 'Descargar CSV' });
    csvButton.addEventListener('click', function () {
      var X = window.AnalyzerExport;
      X.download(X.fileName(run.data.url, new Date(), 'csv'), 'text/csv;charset=utf-8', X.toCsv(X.buildRows(run.data.checks, i18n)));
    });
    var jsonButton = el('button', { type: 'button', class: 'button button--secondary no-print', text: 'Descargar JSON' });
    jsonButton.addEventListener('click', function () {
      var X = window.AnalyzerExport;
      X.download(X.fileName(run.data.url, new Date(), 'json'), 'application/json;charset=utf-8',
        X.buildJson(run.data, { provisional: psiPending(run) && !run.data.blocked }));
    });

    results.appendChild(el('div', { class: 'report-head' }, [
      title,
      el('ul', { class: 'meta-list' }, [
        el('li', {}, ['URL analizada: ', el('strong', { text: data.url })]),
        el('li', { text: 'Fecha del análisis: ' + fmtDate(data.fecha) })
      ]),
      el('div', { class: 'report-actions no-print' }, [printButton, csvButton, jsonButton]),
      el('p', { class: 'help no-print', text: 'Los archivos se generan en tu navegador; no se guarda nada en el servidor. El CSV usa «;» y UTF-8 para abrirlo en Excel en español.' })
    ]));

    results.appendChild(el('nav', { class: 'report-toc', 'aria-labelledby': 'toc-title' }, [
      el('h3', { id: 'toc-title', class: 'report-toc__title', text: 'Contenido del informe' }),
      el('ol', {}, SECTIONS.map(function (s) { return el('li', {}, [el('a', { href: '#' + s[0], text: s[1] })]); }))
    ]));

    if (data.blocked) {
      results.appendChild(el('div', { class: 'notice notice--warn' }, [
        el('p', {}, [el('strong', { text: 'Acceso bloqueado. ' }), data.blocked.message]),
        el('p', { text: 'Las comprobaciones que dependen del contenido de la página aparecen como «Sin datos».' })
      ]));
    }

    var keys = ['summary', 'scores', 'critical', 'findings', 'recs', 'plan'];
    run.view = {};
    keys.forEach(function (k, i) {
      var s = reportSection(SECTIONS[i][0], SECTIONS[i][1]);
      run.view[k] = s.body;
      results.appendChild(s.node);
    });
    // El análisis complementario se pinta una sola vez: no cambia al llegar PageSpeed
    var extra = reportSection(SECTIONS[6][0], SECTIONS[6][1]);
    renderComplementary(extra.body, data.complementario);
    results.appendChild(extra.node);
    var glossary = reportSection(SECTIONS[7][0], SECTIONS[7][1]);
    glossary.body.appendChild(renderGlossary());
    results.appendChild(glossary.node);

    updateReport(run);

    results.hidden = false;
    results.setAttribute('aria-busy', 'false');
    title.focus();
  }

  function updateReport(run) {
    updateSummary(run);
    updateScores(run);
    updateCritical(run);
    updateFindings(run);
    updateRecommendations(run);
    updatePlan(run);
    updateEvidence(run);
  }

  function psiPending(run) {
    return !run.psiMerged && !run.psiFailure;
  }

  /** Nota cuando la puntuación no incluye C08 y C14. */
  function psiScoreNote(run) {
    if (run.psiMerged || run.data.blocked) return null;
    return psiPending(run)
      ? 'Puntuación provisional: falta incorporar contraste (C08) y Core Web Vitals (C14), que se están consultando a PageSpeed Insights.'
      : 'La puntuación no incluye contraste (C08) ni Core Web Vitals (C14) porque PageSpeed Insights no estaba disponible.';
  }

  function checkById(run, id) {
    for (var i = 0; i < run.data.checks.length; i++) {
      if (run.data.checks[i].id === id) return run.data.checks[i];
    }
    return null;
  }

  function priorityIndex(run) {
    var out = {};
    (run.data.informe.prioridades || []).forEach(function (p) { out[p.id] = p; });
    return out;
  }

  /** Enlace al hallazgo detallado (abre el desplegable al seguirlo). */
  function findingLink(id, name) {
    return el('a', { href: '#hallazgo-' + id, 'data-finding': id, text: name || id });
  }

  // 1. Resumen ejecutivo
  function updateSummary(run) {
    var host = run.view.summary;
    var p = run.data.puntuacion;
    host.textContent = '';
    if (p.riesgo_alto) {
      host.appendChild(el('div', { class: 'notice notice--risk' }, [
        el('p', { text: p.riesgo_mensaje }),
        el('ul', {}, p.riesgo_motivos.map(function (m) { return el('li', { text: m }); }))
      ]));
    }
    (run.data.informe.resumen || []).forEach(function (t) { host.appendChild(el('p', { text: t })); });
    var note = psiScoreNote(run);
    if (note) host.appendChild(el('p', { class: 'help', text: note }));
  }

  // 2. Puntuaciones
  function updateScores(run) {
    var p = run.data.puntuacion;
    var blocked = !!run.data.blocked;
    var host = run.view.scores;
    host.textContent = '';
    var note = psiScoreNote(run);
    if (note) host.appendChild(el('p', { class: 'help', text: note }));
    host.appendChild(el('ul', { class: 'scores' }, [
      scoreCard(i18n.areas.seo, p.areas.seo, false, blocked),
      scoreCard(i18n.areas.ia, p.areas.ia, false, blocked),
      scoreCard(i18n.areas.accesibilidad, p.areas.accesibilidad, false, blocked),
      scoreCard('Nota global', p.global, true, blocked)
    ]));
    var incomplete = !blocked && ['seo', 'ia', 'accesibilidad'].some(function (a) { return p.areas[a].incompleta; });
    if (incomplete) host.appendChild(el('p', { class: 'help', text: i18n.nota_incompleta }));
    var sim = renderSimulation(run.data.informe.simulacion);
    if (sim) host.appendChild(sim);
  }

  /** Simulador de mejora: estimación «antes / después» si se corrigen los N fallos que más restan. */
  function renderSimulation(s) {
    if (!s) return null;
    var box = el('section', { class: 'subsection', 'aria-labelledby': 'sim-title' }, [
      el('h4', { id: 'sim-title', text: 'Simulación de mejora (estimación)' })
    ]);
    if (!s.corregidos.length) {
      box.appendChild(el('p', { text: 'No hay fallos que simular: ninguna comprobación resta puntos.' }));
      return box;
    }
    var names = s.corregidos.map(function (c) { return c.nombre; });
    var lead = s.corregidos.length === 1
      ? 'Si se corrigiera el fallo que más puntos resta (' + names[0] + ')'
      : 'Si se corrigieran los ' + s.corregidos.length + ' fallos que más puntos restan (' + joinList(names) + ')';
    if (s.fallos_totales > s.corregidos.length) lead += ', de ' + s.fallos_totales + ' fallos detectados';
    box.appendChild(el('p', { text: lead + ', las notas quedarían así:' }));

    var rows = [['seo', i18n.areas.seo], ['ia', i18n.areas.ia], ['accesibilidad', i18n.areas.accesibilidad], ['global', 'Nota global']];
    box.appendChild(el('div', { class: 'table-wrap' }, [el('table', { class: 'data-table' }, [
      el('caption', { class: 'visually-hidden', text: 'Notas antes y después de la corrección simulada' }),
      el('thead', {}, [el('tr', {}, [
        el('th', { scope: 'col', text: 'Área' }),
        el('th', { scope: 'col', class: 'num', text: 'Antes' }),
        el('th', { scope: 'col', class: 'num', text: 'Después' }),
        el('th', { scope: 'col', class: 'num', text: 'Diferencia' })
      ])]),
      el('tbody', {}, rows.map(function (r) {
        var n = s.notas[r[0]];
        return el('tr', { class: r[0] === 'global' ? 'is-total' : null }, [
          el('th', { scope: 'row', text: r[1] }),
          el('td', { class: 'num', text: fmtNumber(n.antes, 1) }),
          el('td', { class: 'num', text: fmtNumber(n.despues, 1) }),
          el('td', { class: 'num', text: (n.mejora > 0 ? '+' : '') + fmtNumber(n.mejora, 1) })
        ]);
      }))
    ])]));
    if (s.riesgo_alto.antes && !s.riesgo_alto.despues) {
      box.appendChild(el('p', { text: 'Con esas correcciones desaparecería el aviso de riesgo alto.' }));
    }
    box.appendChild(el('p', { class: 'help', text: 'Es una estimación, no una medición: supone que cada fallo se corrige por completo y que no aparecen otros. Los avisos y las comprobaciones sin datos no cambian.' }));
    return box;
  }

  /** «a, b y c» */
  function joinList(items) {
    if (items.length <= 1) return items.join('');
    return items.slice(0, -1).join(', ') + ' y ' + items[items.length - 1];
  }

  function scoreCard(label, score, isGlobal, blocked) {
    var cls = 'score' + (isGlobal ? ' score--global' : '');
    // Con acceso bloqueado no se ha evaluado el contenido: no se muestra una cifra que parezca un resultado
    if (blocked) {
      return el('li', { class: cls }, [
        el('p', { class: 'score__label', text: label }),
        el('p', { class: 'score__value' }, ['—', el('span', { class: 'score__max', text: ' sin datos' })]),
        el('p', { class: 'tag', text: 'No calculable: acceso bloqueado' })
      ]);
    }
    var value = score.redondeada;
    var meter = el('div', { class: 'meter ' + (value >= 90 ? 'is-good' : value >= 50 ? 'is-mid' : 'is-low'), 'aria-hidden': 'true' }, [el('span')]);
    meter.firstChild.style.width = Math.max(0, Math.min(100, value)) + '%';   // CSSOM: compatible con la CSP
    return el('li', { class: cls }, [
      el('p', { class: 'score__label', text: label }),
      el('p', { class: 'score__value' }, [fmtNumber(value, 1), el('span', { class: 'score__max', text: ' / 100' })]),
      meter,
      score.incompleta ? el('p', { class: 'tag', text: 'Nota incompleta', title: i18n.nota_incompleta }) : null
    ]);
  }

  // 3. Problemas críticos
  function updateCritical(run) {
    var host = run.view.critical;
    var ids = run.data.informe.criticos || [];
    host.textContent = '';
    if (!ids.length) {
      host.appendChild(el('p', { text: 'No se han detectado fallos de gravedad crítica de forma automática.' }));
      return;
    }
    host.appendChild(el('p', { class: 'help', text: 'Fallos de gravedad crítica: cada uno resta 15 puntos, más si se repite. Los de gravedad alta figuran en las recomendaciones priorizadas.' }));
    host.appendChild(el('ul', { class: 'critical-list' }, ids.map(function (id) {
      var c = checkById(run, id) || { id: id };
      return el('li', {}, [
        el('p', { class: 'critical__name' }, [findingLink(id, c.nombre), el('span', { class: 'finding__meta', text: ' ' + id })]),
        c.detalle ? el('p', { class: 'critical__detail', text: c.detalle }) : null
      ]);
    })));
  }

  // 4. Hallazgos detallados (lo medido; las recomendaciones van en el apartado 5)
  /** Repinta los hallazgos conservando qué desplegables estaban abiertos y el foco. */
  function updateFindings(run) {
    var host = run.view.findings;
    var open = {};
    Array.prototype.forEach.call(host.querySelectorAll('details[open]'), function (d) { open[d.getAttribute('data-check')] = true; });
    var active = document.activeElement;
    var focusedCheck = active && host.contains(active) && active.closest('details') ? active.closest('details').getAttribute('data-check') : null;

    host.textContent = '';
    renderFindings(host, run.data.checks, psiPending(run), priorityIndex(run));

    Array.prototype.forEach.call(host.querySelectorAll('details'), function (d) {
      if (open[d.getAttribute('data-check')]) d.open = true;
    });
    if (focusedCheck) {
      var target = host.querySelector('details[data-check="' + focusedCheck + '"] > summary');
      if (target) target.focus();
    }
  }

  function bySeverity(a, b) {
    return (SEVERITY_ORDER[a.severidad] - SEVERITY_ORDER[b.severidad])
      || (LEVEL_ORDER[a.impacto] - LEVEL_ORDER[b.impacto])
      || String(a.id).localeCompare(String(b.id));
  }

  function renderFindings(host, checks, pending, priorities) {
    var groups = [
      { id: 'g-fail', title: 'Fallos', items: [] },
      { id: 'g-warn', title: 'Avisos', items: [] },
      { id: 'g-nodata', title: 'Sin datos', items: [] },
      { id: 'g-na', title: 'No aplica', items: [] },
      { id: 'g-ok', title: 'Sin errores detectables automáticamente', items: [] }
    ];
    checks.forEach(function (c) {
      if (c.aplica === false) groups[3].items.push(c);
      else if (c.estado === 'NO_CUMPLE') groups[0].items.push(c);
      else if (c.estado === 'AVISO') groups[1].items.push(c);
      else if (c.estado === 'SIN_DATOS') groups[2].items.push(c);
      else groups[4].items.push(c);
    });
    groups[0].items.sort(bySeverity);
    groups[1].items.sort(bySeverity);

    if (pending) {
      host.appendChild(el('h4', { id: 'g-pending' }, ['Pendientes de PageSpeed Insights ', el('span', { class: 'group-count', text: '(' + PSI_IDS.length + ')' })]));
      host.appendChild(el('ul', { class: 'pending-list', 'aria-labelledby': 'g-pending' }, PSI_IDS.map(function (id) {
        return el('li', { text: i18n.psi_checks[id] + ' (' + id + ')' });
      })));
    }
    groups.forEach(function (g) {
      if (!g.items.length) return;
      host.appendChild(el('h4', { id: g.id }, [g.title + ' ', el('span', { class: 'group-count', text: '(' + g.items.length + ')' })]));
      host.appendChild(el('ul', { class: 'findings', 'aria-labelledby': g.id }, g.items.map(function (c) {
        return el('li', {}, [renderCheck(c, priorities[c.id])]);
      })));
    });
  }

  function renderCheck(c, priority) {
    var stateKey = c.aplica === false ? 'NA' : c.estado;
    var stateLabel = c.aplica === false ? 'No aplica' : (i18n.estados[c.estado] || c.estado);
    var summary = el('summary', {}, [
      el('span', { class: 'badge badge--' + stateKey, text: stateLabel }),
      el('span', { class: 'finding__name', text: c.nombre || c.id }),
      el('span', { class: 'finding__meta', text: c.id + (c.severidad ? ' · Gravedad ' + lower(i18n.severidades, c.severidad) : '') }),
      c.detalle ? el('span', { class: 'finding__summary', text: c.detalle }) : null
    ]);

    var rows = [
      ['Valor medido', fmtValue(c.valor_medido)],
      c.umbral ? ['Umbral', String(c.umbral)] : null,
      c.criterio ? ['Criterio', c.criterio] : null
    ];
    if (c.evidencia && c.evidencia.length) {
      var list = el('ul', { class: 'evidence' }, c.evidencia.map(function (e) {
        return el('li', {}, [
          e.selector ? el('code', { text: e.selector }) : null,
          e.linea ? document.createTextNode(' (línea ' + e.linea + ')') : null,
          el('pre', {}, [el('code', { text: e.extracto })])
        ]);
      }));
      var evidence = el('div', {}, [list]);
      if (c.ocurrencias > c.evidencia.length) {
        evidence.appendChild(el('p', { class: 'help', text: 'Se muestran ' + c.evidencia.length + ' de ' + c.ocurrencias + ' casos.' }));
      }
      rows.push(['Evidencia', evidence]);
    }
    if (c.requiere_revision_manual) {
      rows.push(['Revisión manual', el('span', { class: 'manual', text: 'Esta comprobación requiere además revisión manual.' })]);
    }
    if (priority) {
      rows.push(['Recomendación', el('a', { href: '#rec-' + c.id, text: 'Ver la recomendación n.º ' + priority.orden })]);
    }

    return el('details', { class: 'finding', id: 'hallazgo-' + c.id, 'data-check': c.id }, [
      summary,
      el('div', { class: 'finding__body' }, [defList('', rows)])
    ]);
  }

  // 5. Recomendaciones priorizadas (lo recomendado: no penaliza)
  function updateRecommendations(run) {
    var host = run.view.recs;
    var items = run.data.informe.prioridades || [];
    // Las propuestas de corrección abiertas siguen abiertas al repintar (llegada de PageSpeed)
    var open = {};
    Array.prototype.forEach.call(host.querySelectorAll('details.fix[open]'), function (d) { open[d.getAttribute('data-check')] = true; });
    var fixes = {};
    (run.data.checks || []).forEach(function (c) { if (c.correccion) fixes[c.id] = c.correccion; });
    host.textContent = '';
    if (!items.length) {
      host.appendChild(el('p', { text: 'No hay recomendaciones: no se han detectado fallos ni avisos de forma automática.' }));
      return;
    }
    host.appendChild(el('p', { class: 'help', text: 'Primero los fallos, ordenados por los puntos que restan; después los avisos, que no restan puntos. Las recomendaciones son propuestas de mejora y no afectan a la nota.' }));
    host.appendChild(el('ol', { class: 'recs' }, items.map(function (r) {
      var meta = ['Gravedad ' + lower(i18n.severidades, r.severidad), 'impacto ' + lower(i18n.niveles, r.impacto), 'esfuerzo ' + lower(i18n.niveles, r.esfuerzo)];
      if (r.estado === 'NO_CUMPLE') {
        meta.push('penalización ' + fmtNumber(r.penalizacion, 1) + ' puntos' + (r.ocurrencias > 1 ? ' (' + r.ocurrencias + ' casos)' : ''));
      }
      return el('li', { id: 'rec-' + r.id, class: 'rec', tabindex: '-1' }, [
        el('p', { class: 'rec__head' }, [
          el('span', { class: 'badge badge--' + r.estado, text: i18n.estados[r.estado] || r.estado }), ' ',
          findingLink(r.id, r.nombre)
        ]),
        el('p', { class: 'finding__meta', text: meta.join(' · ') }),
        el('p', { text: r.recomendacion }),
        fixes[r.id] ? renderFix(r.id, fixes[r.id], !!open[r.id]) : null
      ]);
    })));
  }

  /**
   * 09. Propuesta de corrección: código «antes» (tomado de la evidencia) y «después» (plantilla
   * de lang/es.php). Es un borrador informativo; todo se pinta como texto.
   */
  function renderFix(id, fix, isOpen) {
    var list = el('ol', { class: 'fix__list' }, (fix.fragmentos || []).map(function (f) {
      return el('li', {}, [
        el('p', { class: 'fix__label', text: 'Antes' }),
        f.antes === null
          ? el('p', { class: 'fix__absent', text: 'No existe en la página.' })
          : el('pre', { class: 'fix__code fix__code--before' }, [el('code', { text: f.antes })]),
        el('p', { class: 'fix__label', text: 'Después' }),
        el('pre', { class: 'fix__code' }, [el('code', { text: f.despues })])
      ]);
    }));
    var shown = (fix.fragmentos || []).length;
    var details = el('details', { class: 'fix', 'data-check': id }, [
      el('summary', { text: 'Propuesta de corrección (borrador)' }),
      el('div', { class: 'fix__body' }, [
        el('p', { class: 'help', text: fix.borrador }),
        fix.nota ? el('p', { text: fix.nota }) : null,
        list,
        fix.total > shown ? el('p', { class: 'help', text: 'Se muestran ' + shown + ' de ' + fix.total + ' casos.' }) : null
      ])
    ]);
    if (isOpen) details.open = true;
    return details;
  }

  // 6. Plan de acción: matriz impacto × esfuerzo
  function updatePlan(run) {
    var host = run.view.plan;
    var matrix = run.data.informe.matriz || {};
    var prio = priorityIndex(run);
    host.textContent = '';
    if (!(run.data.informe.prioridades || []).length) {
      host.appendChild(el('p', { text: 'No hay acciones que planificar: no se han detectado fallos ni avisos de forma automática.' }));
      return;
    }
    host.appendChild(el('p', { class: 'help', text: 'Fallos y avisos según su impacto y su esfuerzo estimados (valores orientativos). En cada grupo se mantiene el orden de prioridad.' }));
    host.appendChild(el('div', { class: 'plan' }, Object.keys(matrix).map(function (k) {
      var q = matrix[k];
      var hid = 'plan-' + k;
      return el('section', { class: 'quadrant', 'aria-labelledby': hid }, [
        el('h4', { id: hid }, [q.titulo + ' ', el('span', { class: 'group-count', text: '(' + q.ids.length + ')' })]),
        el('p', { class: 'quadrant__desc', text: q.descripcion }),
        q.ids.length
          ? el('ul', {}, q.ids.map(function (id) {
            return el('li', {}, [el('a', { href: '#rec-' + id, text: (prio[id] && prio[id].nombre) || id })]);
          }))
          : el('p', { class: 'quadrant__empty', text: 'Ninguno.' })
      ]);
    })));
  }

  // 7. Glosario (fijo)
  // 7. Análisis complementario (no puntúa)
  function renderComplementary(host, c) {
    host.appendChild(el('p', { class: 'help', text: 'Vistas informativas calculadas con la misma descarga. No asignan estados ni restan puntos.' }));
    if (!c) {
      host.appendChild(el('p', { text: 'No disponible.' }));
      return;
    }
    if (c.sin_contenido) {
      host.appendChild(el('div', { class: 'notice notice--warn' }, [
        el('p', { text: 'Las vistas basadas en el contenido de la página no están disponibles. ' + c.sin_contenido })
      ]));
    }
    if (c.vista_bot) host.appendChild(renderBotView(c.vista_bot));
    if (c.vista_previa) host.appendChild(renderPreview(c.vista_previa));
    if (c.bots_ia) host.appendChild(renderBotsTable(c.bots_ia));
    if (!c.sin_contenido) host.appendChild(renderKeyword(c.palabra_clave));
    if (c.esquema) host.appendChild(renderOutline(c.esquema));
    if (c.transferencia) host.appendChild(renderTransfer(c.transferencia));
  }

  // 07. Redirecciones y peso
  function fmtSeconds(ms) {
    return fmtNumber((ms || 0) / 1000, 2) + ' s';
  }

  function fmtBytes(b) {
    return b >= 1048576 ? fmtNumber(b / 1048576, 2) + ' MB' : fmtNumber(b / 1024, 1) + ' KB';
  }

  function renderTransfer(t) {
    var sec = el('section', { class: 'subsection', 'aria-labelledby': 'transfer-title' }, [
      el('h4', { id: 'transfer-title', text: 'Redirecciones y peso' })
    ]);
    if (t.aviso_redirecciones) {
      sec.appendChild(el('div', { class: 'notice notice--warn' }, [
        el('p', { text: 'La URL pasa por ' + t.redirecciones + ' redirecciones antes de llegar a la página final (más de 2). Cada salto añade una petición y tiempo de espera; conviene enlazar directamente a la URL final.' })
      ]));
    }
    sec.appendChild(el('ul', { class: 'inline-stats' }, [
      el('li', {}, ['Redirecciones: ', el('strong', { text: String(t.redirecciones) })]),
      el('li', {}, ['Tiempo total: ', el('strong', { text: fmtSeconds(t.tiempo_total_ms) })]),
      el('li', {}, ['HTML descargado: ', el('strong', { text: fmtBytes(t.bytes) }), t.truncado ? ' (truncado al límite de 2 MB)' : '']),
      el('li', {}, ['Content-Encoding: ', t.content_encoding ? el('code', { text: t.content_encoding }) : el('span', { class: 'is-missing', text: 'no indicado (sin compresión declarada)' })])
    ]));
    if (t.saltos.length) {
      sec.appendChild(el('div', { class: 'table-wrap' }, [
        el('table', { class: 'data-table' }, [
          el('caption', { class: 'visually-hidden', text: 'Cadena de peticiones hasta la página final' }),
          el('thead', {}, [el('tr', {}, [
            el('th', { scope: 'col', class: 'num', text: 'Paso' }),
            el('th', { scope: 'col', text: 'URL' }),
            el('th', { scope: 'col', class: 'num', text: 'Código' }),
            el('th', { scope: 'col', class: 'num', text: 'Tiempo' })
          ])]),
          el('tbody', {}, t.saltos.map(function (h, i) {
            var last = i === t.saltos.length - 1;
            return el('tr', {}, [
              el('td', { class: 'num', text: String(i + 1) }),
              el('th', { scope: 'row', text: h.url + (last && t.saltos.length > 1 ? ' (final)' : '') }),
              el('td', { class: 'num', text: h.estado ? 'HTTP ' + h.estado : '—' }),
              el('td', { class: 'num', text: h.tiempo_ms === null ? '—' : fmtSeconds(h.tiempo_ms) })
            ]);
          }))
        ])
      ]));
    }
    sec.appendChild(el('p', { class: 'help', text: 'Datos de la descarga del analizador. El tamaño es el del HTML ya descomprimido (no el transferido por la red) y no incluye imágenes, hojas de estilo ni scripts. El tiempo total incluye la resolución DNS y todos los saltos.' }));
    return sec;
  }

  // 06. Esquema del documento: lista anidada real (ul dentro de li) y regiones
  function outlineList(nodes) {
    return el('ul', {}, nodes.map(function (n) {
      return el('li', {}, [
        el('span', { class: 'outline__level', text: 'H' + n.nivel }),
        ' ',
        n.texto ? el('span', { text: n.texto }) : el('span', { class: 'is-missing', text: '(encabezado vacío)' }),
        n.salto ? el('strong', { class: 'outline__jump', text: ' Salto de nivel: de H' + n.desde + ' a H' + n.nivel }) : null,
        n.hijos && n.hijos.length ? outlineList(n.hijos) : null
      ]);
    }));
  }

  function renderOutline(o) {
    var sec = el('section', { class: 'subsection', 'aria-labelledby': 'outline-title' }, [
      el('h4', { id: 'outline-title', text: 'Esquema del documento' }),
      el('h5', { id: 'outline-headings', text: 'Encabezados' }),
      el('p', { text: o.total_encabezados
        ? fmtNumber(o.total_encabezados, 0) + (o.total_encabezados === 1 ? ' encabezado visible' : ' encabezados visibles') + ', '
          + (o.saltos ? fmtNumber(o.saltos, 0) + (o.saltos === 1 ? ' salto de nivel.' : ' saltos de nivel.') : 'sin saltos de nivel.')
        : 'La página no tiene encabezados visibles (h1-h6).' })
    ]);
    if (o.encabezados.length) {
      var tree = outlineList(o.encabezados);
      tree.className = 'outline';
      tree.setAttribute('aria-labelledby', 'outline-headings');
      sec.appendChild(tree);
    }
    if (o.recortado) sec.appendChild(el('p', { class: 'help', text: 'Se muestran los ' + fmtNumber(countNodes(o.encabezados), 0) + ' primeros encabezados.' }));
    sec.appendChild(el('p', { class: 'help', text: 'Hay un salto cuando un encabezado baja más de un nivel respecto al anterior (por ejemplo, de H2 a H4); es el mismo criterio de la comprobación C05. No se incluyen los encabezados ocultos.' }));

    sec.appendChild(el('h5', { id: 'outline-landmarks', text: 'Regiones (landmarks)' }));
    sec.appendChild(el('div', { class: 'table-wrap' }, [
      el('table', { class: 'data-table', 'aria-labelledby': 'outline-landmarks' }, [
        el('thead', {}, [el('tr', {}, [
          el('th', { scope: 'col', text: 'Región' }),
          el('th', { scope: 'col', text: 'Elemento o rol' }),
          el('th', { scope: 'col', class: 'num', text: 'Cantidad' }),
          el('th', { scope: 'col', text: 'Nombres' })
        ])]),
        el('tbody', {}, o.regiones.map(function (r) {
          return el('tr', {}, [
            el('th', { scope: 'row', text: r.nombre }),
            el('td', {}, [el('code', { text: r.elemento }), ' / ', el('code', { text: 'role="' + r.rol + '"' })]),
            el('td', { class: 'num', text: fmtNumber(r.total, 0) + (r.ocultas ? ' (+' + r.ocultas + ' oculta' + (r.ocultas === 1 ? '' : 's') + ')' : '') }),
            el('td', { text: r.etiquetas.length ? r.etiquetas.join(' · ') : '—' })
          ]);
        }))
      ])
    ]));
    sec.appendChild(el('p', { class: 'help', text: 'header y footer solo cuentan como cabecera y pie de la página cuando no están dentro de article, aside, main, nav o section. Los nombres salen de aria-label o aria-labelledby.' }));
    return sec;
  }

  function countNodes(nodes) {
    return nodes.reduce(function (n, x) { return n + 1 + countNodes(x.hijos || []); }, 0);
  }

  // 05. Palabra clave objetivo (opcional)
  function renderKeyword(k) {
    var sec = el('section', { class: 'subsection', 'aria-labelledby': 'keyword-title' }, [
      el('h4', { id: 'keyword-title', text: 'Palabra clave objetivo' })
    ]);
    if (!k) {
      sec.appendChild(el('p', { text: 'No se ha indicado ninguna palabra clave. Es opcional: puedes escribirla en el formulario y volver a analizar.' }));
      return sec;
    }
    var found = k.presente_en;
    sec.appendChild(el('p', {}, [
      '«', el('strong', { text: k.palabra_clave }), '» aparece en ' + found + ' de ' + k.elementos.length + ' elementos.'
    ]));
    sec.appendChild(el('ul', { class: 'keyword-list' }, k.elementos.map(function (i) {
      return el('li', { class: i.presente ? 'is-yes' : 'is-no' }, [
        el('span', { class: 'keyword-mark', text: i.presente ? 'Aparece' : 'No aparece' }),
        ' ',
        el('span', { class: 'keyword-name', text: i.nombre }),
        i.texto ? el('span', { class: 'keyword-text', text: i.texto }) : null
      ]);
    })));
    sec.appendChild(el('p', { class: 'help', text: 'Se busca la frase completa sin distinguir mayúsculas ni tildes (la ñ sí se distingue) e ignorando los signos de puntuación. No se cuentan plurales ni sinónimos. Es informativo: no puntúa.' }));
    return sec;
  }

  // 04. Bots de IA y buscadores en robots.txt
  var ACCESS_TEXT = { permitido: 'Permitido', bloqueado: 'Bloqueado', sin_datos: 'Sin datos' };
  var BOT_TYPES = [
    ['busqueda', 'Bots de búsqueda (buscadores y asistentes de IA)', 'bots-search'],
    ['entrenamiento', 'Bots de entrenamiento de IA', 'bots-training']
  ];

  function renderBotsTable(b) {
    var sec = el('section', { class: 'subsection', 'aria-labelledby': 'bots-title' }, [
      el('h4', { id: 'bots-title', text: 'Bots de IA y buscadores en robots.txt' }),
      el('p', { class: 'help', text: 'Acceso de cada bot a esta URL (' + b.ruta + ') según robots.txt: se aplica el grupo del propio bot o, si no lo hay, el de «*», y la regla más larga (en empate, Allow). Es informativo: lo que puntúa es la comprobación C02.' })
    ]);
    if (b.nota) sec.appendChild(el('p', { text: b.nota }));
    BOT_TYPES.forEach(function (t) {
      var rows = b.bots.filter(function (r) { return r.tipo === t[0]; });
      if (!rows.length) return;
      sec.appendChild(el('h5', { id: t[2], text: t[1] }));
      sec.appendChild(el('div', { class: 'table-wrap' }, [
        el('table', { class: 'data-table', 'aria-labelledby': t[2] }, [
          el('thead', {}, [el('tr', {}, ['Bot', 'Acceso a esta URL', 'Grupo aplicado', 'Regla'].map(function (h) {
            return el('th', { scope: 'col', text: h });
          }))]),
          el('tbody', {}, rows.map(function (r) {
            var access = ACCESS_TEXT[r.acceso] || r.acceso;
            var group = r.grupo === '*' ? '* (todos los bots)' : (r.grupo || (b.robots_txt === 'presente' ? 'Ninguno' : '—'));
            return el('tr', {}, [
              el('th', { scope: 'row', text: r.bot }),
              el('td', {}, [r.acceso === 'bloqueado' ? el('strong', { text: access }) : access]),
              el('td', { text: group }),
              el('td', {}, [r.regla ? el('code', { text: r.regla }) : (b.robots_txt === 'presente' ? 'Ninguna coincide' : '—')])
            ]);
          }))
        ])
      ]));
    });
    sec.appendChild(el('p', { class: 'help', text: 'Los bots de entrenamiento recopilan contenido para entrenar modelos de IA; los de búsqueda, para mostrar la página en resultados y respuestas. Bloquear solo a los primeros no impide aparecer en buscadores.' }));
    return sec;
  }

  /** Texto o, si falta, un aviso en cursiva. */
  function textOrMissing(tag, cls, value, missing) {
    return value
      ? el(tag, { class: cls, text: value })
      : el(tag, { class: cls + ' is-missing', text: missing });
  }

  // 03. Vista previa en Google y redes
  function renderPreview(p) {
    var g = p.google;
    var s = p.social;
    var sec = el('section', { class: 'subsection', 'aria-labelledby': 'preview-title' }, [
      el('h4', { id: 'preview-title', text: 'Vista previa en Google y redes' }),
      el('p', { class: 'help', text: 'Aproximación: los buscadores y las redes recortan según el ancho en píxeles y pueden mostrar otros textos distintos de los indicados en la página.' }),
      el('h5', { text: 'Resultado de búsqueda' }),
      el('div', { class: 'serp' }, [
        el('p', { class: 'serp__url', text: g.url_mostrado }),
        textOrMissing('p', 'serp__title', g.titulo_mostrado, 'Sin title: el buscador generaría un título.'),
        textOrMissing('p', 'serp__desc', g.descripcion_mostrado, 'Sin meta description: el buscador mostraría un fragmento del contenido.')
      ]),
      el('ul', { class: 'inline-stats' }, [
        el('li', { text: 'Title: ' + fmtNumber(g.titulo_longitud, 0) + ' caracteres' + (g.titulo_recortado ? ' (se recorta a unos 60)' : '') }),
        el('li', { text: 'Meta description: ' + fmtNumber(g.descripcion_longitud, 0) + ' caracteres' + (g.descripcion_recortado ? ' (se recorta a unos 155)' : '') })
      ]),
      el('h5', { text: 'Tarjeta en redes sociales' })
    ]);

    var media = el('div', { class: 'social-card__media' });
    var placeholder = function (text) {
      media.textContent = '';
      media.appendChild(el('p', { class: 'social-card__placeholder', text: text }));
    };
    // Solo se carga una imagen https; la pide el navegador, sin enviar la dirección de esta herramienta
    if (s.imagen && s.imagen_https && /^https:\/\//i.test(s.imagen)) {
      var img = el('img', { src: s.imagen, alt: 'Imagen indicada en ' + s.imagen_origen, referrerpolicy: 'no-referrer', loading: 'lazy', decoding: 'async' });
      img.addEventListener('error', function () { placeholder('No se ha podido cargar la imagen.'); });
      media.appendChild(img);
    } else if (s.imagen) {
      placeholder('Imagen no mostrada: solo se cargan imágenes servidas por https.');
    } else {
      placeholder('Sin imagen: falta og:image.');
    }
    sec.appendChild(el('div', { class: 'social-card' }, [
      media,
      el('div', { class: 'social-card__body' }, [
        el('p', { class: 'social-card__domain', text: s.dominio }),
        textOrMissing('p', 'social-card__title', s.titulo, 'Sin título'),
        textOrMissing('p', 'social-card__desc', s.descripcion, 'Sin descripción')
      ])
    ]));

    var notes = [];
    if (s.titulo_origen && s.titulo_origen !== 'og:title') notes.push('El título sale de ' + s.titulo_origen + ' porque falta og:title.');
    if (s.descripcion_origen && s.descripcion_origen !== 'og:description') notes.push('La descripción sale de ' + s.descripcion_origen + ' porque falta og:description.');
    if (s.imagen_origen && s.imagen_origen !== 'og:image') notes.push('La imagen sale de ' + s.imagen_origen + ' porque falta og:image.');
    if (s.imagen && s.imagen_https) notes.push('La imagen la descarga tu navegador directamente del servidor de la web analizada.');
    notes.forEach(function (n) { sec.appendChild(el('p', { class: 'help', text: n })); });

    var tags = Object.keys(s.etiquetas);
    sec.appendChild(el('div', { class: 'table-wrap' }, [
      el('table', { class: 'data-table' }, [
        el('caption', { class: 'visually-hidden', text: 'Etiquetas para redes sociales' }),
        el('thead', {}, [el('tr', {}, [el('th', { scope: 'col', text: 'Etiqueta' }), el('th', { scope: 'col', text: 'Valor' })])]),
        el('tbody', {}, tags.map(function (t) {
          var v = s.etiquetas[t];
          return el('tr', {}, [el('th', { scope: 'row' }, [el('code', { text: t })]), v ? el('td', { text: v }) : el('td', { class: 'is-missing', text: 'Ausente' })]);
        }))
      ])
    ]));
    sec.appendChild(el('p', {}, [s.ausentes.length
      ? 'Etiquetas ausentes: ' + joinList(s.ausentes) + '.'
      : 'Están las cuatro etiquetas comprobadas.']));
    return sec;
  }

  var BOT_PREVIEW_CHARS = 5000;

  /** Recorta un texto a unos max caracteres sin partir palabras. */
  function cutText(s, max) {
    if (s.length <= max) return { text: s, cut: false };
    var i = s.lastIndexOf(' ', max);
    if (i < max * 0.8) {
      i = max;
      var code = s.charCodeAt(i - 1);
      if (code >= 0xD800 && code <= 0xDBFF) i--;   // no partir un carácter de dos unidades
    }
    return { text: s.slice(0, i) + ' …', cut: true };
  }

  // 02. Lo que ve un bot
  function renderBotView(v) {
    var sec = el('section', { class: 'subsection', 'aria-labelledby': 'bot-title' }, [
      el('h4', { id: 'bot-title', text: 'Lo que ve un bot' }),
      el('p', { class: 'help', text: 'Texto del contenido principal en el HTML inicial, tal como lo lee un rastreador que no ejecuta JavaScript (se extrae igual que en la comprobación C03). Los rastreadores que sí lo ejecutan, como Googlebot, pueden ver más.' }),
      el('ul', { class: 'inline-stats' }, [
        el('li', {}, ['Contenido principal: ', el('strong', { text: v.fuente })]),
        el('li', {}, [el('strong', { text: fmtNumber(v.palabras, 0) }), v.palabras === 1 ? ' palabra' : ' palabras']),
        el('li', {}, [el('strong', { text: fmtNumber(v.caracteres, 0) }), v.caracteres === 1 ? ' carácter' : ' caracteres'])
      ])
    ]);
    if (v.poco_texto) {
      sec.appendChild(el('div', { class: 'notice notice--warn' }, [
        el('p', { text: 'Poco texto: ' + fmtNumber(v.palabras, 0) + ' palabras, por debajo del mínimo orientativo de ' + fmtNumber(v.minimo_palabras, 0) + '. Puede que el contenido se cargue con JavaScript o que la página tenga poco texto.' })
      ]));
    }
    if (v.html_recortado) {
      sec.appendChild(el('p', { class: 'help', text: 'El HTML se truncó al llegar al límite de 2 MB: el texto puede estar incompleto.' }));
    }
    if (!v.texto) {
      sec.appendChild(el('p', { text: 'El contenido principal no tiene texto en el HTML inicial.' }));
      return sec;
    }
    var full = v.texto;
    var preview = cutText(full, BOT_PREVIEW_CHARS);
    var textEl = el('p', { text: preview.text });
    sec.appendChild(el('div', { id: 'bot-text', class: 'bot-text', role: 'region', 'aria-label': 'Texto que ve un bot', tabindex: '0' }, [textEl]));
    if (preview.cut) {
      var more = el('button', { type: 'button', class: 'button button--secondary no-print', 'aria-controls': 'bot-text', 'aria-expanded': 'false', text: 'Mostrar el texto completo' });
      more.addEventListener('click', function () {
        var expanded = more.getAttribute('aria-expanded') === 'true';
        textEl.textContent = expanded ? preview.text : full;
        more.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        more.textContent = expanded ? 'Mostrar el texto completo' : 'Mostrar solo el principio';
      });
      sec.appendChild(el('p', { class: 'help', text: 'Se muestran unos ' + fmtNumber(BOT_PREVIEW_CHARS, 0) + ' caracteres del principio.' }));
      sec.appendChild(more);
    }
    if (v.texto_recortado) {
      sec.appendChild(el('p', { class: 'help', text: 'Por su extensión, el texto completo se limita al principio de la página; el recuento de palabras sí incluye todo el texto.' }));
    }
    return sec;
  }

  function renderGlossary() {
    var terms = i18n.glosario || {};
    return defList('glossary', Object.keys(terms).map(function (t) { return [t, terms[t]]; }));
  }

  // 9. Evidencias del análisis (fuera de #results, después de las limitaciones)
  function updateEvidence(run) {
    var d = run.data;
    var f = d.fetch || {};
    var hops = f.hops || [];
    var last = hops.length ? hops[hops.length - 1] : null;
    var redirects = f.redirects || [];
    var timings = 'Total ' + fmtNumber((f.timings && f.timings.total_ms || 0) / 1000, 2) + ' s';
    if (last && last.timings && typeof last.timings.ttfb_ms === 'number') {
      timings += '; primer byte de la respuesta final: ' + fmtNumber(last.timings.ttfb_ms / 1000, 2) + ' s';
    }
    var robots = d.robots || {};

    evidenceEl.textContent = '';
    evidenceEl.appendChild(el('h2', { id: 'evidence-title', text: 'Evidencias del análisis' }));
    evidenceEl.appendChild(el('p', { class: 'help', text: 'Datos de la descarga y de las consultas en los que se basa el informe. Las evidencias de cada comprobación (selector, extracto y línea) figuran en los hallazgos detallados. No se guarda ninguno de estos datos.' }));
    evidenceEl.appendChild(defList('facts', [
      ['Fecha del análisis', fmtDate(d.fecha)],
      ['URL solicitada', f.requested_url || '—'],
      ['URL final', f.final_url || d.url],
      ['Redirecciones', redirects.length
        ? el('ol', {}, redirects.map(function (r) { return el('li', { text: 'HTTP ' + r.status + ': ' + r.from + ' → ' + r.to }); }))
        : 'Ninguna'],
      ['Respuesta', 'HTTP ' + (f.status || '—') + (f.content_type ? ' · ' + f.content_type : '') + ' · '
        + fmtNumber((f.bytes || 0) / 1024, 1) + ' KB' + (f.truncated ? ' (truncada al límite de 2 MB)' : '')],
      ['Servidor', last && last.ip ? 'IP ' + last.ip : '—'],
      ['Tiempos', timings],
      ['robots.txt', robots.status ? 'HTTP ' + robots.status : 'No se pudo descargar' + (robots.error ? ' (' + robots.error + ')' : '')],
      d.blocked ? ['Bloqueo', d.blocked.message] : null,
      ['Agente de usuario', d.user_agent || '—'],
      ['PageSpeed Insights', psiText(run)]
    ]));
    evidenceEl.hidden = false;
  }

  function psiText(run) {
    if (run.psiMerged) {
      var p = run.psi.psi || {};
      if (!p.ok) return 'Sin datos. ' + ((p.error && p.error.message) || '');
      var parts = ['Estrategia móvil'];
      if (p.lighthouse) parts.push('Lighthouse ' + p.lighthouse);
      if (p.fecha) parts.push('consultado el ' + fmtDate(p.fecha));
      if (p.url_final) parts.push('URL evaluada: ' + p.url_final);
      return parts.join('; ') + '.';
    }
    if (run.psiFailure) return 'No disponible. ' + run.psiFailure + ' Contraste (C08) y Core Web Vitals (C14) no están incluidos.';
    return 'Consultando…';
  }

  // ---------- Navegación e impresión ----------

  // Los enlaces a un hallazgo abren su desplegable y llevan el foco a él
  results.addEventListener('click', function (ev) {
    var a = ev.target.closest ? ev.target.closest('a[data-finding]') : null;
    if (!a) return;
    var d = document.getElementById('hallazgo-' + a.getAttribute('data-finding'));
    if (!d) return;
    ev.preventDefault();
    d.open = true;
    d.querySelector('summary').focus();
  });

  // Al imprimir se abren todos los desplegables (si no, su contenido no sale) y después se restauran
  var openedForPrint = [];
  window.addEventListener('beforeprint', function () {
    openedForPrint = Array.prototype.filter.call(results.querySelectorAll('details'), function (d) {
      if (d.open) return false;
      d.open = true;
      return true;
    });
  });
  window.addEventListener('afterprint', function () {
    openedForPrint.forEach(function (d) { d.open = false; });
    openedForPrint = [];
  });
})();
