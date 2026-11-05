/* Analizador web — lógica de la pantalla. Sin librerías.
 * Todo dato procedente del servidor o de la web analizada se inserta con textContent, nunca con innerHTML.
 *
 * Flujo: api/analyze.php y api/psi.php se piden en paralelo. Se pinta el informe en cuanto llega el
 * análisis, con puntuación provisional; cuando llega PageSpeed (C08, C14) se recalculan puntuación e
 * informe en el servidor (api/score.php, mismo Scorer y mismo Report) y se actualiza sin mover el foco.
 *
 * Informe (nueve apartados): 1 resumen ejecutivo, 2 puntuaciones, 3 problemas críticos, 4 hallazgos
 * detallados con evidencia, 5 recomendaciones priorizadas, 6 plan de acción (matriz 2×2), 7 glosario,
 * 8 limitaciones (texto fijo de index.php, siempre visible) y 9 evidencias del análisis. */
(function () {
  'use strict';

  var i18n = JSON.parse(document.getElementById('i18n').textContent);
  var form = document.getElementById('analyze-form');
  var input = document.getElementById('url');
  var noindex = document.getElementById('noindex');
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
    analyze(url, noindex.checked);
  });

  function analyze(url, noindexIntentional) {
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

    postJson('api/analyze.php', { url: url, noindex_intentional: noindexIntentional }, run.analyzeCtrl, REQUEST_TIMEOUT_MS).then(function (r) {
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

    results.appendChild(el('div', { class: 'report-head' }, [
      title,
      el('ul', { class: 'meta-list' }, [
        el('li', {}, ['URL analizada: ', el('strong', { text: data.url })]),
        el('li', { text: 'Fecha del análisis: ' + fmtDate(data.fecha) })
      ]),
      printButton
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
    var glossary = reportSection(SECTIONS[6][0], SECTIONS[6][1]);
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
        el('p', { text: r.recomendacion })
      ]);
    })));
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
