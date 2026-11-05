/* Analizador web — lógica de la pantalla. Sin librerías.
 * Todo dato procedente del servidor o de la web analizada se inserta con textContent, nunca con innerHTML. */
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
  var SEVERITY_ORDER = { CRITICO: 0, ALTO: 1, MEDIO: 2, BAJO: 3 };
  var LEVEL_ORDER = { ALTO: 0, MEDIO: 1, BAJO: 2 };
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

  function setLoading(on) {
    button.disabled = on;
    results.setAttribute('aria-busy', on ? 'true' : 'false');
    statusEl.classList.toggle('is-loading', on);
    statusEl.textContent = on ? 'Analizando la página… puede tardar unos segundos.' : '';
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

  // ---------- Envío ----------

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
    results.hidden = true;
    results.textContent = '';
    setLoading(true);

    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, REQUEST_TIMEOUT_MS);

    fetch('api/analyze.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ url: url, noindex_intentional: noindexIntentional }),
      signal: controller.signal,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().catch(function () {
        throw new Error('Respuesta no válida del servidor (HTTP ' + res.status + ').');
      });
    }).then(function (data) {
      setLoading(false);
      if (!data || !data.ok) {
        handleApiError(data && data.error);
        return;
      }
      renderResults(data);
    }).catch(function (e) {
      setLoading(false);
      if (e && e.name === 'AbortError') {
        showError('El análisis ha tardado demasiado y se ha cancelado.', ERROR_HINTS.TIMEOUT);
      } else if (e && /Respuesta no válida/.test(e.message)) {
        showError(e.message, ERROR_HINTS.INTERNAL_ERROR);
      } else {
        showError('No se ha podido contactar con el servidor del analizador.', 'Comprueba tu conexión e inténtalo de nuevo.');
      }
    }).finally(function () {
      clearTimeout(timer);
    });
  }

  // ---------- Resultados ----------

  function renderResults(data) {
    var p = data.puntuacion;
    var title = el('h2', { id: 'results-title', tabindex: '-1', text: 'Resultados' });
    results.appendChild(title);

    var meta = el('ul', { class: 'meta-list' }, [
      el('li', {}, ['URL analizada: ', el('strong', { text: data.url })])
    ]);
    if (data.fetch && data.fetch.redirects && data.fetch.redirects.length) {
      meta.appendChild(el('li', { text: 'Se siguieron ' + data.fetch.redirects.length + ' redirección(es) desde ' + data.fetch.requested_url + '.' }));
    }
    if (data.fetch && data.fetch.timings) {
      meta.appendChild(el('li', { text: 'Tiempo de descarga: ' + fmtNumber(data.fetch.timings.total_ms / 1000, 1) + ' s.' }));
    }
    results.appendChild(meta);

    if (data.blocked) {
      results.appendChild(el('div', { class: 'notice notice--warn' }, [
        el('p', {}, [el('strong', { text: 'Acceso bloqueado. ' }), data.blocked.message]),
        el('p', { text: 'Las comprobaciones que dependen del contenido de la página aparecen como «Sin datos».' })
      ]));
    }
    if (p.riesgo_alto) {
      results.appendChild(el('div', { class: 'notice notice--risk' }, [
        el('p', { text: p.riesgo_mensaje }),
        el('ul', {}, p.riesgo_motivos.map(function (m) { return el('li', { text: m }); }))
      ]));
    }

    results.appendChild(renderScores(p, !!data.blocked));
    results.appendChild(renderFindings(data.checks));

    results.hidden = false;
    statusEl.textContent = 'Análisis completado.';
    title.focus();
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

  function renderScores(p, blocked) {
    var list = el('ul', { class: 'scores' }, [
      scoreCard(i18n.areas.seo, p.areas.seo, false, blocked),
      scoreCard(i18n.areas.ia, p.areas.ia, false, blocked),
      scoreCard(i18n.areas.accesibilidad, p.areas.accesibilidad, false, blocked),
      scoreCard('Nota global', p.global, true, blocked)
    ]);
    var incomplete = !blocked && ['seo', 'ia', 'accesibilidad'].some(function (a) { return p.areas[a].incompleta; });
    return el('section', { 'aria-labelledby': 'scores-title' }, [
      el('h3', { id: 'scores-title', text: 'Puntuaciones' }),
      list,
      incomplete ? el('p', { class: 'help', text: i18n.nota_incompleta }) : null
    ]);
  }

  function bySeverity(a, b) {
    return (SEVERITY_ORDER[a.severidad] - SEVERITY_ORDER[b.severidad])
      || (LEVEL_ORDER[a.impacto] - LEVEL_ORDER[b.impacto])
      || String(a.id).localeCompare(String(b.id));
  }

  function renderFindings(checks) {
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

    return el('details', { class: 'finding' }, [summary, el('div', { class: 'finding__body' }, [dl])]);
  }
})();
