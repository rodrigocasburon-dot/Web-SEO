/* Analizador web — lógica de la pantalla. Sin librerías.
 * Todo dato procedente del servidor o de la web analizada se inserta con textContent, nunca con innerHTML.
 *
 * Flujo: api/analyze.php y api/psi.php se piden en paralelo. Se pinta el análisis en cuanto llega,
 * con puntuación provisional; cuando llega PageSpeed (C08, C14) se recalcula la puntuación en el
 * servidor (api/score.php, misma fórmula) y se actualizan puntuaciones y hallazgos sin mover el foco. */
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

  var REQUEST_TIMEOUT_MS = 60000;
  var PSI_TIMEOUT_MS = 100000;
  var SEVERITY_ORDER = { CRITICO: 0, ALTO: 1, MEDIO: 2, BAJO: 3 };
  var LEVEL_ORDER = { ALTO: 0, MEDIO: 1, BAJO: 2 };
  var PSI_IDS = Object.keys(i18n.psi_checks || {});
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

  // ---------- Estados de la pantalla ----------

  function setStatus(text, loading) {
    statusEl.classList.toggle('is-loading', !!loading);
    statusEl.textContent = text || '';
  }

  function setLoading(on) {
    button.disabled = on;
    results.setAttribute('aria-busy', on ? 'true' : 'false');
    setStatus(on ? 'Analizando la página… puede tardar unos segundos.' : '', on);
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
    setLoading(true);

    var run = {
      analyzeCtrl: new AbortController(),
      psiCtrl: new AbortController(),
      data: null,          // respuesta de analyze.php
      psi: null,           // respuesta de psi.php (ok: true)
      psiFailure: null,    // mensaje si psi.php no se pudo usar
      psiDone: false,
      view: null,          // nodos que se actualizan al llegar PageSpeed
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

  /** Incorpora C08 y C14 y recalcula la puntuación en el servidor. */
  function finishPsi(run) {
    if (!run.psi) {
      refreshWithoutPsi(run);
      setStatus('Análisis completado. ' + run.psiFailure + ' La puntuación no incluye contraste ni Core Web Vitals.', false);
      current = null;
      return;
    }
    var merged = run.data.checks.filter(function (c) { return PSI_IDS.indexOf(c.id) === -1; }).concat(run.psi.checks);
    var payload = merged.map(function (c) {
      return { id: c.id, estado: c.estado, ocurrencias: c.ocurrencias || 0, aplica: c.aplica !== false };
    });
    postJson('api/score.php', { checks: payload }, new AbortController(), REQUEST_TIMEOUT_MS).then(function (r) {
      if (run !== current) return;
      if (!r.data || !r.data.ok) throw new Error('score');
      run.data.checks = merged;
      run.data.puntuacion = r.data.puntuacion;
      run.psiMerged = true;
      updatePsiInfo(run);
      updateScores(run);
      updateFindings(run);
      setStatus(run.psi.psi && run.psi.psi.ok
        ? 'Análisis completado. Puntuación actualizada con los datos de PageSpeed Insights.'
        : 'Análisis completado. PageSpeed Insights no devolvió datos: contraste y Core Web Vitals figuran como «Sin datos».', false);
      current = null;
    }).catch(function () {
      if (run !== current) return;
      run.psiFailure = 'No se pudo recalcular la puntuación con los datos de PageSpeed Insights.';
      run.psi = null;
      refreshWithoutPsi(run);
      setStatus('Análisis completado. ' + run.psiFailure + ' Se mantiene la puntuación sin contraste ni Core Web Vitals.', false);
      current = null;
    });
  }

  /** PageSpeed no disponible: se quita la lista de pendientes y se explica que la nota no los incluye. */
  function refreshWithoutPsi(run) {
    updatePsiInfo(run);
    updateScores(run);
    updateFindings(run);
  }

  // ---------- Resultados ----------

  function renderResults(run) {
    var data = run.data;
    var title = el('h2', { id: 'results-title', tabindex: '-1', text: 'Resultados' });
    results.appendChild(title);

    var psiInfo = el('li', { text: 'PageSpeed Insights: consultando…' });
    var meta = el('ul', { class: 'meta-list' }, [
      el('li', {}, ['URL analizada: ', el('strong', { text: data.url })])
    ]);
    if (data.fetch && data.fetch.redirects && data.fetch.redirects.length) {
      meta.appendChild(el('li', { text: 'Se siguieron ' + data.fetch.redirects.length + ' redirección(es) desde ' + data.fetch.requested_url + '.' }));
    }
    if (data.fetch && data.fetch.timings) {
      meta.appendChild(el('li', { text: 'Tiempo de descarga: ' + fmtNumber(data.fetch.timings.total_ms / 1000, 1) + ' s.' }));
    }
    meta.appendChild(psiInfo);
    results.appendChild(meta);

    if (data.blocked) {
      results.appendChild(el('div', { class: 'notice notice--warn' }, [
        el('p', {}, [el('strong', { text: 'Acceso bloqueado. ' }), data.blocked.message]),
        el('p', { text: 'Las comprobaciones que dependen del contenido de la página aparecen como «Sin datos».' })
      ]));
    }

    run.view = { psiInfo: psiInfo, scores: el('div'), findings: el('div') };
    results.appendChild(run.view.scores);
    results.appendChild(run.view.findings);
    updateScores(run);
    updateFindings(run);

    results.hidden = false;
    results.setAttribute('aria-busy', 'false');
    title.focus();
  }

  function psiPending(run) {
    return !run.psiMerged && !run.psiFailure;
  }

  /** Nota bajo las puntuaciones cuando no incluyen C08 y C14. */
  function psiScoreNote(run) {
    if (run.psiMerged) return null;
    return psiPending(run)
      ? 'Puntuación provisional: falta incorporar contraste (C08) y Core Web Vitals (C14), que se están consultando a PageSpeed Insights.'
      : 'La puntuación no incluye contraste (C08) ni Core Web Vitals (C14) porque PageSpeed Insights no estaba disponible.';
  }

  function updatePsiInfo(run) {
    var li = run.view.psiInfo;
    if (run.psiMerged) {
      var p = run.psi.psi || {};
      if (!p.ok) {
        li.textContent = 'PageSpeed Insights: sin datos. ' + ((p.error && p.error.message) || '');
        return;
      }
      var parts = ['PageSpeed Insights: estrategia móvil'];
      if (p.lighthouse) parts.push('Lighthouse ' + p.lighthouse);
      if (p.fecha) {
        var d = new Date(p.fecha);
        if (!isNaN(d.getTime())) parts.push('consultado el ' + d.toLocaleString('es-ES'));
      }
      li.textContent = parts.join(', ') + '.';
    } else if (run.psiFailure) {
      li.textContent = 'PageSpeed Insights: no disponible. ' + run.psiFailure + ' Contraste (C08) y Core Web Vitals (C14) no están incluidos.';
    }
  }

  function updateScores(run) {
    var p = run.data.puntuacion;
    var blocked = !!run.data.blocked;
    var host = run.view.scores;
    host.textContent = '';
    if (p.riesgo_alto) {
      host.appendChild(el('div', { class: 'notice notice--risk' }, [
        el('p', { text: p.riesgo_mensaje }),
        el('ul', {}, p.riesgo_motivos.map(function (m) { return el('li', { text: m }); }))
      ]));
    }
    host.appendChild(renderScores(p, blocked, psiScoreNote(run)));
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

  function renderScores(p, blocked, psiNote) {
    var list = el('ul', { class: 'scores' }, [
      scoreCard(i18n.areas.seo, p.areas.seo, false, blocked),
      scoreCard(i18n.areas.ia, p.areas.ia, false, blocked),
      scoreCard(i18n.areas.accesibilidad, p.areas.accesibilidad, false, blocked),
      scoreCard('Nota global', p.global, true, blocked)
    ]);
    var incomplete = !blocked && ['seo', 'ia', 'accesibilidad'].some(function (a) { return p.areas[a].incompleta; });
    return el('section', { 'aria-labelledby': 'scores-title' }, [
      el('h3', { id: 'scores-title', text: 'Puntuaciones' }),
      psiNote && !blocked ? el('p', { class: 'help', text: psiNote }) : null,
      list,
      incomplete ? el('p', { class: 'help', text: i18n.nota_incompleta }) : null
    ]);
  }

  /** Repinta los hallazgos conservando qué desplegables estaban abiertos y el foco. */
  function updateFindings(run) {
    var host = run.view.findings;
    var open = {};
    Array.prototype.forEach.call(host.querySelectorAll('details[open]'), function (d) { open[d.getAttribute('data-check')] = true; });
    var active = document.activeElement;
    var focusedCheck = active && host.contains(active) && active.closest('details') ? active.closest('details').getAttribute('data-check') : null;

    host.textContent = '';
    host.appendChild(renderFindings(run.data.checks, psiPending(run)));

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

  function renderFindings(checks, pending) {
    var groups = [
      { id: 'g-fail', title: 'Problemas', items: [] },
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

    var section = el('section', { 'aria-labelledby': 'findings-title' }, [el('h3', { id: 'findings-title', text: 'Hallazgos' })]);
    if (pending) {
      section.appendChild(el('h4', { id: 'g-pending' }, ['Pendientes de PageSpeed Insights ', el('span', { class: 'group-count', text: '(' + PSI_IDS.length + ')' })]));
      section.appendChild(el('ul', { class: 'pending-list', 'aria-labelledby': 'g-pending' }, PSI_IDS.map(function (id) {
        return el('li', { text: i18n.psi_checks[id] + ' (' + id + ')' });
      })));
    }
    groups.forEach(function (g) {
      if (!g.items.length) return;
      section.appendChild(el('h4', { id: g.id }, [g.title + ' ', el('span', { class: 'group-count', text: '(' + g.items.length + ')' })]));
      section.appendChild(el('ul', { class: 'findings', 'aria-labelledby': g.id }, g.items.map(function (c) {
        return el('li', {}, [renderCheck(c)]);
      })));
    });
    return section;
  }

  function renderCheck(c) {
    var stateKey = c.aplica === false ? 'NA' : c.estado;
    var stateLabel = c.aplica === false ? 'No aplica' : (i18n.estados[c.estado] || c.estado);
    var summary = el('summary', {}, [
      el('span', { class: 'badge badge--' + stateKey, text: stateLabel }),
      el('span', { class: 'finding__name', text: c.nombre || c.id }),
      el('span', { class: 'finding__meta', text: c.id + (c.severidad ? ' · Gravedad ' + (i18n.severidades[c.severidad] || c.severidad).toLowerCase() : '') }),
      c.detalle ? el('span', { class: 'finding__summary', text: c.detalle }) : null
    ]);

    var dl = el('dl');
    function row(term, valueNode) {
      dl.appendChild(el('dt', { text: term }));
      dl.appendChild(el('dd', {}, [valueNode]));
    }
    // Lo medido
    row('Valor medido', fmtValue(c.valor_medido));
    if (c.umbral) row('Umbral', document.createTextNode(c.umbral));
    if (c.criterio) row('Criterio', document.createTextNode(c.criterio));
    if (c.evidencia && c.evidencia.length) {
      row('Evidencia', el('ul', { class: 'evidence' }, c.evidencia.map(function (e) {
        return el('li', {}, [
          e.selector ? el('code', { text: e.selector }) : null,
          e.linea ? document.createTextNode(' (línea ' + e.linea + ')') : null,
          el('pre', {}, [el('code', { text: e.extracto })])
        ]);
      })));
      if (c.ocurrencias > c.evidencia.length) {
        dl.lastChild.appendChild(el('p', { class: 'help', text: 'Se muestran ' + c.evidencia.length + ' de ' + c.ocurrencias + ' casos.' }));
      }
    }
    // Lo recomendado (no penaliza)
    if (c.recomendacion) row('Recomendación', document.createTextNode(c.recomendacion));
    if (c.impacto && c.esfuerzo && (c.estado === 'NO_CUMPLE' || c.estado === 'AVISO')) {
      row('Impacto y esfuerzo', document.createTextNode('Impacto ' + i18n.niveles[c.impacto].toLowerCase() + ', esfuerzo ' + i18n.niveles[c.esfuerzo].toLowerCase()));
    }
    if (c.requiere_revision_manual) {
      row('Revisión manual', el('span', { class: 'manual', text: 'Esta comprobación requiere además revisión manual.' }));
    }

    return el('details', { class: 'finding', 'data-check': c.id }, [summary, el('div', { class: 'finding__body' }, [dl])]);
  }
})();
