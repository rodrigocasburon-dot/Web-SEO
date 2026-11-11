/* Analizador web — lógica de la pantalla. Sin librerías.
 * Todo dato procedente del servidor o de la web analizada se inserta con textContent, nunca con innerHTML.
 *
 * Flujo: api/analyze.php y api/psi.php se piden en paralelo. Se pinta el informe en cuanto llega el
 * análisis, con puntuación provisional; cuando llega PageSpeed (C08, C14) se recalculan puntuación e
 * informe en el servidor (api/score.php, mismo Scorer y mismo Report) y se actualiza sin mover el foco.
 *
 * Informe: puntuaciones con cobertura, resumen ejecutivo breve, descargas, un bloque plegable por área
 * con sus puntos, revisiones manuales y, plegados, recomendaciones priorizadas, plan de acción,
 * simulación y análisis complementario; después, limitaciones y evidencias (index.php). El glosario
 * no se muestra (renderGlossary() y lang/es.php 'glosario' se conservan).
 *
 * Comparar dos URLs (función 10): cada URL se analiza con sus propias peticiones (una URL por petición) y
 * assets/js/compare.js pone los dos resultados lado a lado: notas, diferencia y estado de cada comprobación. */
(function () {
  'use strict';

  var i18n = JSON.parse(document.getElementById('i18n').textContent);
  // Perfil de lectura y pantalla por capas (assets/js/perfiles.js, assets/js/dashboard.js)
  if (window.RadarDashboard) window.RadarDashboard.init(i18n);
  var form = document.getElementById('analyze-form');
  var input = document.getElementById('url');
  var noindex = document.getElementById('noindex');
  var keywordInput = document.getElementById('keyword');
  var button = document.getElementById('submit');
  var statusEl = document.getElementById('status');
  var errorEl = document.getElementById('error');
  var urlError = document.getElementById('url-error');
  var results = document.getElementById('results');
  // v4: descargas al final de la pantalla (index.php #descargas)
  var downloadsSection = document.getElementById('descargas');
  var downloadsUrl = document.getElementById('descargas-url');
  var downloadsSite = document.getElementById('descargas-sitio');
  // 10. Comparar dos URLs
  var modeCompare = document.getElementById('mode-compare');
  // v4: la pantalla solo tiene el campo de la URL; el modo comparar y las opciones ya no se muestran
  var input2 = document.getElementById('url2');
  var url2Field = document.getElementById('url2-field');
  var url2Error = document.getElementById('url2-error');
  var urlLabel = form.querySelector('label[for="url"]');
  var keywordField = document.getElementById('keyword-field');
  var noindexCompare = document.getElementById('noindex-compare');
  var localBusiness = document.getElementById('local-business');
  var localField = document.getElementById('local-field');
  var moreOptions = document.getElementById('more-options');
  var URL_NAMES = ['Primera URL', 'Segunda URL'];
  // v4 · Tipo de análisis (rápido / profundo)
  function currentModo() {
    var r = form.querySelector('input[name="modo"]:checked');
    return r ? r.value : 'rapido';
  }

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

  /** Un solo formato de fecha en toda la herramienta (informe, historial y comparación): 11/11/2026, 10:29, hora de Madrid. */
  function fmtDate(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    var opts = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' };
    try {
      return d.toLocaleString('es-ES', Object.assign({ timeZone: 'Europe/Madrid' }, opts));
    } catch (e) {
      return d.toLocaleString('es-ES', opts);
    }
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
    // Con un análisis en marcha o un informe en pantalla se ocultan la ilustración y los bloques de presentación
    if (on) document.body.classList.add('has-report');
    results.setAttribute('aria-busy', on ? 'true' : 'false');
    setStatus(on ? 'Analizando la página, sus enlaces y su sitemap… puede tardar hasta medio minuto.' : '', on);
  }

  function clearErrors() {
    errorEl.hidden = true;
    errorEl.textContent = '';
    urlError.hidden = true;
    urlError.textContent = '';
    input.removeAttribute('aria-invalid');
    if (url2Error) {
      url2Error.hidden = true;
      url2Error.textContent = '';
      input2.removeAttribute('aria-invalid');
    }
  }

  /** Error en un campo de URL: el primero o, con field = 2, el segundo (modo comparar). */
  function showFieldError(message, field) {
    var target = field === 2 ? input2 : input;
    var box = field === 2 ? url2Error : urlError;
    box.textContent = message;
    box.hidden = false;
    target.setAttribute('aria-invalid', 'true');
    target.focus();
  }

  function showError(message, hint) {
    errorEl.textContent = '';
    errorEl.appendChild(el('p', { text: message }));
    if (hint) errorEl.appendChild(el('p', { text: hint }));
    errorEl.hidden = false;
    errorEl.focus();
  }

  /** field y prefix solo en el modo comparar: campo de la URL que falló y texto que la identifica. */
  function handleApiError(err, field, prefix) {
    var code = (err && err.code) || 'UNKNOWN';
    var message = (err && err.message) || 'No se ha podido completar el análisis.';
    if (FIELD_ERRORS.indexOf(code) !== -1) {
      // URL rechazada antes de analizar nada: la portada vuelve a su estado inicial (sin modo informe)
      if (results.hidden) document.body.classList.remove('has-report');
      showFieldError(message, field);
      return;
    }
    showError((prefix || '') + message, ERROR_HINTS[code] || '');
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
    if (isCompareMode()) {
      var url2 = input2.value.trim();
      if (!url) {
        showFieldError('Escribe la primera URL que quieres comparar.');
        return;
      }
      if (!url2) {
        showFieldError('Escribe la segunda URL que quieres comparar.', 2);
        return;
      }
      if (sameAddress(url, url2)) {
        showFieldError('Las dos URL son iguales. Escribe dos direcciones distintas.', 2);
        return;
      }
      compareUrls(url, url2, noindex.checked);
      return;
    }
    if (!url) {
      showFieldError('Escribe la URL de la web que quieres analizar.');
      return;
    }
    // Se analiza a fondo la página y después el sitio entero. Opciones recuperadas: palabra clave y noindex
    analyze(url, !!(noindex && noindex.checked), keywordInput ? keywordInput.value.trim() : '', false);
  });

  // ---------- 10. Modo «Comparar dos URLs» ----------

  function isCompareMode() {
    return !!modeCompare && modeCompare.checked;
  }

  /** Muestra u oculta los campos de cada modo. La palabra clave no se compara: se oculta al comparar. */
  function syncMode() {
    if (!modeCompare) return;
    var cmp = isCompareMode();
    url2Field.hidden = !cmp;
    keywordField.hidden = cmp;
    if (localField) localField.hidden = cmp;
    // Al comparar se analizan solo las dos páginas: el tipo de análisis del sitio no aplica
    var modoField = document.getElementById('modo-field');
    if (modoField) modoField.hidden = cmp;
    noindexCompare.hidden = !cmp;
    if (cmp) noindex.setAttribute('aria-describedby', 'noindex-compare');
    else noindex.removeAttribute('aria-describedby');
    urlLabel.textContent = cmp ? 'Primera URL' : 'Empecemos por tu URL';
    input2.required = cmp;
    button.textContent = cmp ? 'Comparar' : 'Analizar';
    clearErrors();
  }
  Array.prototype.forEach.call(form.querySelectorAll('input[name="accion"]'), function (r) {
    r.addEventListener('change', syncMode);
  });
  syncMode();   // el navegador puede restaurar el modo al volver a la página
  // «Más opciones» empieza plegado, salvo que el navegador haya restaurado alguna opción distinta de la habitual
  if (moreOptions && (isCompareMode() || noindex.checked || keywordInput.value.trim())) moreOptions.open = true;

  // v4 · Comparar dos auditorías del historial (index.php?comparar=ID1,ID2 o comparar[]=ID1&comparar[]=ID2)
  (function () {
    var qs = new URLSearchParams(window.location.search);
    var raw = qs.getAll('comparar[]').concat(qs.getAll('comparar').join(',').split(',')).filter(Boolean);
    if (!raw.length) return;
    var ids = [];
    raw.forEach(function (x) { var n = parseInt(x, 10); if (n > 0 && ids.indexOf(n) < 0) ids.push(n); });
    if (ids.length !== 2) {
      showError('Para comparar hay que elegir exactamente dos análisis.', 'Vuelve al historial y marca dos casillas.');
      return;
    }
    // Se llama cuando ya está definido todo el módulo
    setTimeout(function () { compareSaved(ids); }, 0);
  })();

  // v3.0 · Ver un informe guardado en el historial (index.php?informe=ID): se pinta igual que un análisis recién hecho
  (function () {
    var savedId = parseInt(new URLSearchParams(window.location.search).get('informe') || '', 10);
    if (!(savedId > 0)) return;
    setLoading(true);
    setStatus('Cargando el informe guardado…', true);
    postJson('api/history_report.php', { id: savedId }, new AbortController(), REQUEST_TIMEOUT_MS).then(function (r) {
      setLoading(false);
      var d = r.data || {};
      // Informes antiguos de «Analizar sitio» (v3.0): solo tienen la parte del sitio
      if (d.ok && d.tipo === 'sitio' && d.informe && d.informe.home && window.RadarSite) {
        input.value = d.informe.home.url || '';
        setStatus('Informe guardado del ' + fmtDate(d.fecha) + '. Muestra cómo estaba el sitio ese día; para verlo hoy, pulsa «Analizar».', false);
        window.RadarSite.showSaved(d.informe, d.fecha);
        return;
      }
      if (!d.ok || d.tipo !== 'url' || !d.informe || !d.informe.data) {
        setStatus('', false);
        showError((d.error && d.error.message) || 'No se ha podido abrir este informe.', 'Vuelve al historial y elige otro.');
        return;
      }
      var inf = d.informe;
      input.value = inf.data.url || '';
      var run = {
        data: inf.data, psi: inf.psi, psiMerged: !!inf.psiMerged, psiFailure: inf.psiFailure || null, psiDone: true,
        localBusiness: false, view: null, cancel: function () {}
      };
      renderResults(run);
      // v4: el análisis del sitio entero va en la misma fila del historial
      if (inf.sitio && window.RadarSite) {
        window.RadarSite.showSaved(inf.sitio, d.fecha);
      }
      setStatus('Informe guardado del ' + fmtDate(d.fecha) + '. Muestra cómo estaba la web ese día; para verla hoy, pulsa «Analizar».', false);
    }, function () {
      setLoading(false);
      setStatus('', false);
      showError('No se ha podido cargar el informe guardado.', 'Comprueba tu conexión e inténtalo de nuevo.');
    });
  })();

  /** Misma dirección escrita (sin esquema se supone https, como en el servidor). */
  function sameAddress(a, b) {
    var norm = function (u) {
      var s = /^[a-z][a-z0-9+.\-]*:\/\//i.test(u) ? u : 'https://' + u;
      try { return new URL(s).href; } catch (e) { return s.toLowerCase(); }
    };
    return norm(a) === norm(b);
  }

  function analyze(url, noindexIntentional, keyword, isLocal) {
    if (current) current.cancel();
    if (window.RadarSite) window.RadarSite.cancel();
    results.hidden = true;
    results.textContent = '';
    clearDownloads();
    setLoading(true);

    var run = {
      analyzeCtrl: new AbortController(),
      psiCtrl: new AbortController(),
      data: null,          // respuesta de analyze.php (checks, puntuacion e informe se sustituyen al llegar PageSpeed)
      psi: null,           // respuesta de psi.php (ok: true)
      psiFailure: null,    // mensaje si psi.php no se pudo usar
      psiDone: false,
      localBusiness: !!isLocal,   // dato indicado por la persona; no interviene en ninguna comprobación
      view: null,          // contenedores de los apartados que se actualizan
      cancel: function () { run.analyzeCtrl.abort(); run.psiCtrl.abort(); if (run.finish) run.finish(); }
    };
    // v4: se resuelve cuando el informe de la URL está terminado (con PageSpeed o sin él); el sitio espera a esto para guardar
    run.finished = new Promise(function (resolve) { run.finish = resolve; });
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
      // v4: a la vez, se recorre el sitio entero (assets/js/site.js) y se guarda en la misma fila del historial
      if (window.RadarSite) {
        var h = r.data.historial;
        window.RadarSite.start(r.data.url, {
          modo: currentModo(),
          enlace: h && h.activo && h.enlace ? { id: h.id, expira: h.enlace.expira, firma: h.enlace.firma } : null,
          esperar: run.finished
        });
      }
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
      run.finish();
      return;
    }
    var merged = mergedPsiChecks(run);
    postJson('api/score.php', scoreBody(run, merged), new AbortController(), REQUEST_TIMEOUT_MS).then(function (r) {
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
      run.finish();
    }).catch(function () {
      if (run !== current) return;
      run.psiFailure = 'No se pudo recalcular la puntuación con los datos de PageSpeed Insights.';
      run.psi = null;
      updateReport(run);
      setStatus('Análisis completado. ' + run.psiFailure + ' Se mantiene la puntuación sin contraste ni Core Web Vitals.', false);
      current = null;
      run.finish();
    });
  }

  /** Comprobaciones del análisis con C08 y C14 sustituidas por las de PageSpeed. */
  function mergedPsiChecks(run) {
    return run.data.checks.filter(function (c) { return PSI_IDS.indexOf(c.id) === -1; }).concat(run.psi.checks);
  }

  /** Cuerpo de api/score.php: solo lo que necesita el Scorer. */
  function scoreBody(run, merged) {
    return {
      checks: merged.map(function (c) {
        return { id: c.id, estado: c.estado, ocurrencias: c.ocurrencias || 0, aplica: c.aplica !== false };
      }),
      bloqueado: !!run.data.blocked,
      truncado: !!(run.data.fetch && run.data.fetch.truncated),
      // Historial (Fase 4): el servidor solo actualiza la fila si las dos firmas son válidas
      historial: run.data.historial && run.data.historial.activo && run.psi && run.psi.firma
        ? { id: run.data.historial.id, firma_analisis: run.data.historial.firma, firma_psi: run.psi.firma }
        : null
    };
  }

  // ---------- 10. Comparar dos URLs: peticiones ----------

  function newCompareRun(url) {
    return {
      url: url,
      analyzeCtrl: new AbortController(),
      psiCtrl: new AbortController(),
      data: null,
      psi: null,
      psiFailure: null,
      psiDone: false,
      psiMerged: false,
      settled: false       // PageSpeed incorporado o descartado
    };
  }

  /**
   * Analiza las dos URLs, cada una con sus propias peticiones (analyze, psi y score), igual que el análisis
   * normal. Primero se piden los dos análisis y después PageSpeed. Si una URL falla, se cancela todo.
   */
  function compareUrls(urlA, urlB, noindexIntentional) {
    if (current) current.cancel();
    results.hidden = true;
    results.textContent = '';
    clearDownloads();
    setLoading(true);
    setStatus('Analizando las dos páginas, sus enlaces y sus sitemaps… puede tardar hasta un minuto.', true);

    var cmp = {
      runs: [newCompareRun(urlA), newCompareRun(urlB)],
      view: null,
      cancel: function () {
        cmp.runs.forEach(function (r) { r.analyzeCtrl.abort(); r.psiCtrl.abort(); });
      }
    };
    current = cmp;

    cmp.runs.forEach(function (run, i) {
      postJson('api/analyze.php', { url: run.url, noindex_intentional: noindexIntentional, palabra_clave: '' }, run.analyzeCtrl, REQUEST_TIMEOUT_MS).then(function (r) {
        if (cmp !== current) return;
        if (!r.data || !r.data.ok) {
          failCompare(cmp, i, (r.data && r.data.error) || {}, null);
          return;
        }
        run.data = r.data;
        if (run.psiDone) settleComparePsi(cmp, run);
        if (cmp.runs.every(function (x) { return x.data; })) {
          setLoading(false);
          renderComparison(cmp);
          compareStatus(cmp);
        }
      }, function (e) {
        if (cmp !== current) return;
        failCompare(cmp, i, null, e);
      });
    });

    cmp.runs.forEach(function (run) {
      postJson('api/psi.php', { url: run.url }, run.psiCtrl, PSI_TIMEOUT_MS).then(function (r) {
        if (r.data && r.data.ok) run.psi = r.data;
        else run.psiFailure = (r.data && r.data.error && r.data.error.message) || 'Error al consultar PageSpeed Insights.';
      }, function (e) {
        run.psiFailure = e && e.name === 'AbortError'
          ? 'PageSpeed Insights no respondió a tiempo.'
          : 'No se ha podido contactar con el servidor del analizador para consultar PageSpeed Insights.';
      }).then(function () {
        if (cmp !== current) return;
        run.psiDone = true;
        if (run.data) settleComparePsi(cmp, run);
      });
    });
  }

  /** Incorpora C08 y C14 de una URL y recalcula su puntuación (mismo Scorer, api/score.php). */
  function settleComparePsi(cmp, run) {
    var done = function () {
      if (cmp !== current) return;
      run.settled = true;
      if (cmp.view) updateComparison(cmp);
      compareStatus(cmp);
    };
    if (!run.psi) {
      done();
      return;
    }
    var merged = mergedPsiChecks(run);
    postJson('api/score.php', scoreBody(run, merged), new AbortController(), REQUEST_TIMEOUT_MS).then(function (r) {
      if (!r.data || !r.data.ok || !r.data.informe) throw new Error('score');
      run.data.checks = merged;
      run.data.puntuacion = r.data.puntuacion;
      run.data.informe = r.data.informe;
      run.psiMerged = true;
    }).catch(function () {
      run.psiFailure = 'No se pudo recalcular la puntuación con los datos de PageSpeed Insights.';
      run.psi = null;
    }).then(done);
  }

  function compareStatus(cmp) {
    if (!cmp.view) return;
    if (cmp.runs.some(function (r) { return !r.settled; })) {
      setStatus('Análisis completados. Consultando PageSpeed Insights para las dos URL (contraste y Core Web Vitals)… puede tardar hasta un minuto y medio.', true);
      return;
    }
    var notes = [];
    cmp.runs.forEach(function (r, i) {
      if (r.data.blocked) return;
      if (!r.psiMerged) notes.push(URL_NAMES[i] + ': ' + r.psiFailure + ' Su nota no incluye contraste ni Core Web Vitals.');
      else if (!(r.psi.psi && r.psi.psi.ok)) notes.push(URL_NAMES[i] + ': PageSpeed Insights no devolvió datos; contraste y Core Web Vitals figuran como «Sin datos».');
    });
    setStatus('Comparación completada. ' + (notes.length ? notes.join(' ') : 'Notas actualizadas con los datos de PageSpeed Insights.'), false);
    current = null;
  }

  /** Error en una de las dos URL: se cancela la otra y se indica cuál ha fallado. */
  function failCompare(cmp, i, apiError, e) {
    cmp.cancel();
    current = null;
    setLoading(false);
    results.hidden = true;
    results.textContent = '';
    var prefix = URL_NAMES[i] + ' (' + cmp.runs[i].url + '): ';
    if (apiError) {
      handleApiError(apiError, i === 1 ? 2 : 1, prefix);
    } else if (e && e.name === 'AbortError') {
      showError(prefix + 'el análisis ha tardado demasiado y se ha cancelado.', ERROR_HINTS.TIMEOUT);
    } else if (e && /Respuesta no válida/.test(e.message)) {
      showError(prefix + e.message, ERROR_HINTS.INTERNAL_ERROR);
    } else {
      showError('No se ha podido contactar con el servidor del analizador.', 'Comprueba tu conexión e inténtalo de nuevo.');
    }
  }

  // ---------- Informe ----------
  // Orden: puntuaciones con cobertura, resumen ejecutivo breve, descargas, un bloque plegable por área
  // (fallos, avisos y sin datos; lo que no tiene errores o no aplica, plegado), revisiones manuales y,
  // plegados, recomendaciones priorizadas, plan de acción, simulación y análisis complementario.
  // Limitaciones y evidencias van después, fuera de #results (index.php). Cada punto aparece una sola
  // vez, en el bloque del área con más peso (informe.bloque), con etiquetas de las otras áreas.

  var AREAS = ['seo', 'ia', 'accesibilidad'];
  // Componentes visuales del informe (assets/js/rw-componentes.js, docs/COMPONENTES.md)
  var RW = window.RadarComponents;

  function reportSection(id, title) {
    var body = el('div');
    var node = el('section', { id: id, class: 'report-section', 'aria-labelledby': id + '-t' }, [
      el('h3', { id: id + '-t', text: title }),
      body
    ]);
    return { node: node, body: body };
  }

  /**
   * Título de un apartado plegable para la navegación por encabezados. Va fuera del <summary>: dentro,
   * algunos lectores de pantalla (p. ej., VoiceOver en Safari) lo anuncian solo como botón. Está oculto a
   * la vista porque el <summary> muestra el mismo texto.
   */
  function foldHeading(tag, text, id) {
    return el(tag, { class: 'visually-hidden', id: id || null, text: text });
  }

  /** Apartado plegable: título (h3) para lectores de pantalla y <details> con el mismo texto en el <summary>. */
  function foldSection(id, title) {
    var body = el('div', { class: 'fold__body' });
    var node = el('div', { class: 'fold-wrap' }, [
      foldHeading('h3', title),
      el('details', { class: 'fold', id: id, 'data-key': id }, [
        el('summary', {}, [el('span', { class: 'fold__title', text: title })]),
        body
      ])
    ]);
    return { node: node, body: body };
  }

  function plural(n, one, many) {
    return n + ' ' + (n === 1 ? one : many);
  }

  /** Área con más peso de una comprobación (empates: orden de AREAS). Igual que Report::primaryArea(). */
  function primaryArea(pilares) {
    var best = AREAS[0];
    var max = -1;
    AREAS.forEach(function (a) {
      var w = Number((pilares || {})[a] || 0);
      if (w > max) { max = w; best = a; }
    });
    return best;
  }

  /**
   * v4 · Informe de la URL, en sencillo: la tarjeta de la URL y la primera nota (tres tarjetas: SEO, GEO y UX)
   * de la página escrita. Debajo, el estado (#status) y el sitio entero (assets/js/site.js), que es donde
   * se ven las notas finales y lo que falla. Las descargas van al final (#descargas).
   */
  function renderResults(run) {
    var data = run.data;
    // Botón de PDF de la cabecera (mismo PDF que en «Descargas», con el perfil elegido)
    var headPdf = function (text, make) {
      var b = el('button', { type: 'button', class: 'rw-btn', text: text });
      b.addEventListener('click', function () { pdfButton(b, function () { return make(run); }); });
      return b;
    };
    var title = el('h2', { id: 'results-title', tabindex: '-1', text: 'Informe' });
    run.view = {};
    run.view.state = el('strong');
    var openLink = /^https?:\/\//i.test(data.url)
      ? el('a', { class: 'rw-btn', href: data.url, rel: 'noopener noreferrer', target: '_blank' }, [
        'Abrir la web', el('span', { class: 'visually-hidden', text: ' analizada (en otra pestaña)' })])
      : null;
    // 1. Tarjeta de la URL: la URL va como texto (no enlace) y «Abrir» la abre aparte
    results.appendChild(el('div', { class: 'report-head rw-context' }, [
      title,
      el('p', { class: 'rw-context__url' }, [el('span', { class: 'visually-hidden', text: 'URL analizada: ' }), data.url]),
      el('ul', { class: 'rw-context__meta' }, [
        el('li', {}, ['Fecha: ', el('strong', { text: fmtDate(data.fecha) })]),
        el('li', {}, ['Fiabilidad de la nota de esta página: ', run.view.state])
      ]),
      el('p', { class: 'rw-context__actions' }, [openLink, headPdf('PDF · Informe', pdfReport), headPdf('PDF · Hoja de ruta', pdfRoadmap),
        el('a', { class: 'rw-btn', href: 'historial.php', text: 'Historial' }), el('a', { class: 'rw-btn', href: '#analyze-form', text: 'Nuevo análisis' })])
    ]));

    if (data.blocked) {
      results.appendChild(el('div', { class: 'notice notice--warn' }, [
        el('p', {}, [el('strong', { text: 'Acceso bloqueado. ' }), data.blocked.message]),
        el('p', { text: 'Las comprobaciones que dependen del contenido de la página aparecen como «Sin datos».' })
      ]));
    }

    // 2. Primera nota: análisis rápido de la página escrita, antes de recorrer el sitio
    var first = reportSection('sec-primera-nota', 'Primer análisis: la página que has escrito');
    first.body.appendChild(el('p', { class: 'help', text: 'Nota rápida de esta página (su HTML y PageSpeed). Mientras tanto se recorre el resto del sitio: la nota final y la lista de lo que falla aparecen debajo al terminar.' }));
    run.view.scores = el('div');
    first.body.appendChild(run.view.scores);
    // La pantalla por capas (dashboard.js) sustituye a esta primera nota: tarjetas con rango y variación, sin nota global.
    // updateScores sigue escribiendo en este bloque (no se muestra) para no tocar el flujo de PageSpeed.
    // Perfil de lectura y lo que falla o falta por bloque (assets/js/dashboard.js)
    run.view.dash = el('div', { class: 'rw-dash', id: 'dashboard' });
    results.appendChild(run.view.dash);

    // Análisis complementario (no puntúa): vista de un bot, vista previa, bots de IA, palabra clave, esquema,
    // tono del contenido, pistas para la revisión manual y transferencia. Se pinta una vez: no cambia con PageSpeed.
    var extra = foldSection('sec-complementario', 'Análisis complementario (no puntúa)');
    renderComplementary(extra.body, data.complementario);
    results.appendChild(extra.node);

    // Identidad corporativa detectada de la web analizada (DESIGN.md §7): informativa, no puntúa
    if (data.complementario && data.complementario.identidad) {
      var ident = foldSection('sec-identidad', 'Identidad corporativa detectada (no puntúa)');
      renderIdentity(ident.body, data.complementario.identidad);
      results.appendChild(ident.node);
    }

    // Recuperado de la v4.0: revisiones manuales, recomendaciones con su propuesta de corrección, plan por impacto
    // y esfuerzo, y simulación de mejora. Las tres últimas se repintan al llegar PageSpeed (updateReport).
    if (!data.blocked) {
      var manual = foldSection('sec-revisiones', 'Revisiones manuales (no puntúan)');
      renderManualReviews(manual.body, data.complementario);
      results.appendChild(manual.node);
      var recs = foldSection('sec-recomendaciones', 'Recomendaciones priorizadas y propuestas de corrección');
      run.view.recs = recs.body;
      results.appendChild(recs.node);
      var plan = foldSection('sec-plan', 'Plan de acción por impacto y esfuerzo');
      run.view.plan = plan.body;
      results.appendChild(plan.node);
      var sim = foldSection('sec-simulacion', 'Simulación de mejora (estimación)');
      run.view.sim = sim.body;
      results.appendChild(sim.node);
      updateExtras(run);
    }

    // Descargas de la URL, al final de la pantalla
    downloadsUrl.textContent = '';
    downloadsUrl.appendChild(downloadBar(run));
    downloadsSection.hidden = false;

    updateReport(run);

    results.hidden = false;
    results.setAttribute('aria-busy', 'false');
    title.focus();
  }

  /** v4 · Vacía las descargas del análisis anterior. */
  function clearDownloads() {
    downloadsUrl.textContent = '';
    downloadsSite.textContent = '';
    downloadsSection.hidden = true;
  }

  /** Barra de descargas: se generan en el navegador con los datos actuales (tras PageSpeed, ya actualizados). */
  function downloadBar(run) {
    var X = window.AnalyzerExport;
    var csvButton = el('button', { type: 'button', class: 'rw-btn', text: 'CSV · Esta página' });
    csvButton.addEventListener('click', function () {
      var head = X.corporateHeader('Comprobaciones de la página escrita', [['URL analizada', run.data.url], ['Fecha del análisis', fmtDate(run.data.fecha)],
        ['Notas', run.data.blocked ? 'Acceso bloqueado' : AREAS.map(function (a) { return i18n.areas[a] + ' ' + fmtNumber(run.data.puntuacion.areas[a].redondeada, 1); }).concat('Global ' + fmtNumber(run.data.puntuacion.global.redondeada, 1)).join(' · ')]]);
      X.download('proconsi-radar-' + X.fileName(run.data.url, new Date(), 'csv'), 'text/csv;charset=utf-8', X.toCsv(head.concat(X.buildRows(run.data.checks, i18n))));
    });
    var jsonButton = el('button', { type: 'button', class: 'rw-btn', text: 'JSON · Esta página' });
    jsonButton.addEventListener('click', function () {
      X.download('proconsi-radar-' + X.fileName(run.data.url, new Date(), 'json'), 'application/json;charset=utf-8',
        X.buildJson(run.data, { provisional: psiPending(run) && !run.data.blocked }));
    });
    var reportButton = el('button', { type: 'button', class: 'rw-btn', text: 'PDF · Informe' });
    reportButton.addEventListener('click', function () { pdfButton(reportButton, function () { return pdfReport(run); }); });
    var roadmapButton = el('button', { type: 'button', class: 'rw-btn', text: 'PDF · Hoja de ruta' });
    roadmapButton.addEventListener('click', function () { pdfButton(roadmapButton, function () { return pdfRoadmap(run); }); });
    return el('div', {}, [
      el('h3', { text: 'Informe del análisis' }),
      el('div', { class: 'report-actions rw-context__actions' }, [reportButton, roadmapButton, csvButton, jsonButton]),
      el('p', { class: 'help', text: 'Informe: notas y todo lo que falla o falta. Hoja de ruta: qué corregir y en qué orden. CSV y JSON: todas las comprobaciones de la página con sus evidencias.' })
    ]);
  }

  /** Genera un PDF desde un botón: lo desactiva mientras tanto y avisa si algo falla. */
  function pdfButton(button, make) {
    if (!window.RadarPdf) return;
    var label = button.textContent;
    button.disabled = true;
    button.textContent = 'Generando PDF…';
    Promise.resolve().then(make).catch(function () {
      showError('No se ha podido generar el PDF.', 'Inténtalo de nuevo; si se repite, descarga el CSV.');
    }).then(function () {
      button.disabled = false;
      button.textContent = label;
    });
  }

  /** Área (SEO, GEO o UX) de una comprobación en el informe. */
  function areaOf(run, id) {
    return (run.data.informe.bloque || {})[id] || '';
  }

  /**
   * Informe y hoja de ruta en PDF (se descargan) con el perfil de lectura elegido: portada con índice, resumen
   * ejecutivo y secciones (assets/js/informes-pdf.js). Usan el mismo modelo que la pantalla (RadarDashboard).
   */
  function pdfDownload(run, kind) {
    if (!window.RadarInformesPdf || !window.RadarDashboard) return Promise.reject(new Error('Sin módulo de PDF'));
    var m = window.RadarDashboard.model();
    if (!m) return Promise.reject(new Error('Sin análisis'));
    return window.RadarInformesPdf.download(kind, m, window.RadarDashboard.profile(), i18n);
  }

  function pdfReport(run) { return pdfDownload(run, 'informe'); }

  function pdfRoadmap(run) { return pdfDownload(run, 'hoja'); }

  function updateReport(run) {
    updateScores(run);
    if (window.RadarDashboard && run.view && run.view.dash) window.RadarDashboard.render(run, run.view.dash);
    updateExtras(run);
  }

  /** Secciones recuperadas de la v4.0 (solo si ya están en pantalla). */
  function updateExtras(run) {
    if (!run.view || !run.view.recs || !run.data || !run.data.informe) return;
    updateRecommendations(run);
    updatePlan(run);
    updateSimulation(run);
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

  /** Comprobaciones de PageSpeed que aún no han llegado. */
  function pendingIds(run) {
    return psiPending(run) && !run.data.blocked ? PSI_IDS : [];
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

  /** Enlace a un punto de los bloques por área (abre los desplegables que lo contienen). */
  function findingLink(id, name) {
    return el('a', { href: '#hallazgo-' + id, text: name || id });
  }

  // Puntuaciones (DESIGN.md §4.2): las tres notas en tarjetas iguales, sin colores por valor, con su cobertura,
  // la diferencia con el análisis anterior y su evolución si hay historial. La global, en una línea de texto.
  function updateScores(run) {
    var p = run.data.puntuacion;
    var blocked = !!run.data.blocked;
    var host = run.view.scores;
    var cov = run.data.informe.cobertura || {};
    var pending = pendingIds(run);
    var prev = previousScores(run);
    host.textContent = '';
    var note = psiScoreNote(run);
    if (note) host.appendChild(el('p', { class: 'help', text: note }));

    var areaPending = function (a) {
      return pending.filter(function (id) { return ((i18n.psi_bloque || {})[id] || '') === a; }).length;
    };
    host.appendChild(el('ul', { class: 'rw-scores' }, AREAS.map(function (a) {
      return areaScoreCard(a, p.areas[a], blocked, areaCoverage(run, a, areaPending(a)), prev);
    })));
    if (!blocked && prev.length) {
      host.appendChild(el('p', { class: 'rw-help', text: 'La diferencia se calcula con el análisis anterior guardado de esta URL. Puede deberse a cambios en la página, en los datos de PageSpeed o en las comprobaciones: tómala con cautela.' }));
    }
    var all = (run.data.checks || []).filter(function (c) { return c.aplica !== false; });
    var done = all.filter(function (c) { return c.estado !== 'SIN_DATOS'; }).length;
    // Sin nota global en pantalla (decisión del grupo, 11/11/2026): el Scorer la sigue calculando, pero no se muestra
    host.appendChild(el('p', { class: 'rw-help', text: coverageText(done, all.length + pending.length, pending.length) + '.' }));
    updateContextState(run);
    var incomplete = !blocked && AREAS.some(function (a) { return p.areas[a].incompleta; });
    if (incomplete) host.appendChild(el('p', { class: 'help', text: i18n.nota_incompleta }));
    var rel = reliabilityText(run.data.informe.fiabilidad);
    if (rel) host.appendChild(el('p', { class: 'help', text: rel }));
  }

  /**
   * Fiabilidad de las notas (src/Reliability.php): estado del análisis, puntos evaluados sobre posibles, peso
   * evaluado y motivos. Solo informa: no cambia ninguna nota. Los informes guardados antes no la tienen.
   */
  function reliabilityText(f) {
    if (!f || !f.global) return '';
    var g = f.global;
    var names = { completo: 'completo', parcial: 'parcial', bloqueado: 'bloqueado' };
    var text = 'Fiabilidad de las notas · análisis ' + (names[f.estado] || f.estado) + ': evaluados ' + g.evaluados + ' de ' + g.posibles + ' puntos';
    if (g.peso_evaluado !== null && g.peso_evaluado !== undefined) text += ' (' + fmtNumber(g.peso_evaluado, 1) + ' % del peso de la nota)';
    text += g.sin_datos ? '; ' + g.sin_datos + ' sin datos, que no restan.' : '.';
    if (g.nivel) text += ' Nivel: ' + g.nivel + '.';
    if (f.motivos && f.motivos.length) text += ' ' + f.motivos.join(' ');
    return text;
  }

  function coverageText(done, total, pending) {
    return 'Evaluados ' + done + ' de ' + total + ' puntos' + (pending ? ' (' + plural(pending, 'pendiente', 'pendientes') + ' de PageSpeed)' : '');
  }

  /** Cobertura de un área («Evaluados X de Y puntos»), con las comprobaciones de PageSpeed aún pendientes. */
  function areaCoverage(run, area, pendingCount) {
    var c = (run.data.informe.cobertura || {})[area] || { evaluados: 0, total: 0 };
    return coverageText(c.evaluados, c.total + (pendingCount || 0), pendingCount || 0);
  }

  /** Análisis anteriores de la misma URL (historial), del más reciente al más antiguo; [] sin historial. */
  function previousScores(run) {
    var h = run.data.historial;
    return h && h.activo && h.anteriores ? h.anteriores : [];
  }

  /** Estado del análisis en el contexto (Reliability: completo, parcial o bloqueado). */
  function updateContextState(run) {
    if (!run.view.state) return;
    var f = run.data.informe.fiabilidad;
    var names = { completo: 'Completo', parcial: 'Parcial', bloqueado: 'Bloqueado' };
    var text = run.data.blocked ? 'Bloqueado' : (f && names[f.estado]) || '—';
    if (!run.data.blocked && pendingIds(run).length) text += ' (falta PageSpeed Insights)';
    run.view.state.textContent = text;
  }

  /**
   * Tarjeta de una nota: etiqueta, cifra grande sobre 100, medidor 0–100 (sin colores por valor), cobertura,
   * diferencia con el análisis anterior y minigráfico con 3 análisis o más.
   */
  function areaScoreCard(area, score, blocked, coverage, prev) {
    var label = i18n.areas[area];
    var sub = area === 'ia' ? 'Preparación técnica: no mide si los motores de IA citan la web.' : null;
    // Con acceso bloqueado no se ha evaluado el contenido: no se muestra una cifra que parezca un resultado
    if (blocked) {
      return el('li', { class: 'rw-card rw-score' }, [
        el('p', { class: 'rw-score__label', text: label }),
        sub ? el('p', { class: 'rw-score__sub', text: sub }) : null,
        el('p', { class: 'rw-score__value' }, ['—', el('span', { class: 'rw-score__max', text: ' sin datos' })]),
        el('p', { class: 'rw-tag', text: 'No calculable: acceso bloqueado' })
      ]);
    }
    var value = score.redondeada;
    var key = 'nota_' + area;
    var diff = prev.length ? RW.delta(value, prev[0][key]) : null;
    var series = prev.slice(0, 9).reverse().map(function (r) { return r[key]; }).concat([value])
      .filter(function (v) { return v !== null && v !== undefined; });
    var spark = RW.sparkline(series, { etiqueta: 'Evolución de ' + label + ' en los últimos ' + series.length + ' análisis: '
      + series.map(function (v) { return fmtNumber(v, 1); }).join(', ') });
    // El subtítulo de IA va debajo de la cifra (DESIGN.md §4.2) para que las tres cifras queden alineadas
    return el('li', { class: 'rw-card rw-score' }, [
      el('p', { class: 'rw-score__label', text: label }),
      el('p', { class: 'rw-score__value' }, [fmtNumber(value, 1), el('span', { class: 'rw-score__max', text: ' / 100' })]),
      RW.meter(value),
      coverage ? el('p', { class: 'rw-score__coverage', text: coverage }) : null,
      sub ? el('p', { class: 'rw-score__sub', text: sub }) : null,
      diff || spark ? el('div', { class: 'rw-score__extra' }, [diff, spark]) : null,
      score.incompleta ? el('p', {}, [el('span', { class: 'rw-tag', text: 'Nota incompleta', title: i18n.nota_incompleta })]) : null
    ]);
  }

  /** Nota global (CLAUDE.md): una línea de texto, no una cuarta tarjeta. */
  function globalLine(global, blocked, coverage) {
    var w = i18n.pesos_globales || {};
    var pct = function (a) { return Math.round((w[a] || 0) * 100) + ' % ' + i18n.areas[a]; };
    return el('p', { class: 'rw-global' }, [
      'Nota global: ',
      el('strong', { text: blocked ? 'no calculable (acceso bloqueado)' : fmtNumber(global.redondeada, 1) + ' / 100' }),
      ' · media ponderada de las tres notas (' + AREAS.map(pct).join(', ') + '). ' + coverage + '.'
    ]);
  }

  // Resumen ejecutivo breve (reglas en Report::brief(); 8 líneas como máximo)
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
    var note = psiScoreNote(run);
    var brief = el('ul', { class: 'brief' }, (run.data.informe.resumen_breve || []).map(function (item) {
      // Mientras falta PageSpeed, la línea de cobertura se sustituye por la nota de puntuación provisional
      if (item.tipo === 'cobertura' && note) return el('li', { text: note });
      var c = item.id ? checkById(run, item.id) : null;
      return el('li', {}, [item.texto, c ? ' ' : null, c ? findingLink(item.id, 'Ver «' + (c.nombre || c.id) + '»') : null]);
    }));
    var fixFirst = fixFirstFigure(run);
    host.appendChild(fixFirst ? el('div', { class: 'rw-summary' }, [el('div', {}, [brief]), el('div', { class: 'rw-card' }, [fixFirst])]) : brief);
  }

  /**
   * «Qué arreglar primero» (DESIGN.md §5): los fallos que más restan, con los puntos que calcula el servidor
   * (Report::priorities, con las constantes del Scorer). No se recalcula nada aquí. Sin fallos, no hay gráfico.
   */
  function fixFirstFigure(run) {
    if (run.data.blocked) return null;
    var fails = (run.data.informe.prioridades || []).filter(function (r) { return r.estado === 'NO_CUMPLE' && r.penalizacion > 0; });
    if (!fails.length) return null;
    var top = fails.slice().sort(function (a, b) { return b.penalizacion - a.penalizacion || String(a.id).localeCompare(String(b.id)); }).slice(0, 5);
    var zero = AREAS.some(function (a) { return run.data.puntuacion.areas[a].puntuacion <= 0; });
    var pts = function (v) { return fmtNumber(v, 1) + ' pts'; };
    return RW.figure({
      clave: 'arreglar',
      titulo: 'Qué arreglar primero: «' + top[0].nombre + '» es el fallo que más resta (' + fmtNumber(top[0].penalizacion, 1) + ' puntos)',
      resumen: 'Puntos que resta cada fallo, sumados en las tres notas según la fórmula de puntuación (gravedad y repeticiones). '
        + 'Corregirlo los recupera' + (zero ? ', salvo en una nota que ya esté en 0, que sube menos.' : '.')
        + (fails.length > top.length ? ' Se muestran los ' + top.length + ' que más restan de ' + fails.length + ' fallos.' : ''),
      visual: RW.rankedBars(top.map(function (r) { return { texto: r.nombre + ' (' + r.id + ')', valor: r.penalizacion, valorTexto: pts(r.penalizacion) }; })),
      tabla: {
        columnas: [{ texto: 'Fallo' }, { texto: 'ID' }, { texto: 'Gravedad' }, { texto: 'Puntos que resta', num: true }],
        filas: top.map(function (r) { return [r.nombre, r.id, (i18n.severidades || {})[r.severidad] || r.severidad, fmtNumber(r.penalizacion, 1)]; })
      }
    });
  }

  // Bloques por área
  /** Desplegables abiertos y foco dentro de host, para conservarlos al repintar (llegada de PageSpeed). */
  function saveState(host) {
    var open = {};
    Array.prototype.forEach.call(host.querySelectorAll('details[open]'), function (d) { open[d.getAttribute('data-key')] = true; });
    var active = document.activeElement;
    var focus = null;
    if (active && host.contains(active)) {
      var keyed = active.closest('[data-key]');
      if (active.tagName === 'SUMMARY' && keyed) focus = { key: keyed.getAttribute('data-key') };
      else if (active.id) focus = { id: active.id };
    }
    return { open: open, focus: focus };
  }

  function restoreState(host, state) {
    Array.prototype.forEach.call(host.querySelectorAll('details[data-key]'), function (d) {
      if (state.open[d.getAttribute('data-key')]) d.open = true;
    });
    var f = state.focus;
    var target = f && f.key ? host.querySelector('details[data-key="' + f.key + '"] > summary') : f && f.id ? document.getElementById(f.id) : null;
    if (target && host.contains(target)) target.focus();
  }

  function bySeverity(a, b) {
    return (SEVERITY_ORDER[a.severidad] - SEVERITY_ORDER[b.severidad])
      || (LEVEL_ORDER[a.impacto] - LEVEL_ORDER[b.impacto])
      || String(a.id).localeCompare(String(b.id));
  }

  /** Área con la nota más baja (empates: orden de AREAS): su bloque es el único que se abre al principio. */
  function lowestArea(run) {
    var areas = run.data.puntuacion.areas;
    return AREAS.reduce(function (best, a) { return areas[a].redondeada < areas[best].redondeada ? a : best; }, AREAS[0]);
  }

  function updateBlocks(run) {
    var host = run.view.blocks;
    var state = saveState(host);
    var blockOf = run.data.informe.bloque || {};
    var groups = {};
    AREAS.forEach(function (a) { groups[a] = { fail: [], warn: [], nodata: [], ok: [], pending: [] }; });
    run.data.checks.forEach(function (c) {
      var g = groups[blockOf[c.id] || primaryArea(c.pilares)];
      if (c.aplica === false) g.ok.push(c);
      else if (c.estado === 'NO_CUMPLE') g.fail.push(c);
      else if (c.estado === 'AVISO') g.warn.push(c);
      else if (c.estado === 'SIN_DATOS') g.nodata.push(c);
      else g.ok.push(c);
    });
    pendingIds(run).forEach(function (id) { groups[(i18n.psi_bloque || {})[id] || AREAS[2]].pending.push(id); });

    host.textContent = '';
    var open = run.blocksRendered ? null : lowestArea(run);
    AREAS.forEach(function (a) {
      groups[a].fail.sort(bySeverity);
      groups[a].warn.sort(bySeverity);
      host.appendChild(renderAreaBlock(a, groups[a], open === a, run));
    });
    restoreState(host, state);
    run.blocksRendered = true;
  }

  function renderAreaBlock(area, g, isOpen, run) {
    var counts = [];
    if (g.fail.length) counts.push(plural(g.fail.length, 'fallo', 'fallos'));
    if (g.warn.length) counts.push(plural(g.warn.length, 'aviso', 'avisos'));
    if (g.nodata.length) counts.push(g.nodata.length + ' sin datos');
    if (g.pending.length) counts.push(plural(g.pending.length, 'pendiente', 'pendientes'));
    var blocked = !!run.data.blocked;
    var score = run.data.puntuacion.areas[area];
    var body = el('div', { class: 'fold__body' });
    var block = el('details', { class: 'fold area-block', id: 'bloque-' + area, 'data-key': 'area-' + area }, [
      el('summary', {}, [el('span', { class: 'fold__title' }, [
        i18n.areas[area],
        el('span', { class: 'fold__count', text: ' · ' + (blocked ? 'sin nota' : fmtNumber(score.redondeada, 1) + ' / 100') + ' · '
          + (counts.length ? counts.join(', ') : 'sin errores detectables automáticamente') })
      ])]),
      body
    ]);
    if (isOpen) block.open = true;

    // Cabecera del bloque: nota y cobertura
    body.appendChild(el('div', { class: 'rw-block-head' }, [
      el('p', { class: 'rw-block-head__score' }, ['Nota: ', el('strong', { text: blocked ? 'no calculable' : fmtNumber(score.redondeada, 1) + ' / 100' })]),
      blocked ? null : RW.meter(score.redondeada),
      el('p', { class: 'rw-score__coverage', text: areaCoverage(run, area, g.pending.length) + ' que cuentan en esta nota (algunos se muestran en otros bloques).' }),
      area === 'ia' ? el('p', { class: 'rw-help', text: IA_NOTICE }) : null
    ]));
    // Barra de estados y visuales propios del bloque (máximo tres gráficos en total)
    var visuals = [statusFigure(area, g)].concat(blockVisuals(area, g, run));
    body.appendChild(el('div', { class: 'rw-block-visuals' }, visuals.map(function (v) { return el('div', { class: 'rw-card' }, [v]); })));

    if (!g.fail.length && !g.warn.length && !g.nodata.length && !g.pending.length) {
      body.appendChild(el('p', { text: 'No hay fallos, avisos ni comprobaciones sin datos en esta área.' }));
    }
    [['fail', 'Fallos'], ['warn', 'Avisos'], ['nodata', 'Sin datos']].forEach(function (k) {
      var items = g[k[0]];
      if (!items.length) return;
      var hid = 'g-' + k[0] + '-' + area;
      body.appendChild(el('h4', { id: hid }, [k[1] + ' ', el('span', { class: 'group-count', text: '(' + items.length + ')' })]));
      body.appendChild(el('ul', { class: 'points', 'aria-labelledby': hid }, items.map(function (c) { return renderPoint(c, area); })));
    });
    if (g.pending.length) {
      var pid = 'g-pending-' + area;
      body.appendChild(el('h4', { id: pid }, ['Pendientes de PageSpeed Insights ', el('span', { class: 'group-count', text: '(' + g.pending.length + ')' })]));
      body.appendChild(el('ul', { class: 'pending-list', 'aria-labelledby': pid }, g.pending.map(function (id) {
        return el('li', { text: i18n.psi_checks[id] + ' (' + id + ')' });
      })));
    }
    if (g.ok.length) {
      body.appendChild(el('details', { class: 'fold fold--inner', 'data-key': 'ok-' + area }, [
        el('summary', { text: 'Sin errores detectables automáticamente o no aplica (' + g.ok.length + ')' }),
        el('div', { class: 'fold__body' }, [el('ul', { class: 'points' }, g.ok.map(function (c) { return renderPoint(c, area); }))])
      ]));
    }
    return el('div', { class: 'fold-wrap' }, [foldHeading('h3', i18n.areas[area]), block]);
  }

  /**
   * Barra de estados del bloque (DESIGN.md §5): cuántos puntos fallan, avisan, no tienen errores detectables,
   * no tienen datos (o esperan a PageSpeed) o no aplican. Solo cuenta lo que ya hay en los grupos.
   */
  function statusFigure(area, g) {
    var na = g.ok.filter(function (c) { return c.aplica === false; }).length;
    var ok = g.ok.length - na;
    var nodata = g.nodata.length + g.pending.length;
    var total = g.fail.length + g.warn.length + ok + nodata + na;
    var segs = [
      { clave: 'fail', texto: i18n.estados.NO_CUMPLE, valor: g.fail.length },
      { clave: 'warn', texto: i18n.estados.AVISO, valor: g.warn.length },
      { clave: 'ok', texto: i18n.estados.CUMPLE, valor: ok },
      { clave: 'nodata', texto: g.pending.length ? i18n.estados.SIN_DATOS + ' o pendientes' : i18n.estados.SIN_DATOS, valor: nodata },
      { clave: 'na', texto: 'No aplica', valor: na }
    ];
    var shown = segs.filter(function (s) { return s.valor > 0; });
    var title = g.fail.length
      ? g.fail.length + ' de los ' + total + ' puntos de este bloque ' + (g.fail.length === 1 ? 'tiene fallo' : 'tienen fallo')
      : g.warn.length
        ? 'Ningún fallo en los ' + total + ' puntos de este bloque; ' + plural(g.warn.length, 'aviso', 'avisos')
        : nodata === total ? 'Ningún punto de este bloque evaluado: ' + total + ' sin datos'
          : 'Sin errores detectables automáticamente en ' + (total - nodata - na) + ' de los ' + total + ' puntos de este bloque';
    return RW.figure({
      clave: 'estados-' + area,
      titulo: title,
      resumen: 'Estado de los ' + total + ' puntos de ' + i18n.areas[area] + ': '
        + shown.map(function (s) { return s.texto.toLowerCase() + ' ' + s.valor; }).join('; ') + '. «Sin datos» y «No aplica» no restan.',
      resumenOculto: true,
      visual: RW.segmentedBar(segs).node,
      tabla: { columnas: [{ texto: 'Estado' }, { texto: 'Puntos', num: true }], filas: shown.map(function (s) { return [s.texto, s.valor]; }) }
    });
  }

  /**
   * Visuales propios de cada bloque, además de la barra de estados (máximo tres gráficos por bloque).
   * SEO: Core Web Vitals y fallos por gravedad. La matriz páginas × puntos va en el informe de sitio
   * (site.js), porque necesita 5 páginas o más y aquí se analiza una sola URL.
   */
  function blockVisuals(area, g, run) {
    if (area !== 'seo') return [];
    var out = [cwvFigure(run)];
    var sev = severityFigure(area, g);
    if (sev) out.push(sev);
    return out;
  }

  // Core Web Vitals: umbrales publicados por Google (los mismos que C14CoreWebVitals::METRICS)
  var CWV = [
    { nombre: 'LCP', clave: 'LCP (p75)', texto: 'carga del contenido principal', bueno: 2500, deficiente: 4000, maximo: 6000, buenoTexto: '2,5 s', deficienteTexto: '4 s' },
    { nombre: 'INP', clave: 'INP (p75)', texto: 'respuesta a la interacción', bueno: 200, deficiente: 500, maximo: 800, buenoTexto: '200 ms', deficienteTexto: '500 ms' },
    { nombre: 'CLS', clave: 'CLS (p75)', texto: 'estabilidad visual', bueno: 0.1, deficiente: 0.25, maximo: 0.4, buenoTexto: '0,1', deficienteTexto: '0,25' }
  ];

  /**
   * Lee una métrica del valor medido de C14, que la escribe como «2,1 s (bueno)», «180 ms (bueno)» o
   * «0,05 (bueno)» (C14CoreWebVitals::format). Si el formato no es el esperado, null: no se inventa el dato.
   */
  function parseCwv(name, text) {
    var m = /^(\d{1,3}(?:\.\d{3})*(?:,\d+)?)\s*(s|ms)?\s*\((bueno|mejorable|deficiente)\)$/.exec(String(text || '').trim());
    if (!m) return null;
    var n = parseFloat(m[1].replace(/\./g, '').replace(',', '.'));
    if (isNaN(n)) return null;
    if (name === 'LCP') {
      if (m[2] !== 's') return null;
      n = n * 1000;
    } else if (name === 'INP') {
      if (m[2] !== 'ms') return null;
    } else if (m[2]) {
      return null;
    }
    return { valor: n, texto: m[1] + (m[2] ? ' ' + m[2] : ''), franja: m[3] };
  }

  function cwvFigure(run) {
    var title = 'Core Web Vitals (usuarios reales)';
    if (pendingIds(run).indexOf('C14') !== -1) return RW.empty(title, 'pendiente de PageSpeed Insights.');
    var c = checkById(run, 'C14');
    var v = c && c.valor_medido;
    if (!c || c.estado === 'SIN_DATOS' || !v || typeof v !== 'object') {
      var why = String((c && c.detalle) || 'PageSpeed Insights no ha dado datos de esta URL.').replace(/^sin datos(?: de [^:.]+)?[:.]\s*/i, '') || 'PageSpeed Insights no ha dado datos de esta URL.';
      return RW.empty(title, why.charAt(0).toLowerCase() + why.slice(1));
    }
    var rows = [];
    CWV.forEach(function (d) {
      var x = parseCwv(d.nombre, v[d.clave]);
      if (x) rows.push({ d: d, x: x });
    });
    if (!rows.length) return RW.empty(title, 'no se han podido leer las métricas que ha devuelto PageSpeed Insights.');
    var outside = rows.filter(function (r) { return r.x.franja !== 'bueno'; }).map(function (r) { return r.d.nombre; });
    var missing = CWV.filter(function (d) { return !rows.some(function (r) { return r.d === d; }); }).map(function (d) { return d.nombre; });
    var overall = String(v['Evaluación'] || '').toLowerCase();
    return RW.figure({
      clave: 'cwv',
      titulo: 'Core Web Vitals de usuarios reales: ' + (overall || 'sin evaluación') + (outside.length ? ' (' + joinList(outside) + ' fuera de «bueno»)' : ''),
      resumen: 'Percentil 75 de usuarios reales de Chrome en los últimos 28 días, según PageSpeed Insights. Franjas de Google, de claro a oscuro: bueno, mejorable y deficiente; la marca negra es el valor.'
        + (missing.length ? ' Sin datos de ' + joinList(missing) + '.' : ''),
      visual: el('div', { class: 'rw-bullets' }, rows.map(function (r) {
        return RW.bullet({ nombre: r.d.nombre, valor: r.x.valor, valorTexto: r.x.texto, bueno: r.d.bueno, deficiente: r.d.deficiente, maximo: r.d.maximo, franja: r.x.franja });
      })),
      tabla: {
        columnas: [{ texto: 'Métrica' }, { texto: 'Valor (p75)', num: true }, { texto: 'Franja' }, { texto: 'Bueno hasta' }, { texto: 'Deficiente si supera' }],
        filas: rows.map(function (r) { return [r.d.nombre + ' · ' + r.d.texto, r.x.texto, r.x.franja, r.d.buenoTexto, r.d.deficienteTexto]; })
      }
    });
  }

  /** Fallos del bloque por gravedad (rampa del rojo, de crítica a baja). Sin fallos, no hay gráfico. */
  function severityFigure(area, g) {
    if (!g.fail.length) return null;
    var order = [['CRITICO', 'sev-critica'], ['ALTO', 'sev-alta'], ['MEDIO', 'sev-media'], ['BAJO', 'sev-baja']];
    var segs = order.map(function (s) {
      return { clave: s[1], texto: (i18n.severidades || {})[s[0]] || s[0], valor: g.fail.filter(function (c) { return c.severidad === s[0]; }).length };
    });
    var shown = segs.filter(function (s) { return s.valor > 0; });
    return RW.figure({
      clave: 'gravedad-' + area,
      titulo: plural(g.fail.length, 'fallo', 'fallos') + ' de ' + i18n.areas[area] + ': '
        + joinList(shown.map(function (s) { return s.valor + ' de gravedad ' + s.texto.toLowerCase(); })),
      resumen: 'Fallos del bloque según su gravedad, de crítica a baja. Cuanto más grave, más resta de la nota.',
      resumenOculto: true,
      visual: RW.segmentedBar(segs, { baja: true }).node,
      tabla: { columnas: [{ texto: 'Gravedad' }, { texto: 'Fallos', num: true }], filas: shown.map(function (s) { return [s.texto, s.valor]; }) }
    });
  }

  /**
   * Un punto: estado (icono y texto), ID, nombre, gravedad, otras áreas afectadas y qué se ha encontrado; al
   * desplegar, cómo arreglarlo y la evidencia. La revisión manual se indica siempre a la vista.
   */
  function renderPoint(c, area) {
    var na = c.aplica === false;
    var stateKey = na ? 'NA' : c.estado;
    var stateLabel = na ? 'No aplica' : (i18n.estados[c.estado] || c.estado);
    var others = AREAS.filter(function (a) { return a !== area && c.pilares && c.pilares[a] > 0; });
    var tags = [];
    others.forEach(function (a, i) {
      if (i) tags.push(' ');
      tags.push(el('span', { class: 'rw-tag', text: i18n.areas[a] }));
    });
    var actionable = !na && (c.estado === 'NO_CUMPLE' || c.estado === 'AVISO');
    return el('li', { class: 'point rw-point', id: 'hallazgo-' + c.id, tabindex: '-1' }, [
      el('p', { class: 'point__head rw-point__head' }, [
        RW.status(stateKey, { texto: stateLabel }), ' ',
        el('span', { class: 'rw-point__id', text: c.id }), ' ',
        el('span', { class: 'point__name', text: c.nombre || c.id }), ' ',
        na ? null : RW.severityChip(c.severidad, lower(i18n.severidades, c.severidad))
      ]),
      others.length ? el('p', { class: 'point__tags' }, ['También afecta a: '].concat(tags)) : null,
      c.detalle ? el('p', { class: 'rw-point__finding' }, [el('span', { class: 'visually-hidden', text: 'Qué se ha encontrado: ' }), c.detalle]) : null,
      c.requiere_revision_manual ? el('p', { class: 'manual', text: 'Esta comprobación requiere además revisión manual.' }) : null,
      RESPALDO[c.respaldo] ? el('p', { class: 'rw-help rw-point__respaldo' }, ['Respaldo del criterio: ', el('strong', { text: RESPALDO[c.respaldo][0] }), ' (' + RESPALDO[c.respaldo][1] + ').']) : null,
      evidenceFold(c, actionable)
    ]);
  }

  function evidenceFold(c, actionable) {
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
    var fix = c.correccion ? renderFix(c.id, c.correccion, false) : null;
    if (fix) fix.setAttribute('data-key', 'fix-' + c.id);
    var howTo = actionable && c.recomendacion ? el('p', {}, [el('strong', { text: 'Cómo arreglarlo: ' }), c.recomendacion]) : null;
    return el('details', { class: 'fold fold--inner', 'data-key': 'ev-' + c.id }, [
      el('summary', { text: howTo ? 'Cómo arreglarlo y evidencia' : 'Evidencia y datos medidos' }),
      el('div', { class: 'fold__body' }, [howTo, defList('', rows), fix])
    ]);
  }

  // Revisiones manuales RM-01…RM-10 (lista unificada): no se miden ni puntúan
  function renderManualReviews(host, comp) {
    var rm = i18n.revisiones_manuales || {};
    var hints = manualHintLines((comp && comp.pistas_manuales) || null);
    host.appendChild(el('p', { class: 'help', text: 'La herramienta no puede valorar estos aspectos de forma automática. Conviene que los compruebe una persona; no afectan a la nota. Donde pone «Pista automática», la herramienta ya ha señalado por dónde empezar.' }));
    host.appendChild(el('ul', { class: 'manual-list' }, Object.keys(rm).map(function (id) {
      return el('li', {}, [
        el('strong', { text: id + ' · ' + rm[id][0] + '. ' }), rm[id][1],
        hints[id] ? el('p', { class: 'manual-hint' }, [el('strong', { text: 'Pista automática: ' }), hints[id] + ' ', el('a', { href: '#pistas-title', text: 'Ver el detalle' })]) : null
      ]);
    })));
  }

  /** v4 · Una línea de pista para RM-01 (alt) y RM-09 (legibilidad), si hay datos. */
  function manualHintLines(p) {
    var out = {};
    if (!p) return out;
    if (p.alt && p.alt.imagenes_con_alt) {
      out['RM-01'] = p.alt.sospechosos
        ? plural(p.alt.sospechosos, 'imagen tiene', 'imágenes tienen') + ' un texto alternativo sospechoso (de ' + p.alt.imagenes_con_alt + ' con alt).'
        : 'Ninguno de los ' + p.alt.imagenes_con_alt + ' textos alternativos parece un nombre de archivo, una palabra genérica ni se repite. Falta ver si describen bien cada imagen.';
    }
    var l = p.legibilidad;
    if (l && l.disponible && l.indice !== null) {
      out['RM-09'] = 'Legibilidad ' + l.nivel + ' (índice ' + fmtNumber(l.indice, 1) + ' de 100)'
        + (l.frases_largas ? '; ' + plural(l.frases_largas, 'frase de', 'frases de') + ' más de 30 palabras' : '') + '.'
        + (l.concluyente ? '' : ' Poco texto: orientativo.');
    }
    return out;
  }

  // v4 · Pistas para la revisión manual: textos alternativos sospechosos y legibilidad (sin IA, no puntúan)
  function renderManualHints(p) {
    var sec = el('section', { class: 'subsection', 'aria-labelledby': 'pistas-title' }, [
      el('h4', { id: 'pistas-title', tabindex: '-1', text: 'Pistas para la revisión manual' }),
      el('p', { class: 'help', text: 'Reglas automáticas, sin IA: señalan dónde mirar, pero no saben si un texto alternativo describe bien la imagen ni si el contenido responde a lo que busca la gente. No puntúan.' })
    ]);
    var a = p.alt;
    if (a) {
      sec.appendChild(el('h5', { text: 'Textos alternativos sospechosos (RM-01)' }));
      if (!a.imagenes_con_alt) {
        sec.appendChild(el('p', { text: 'No hay imágenes visibles con texto alternativo que revisar.' }));
      } else if (!a.sospechosos) {
        sec.appendChild(el('p', { text: 'Ninguno de los ' + a.imagenes_con_alt + ' textos alternativos parece un nombre de archivo, una palabra genérica, demasiado corto o largo, ni se repite en varias imágenes.' }));
      } else {
        var motivos = i18n.motivos_alt || {};
        sec.appendChild(el('p', { text: plural(a.sospechosos, 'imagen', 'imágenes') + ' de ' + a.imagenes_con_alt + ' con texto alternativo: '
          + Object.keys(a.por_motivo).map(function (k) { return lower(motivos, k) + ' (' + a.por_motivo[k] + ')'; }).join('; ') + '.' }));
        sec.appendChild(el('div', { class: 'table-wrap' }, [el('table', { class: 'data-table' }, [
          el('caption', { class: 'visually-hidden', text: 'Imágenes con texto alternativo sospechoso' }),
          el('thead', {}, [el('tr', {}, ['Texto alternativo', 'Motivo', 'Imagen'].map(function (h) { return el('th', { scope: 'col', text: h }); }))]),
          el('tbody', {}, a.ejemplos.map(function (x) {
            return el('tr', {}, [
              el('th', { scope: 'row', text: '«' + x.alt + '»' }),
              el('td', { text: motivos[x.motivo] || x.motivo }),
              el('td', {}, [el('code', { text: x.src || x.selector })])
            ]);
          }))
        ])]));
        if (a.sospechosos > a.ejemplos.length) sec.appendChild(el('p', { class: 'help', text: 'Se muestran ' + a.ejemplos.length + ' de ' + a.sospechosos + '.' }));
      }
    }
    var l = p.legibilidad;
    if (l) {
      sec.appendChild(el('h5', { text: 'Legibilidad del contenido (RM-09)' }));
      if (!l.disponible) {
        sec.appendChild(el('p', { text: 'No disponible. ' + (l.motivo || '') }));
      } else if (l.indice === null) {
        sec.appendChild(el('p', { text: 'No hay texto suficiente en el contenido principal para medirla.' }));
      } else {
        sec.appendChild(el('ul', { class: 'inline-stats' }, [
          el('li', {}, ['Índice de Fernández-Huerta: ', el('strong', { text: fmtNumber(l.indice, 1) + ' de 100' }), ' (' + l.nivel + ')']),
          el('li', {}, ['Palabras por frase: ', el('strong', { text: fmtNumber(l.palabras_por_frase, 1) })]),
          el('li', {}, ['Frases de más de 30 palabras: ', el('strong', { text: String(l.frases_largas) })]),
          el('li', {}, ['Párrafos de más de 120 palabras: ', el('strong', { text: l.parrafos_largos + ' de ' + l.parrafos })]),
          el('li', {}, ['Palabras medidas: ', el('strong', { text: fmtNumber(l.palabras, 0) })])
        ]));
        if (!l.concluyente) sec.appendChild(el('div', { class: 'notice notice--warn' }, [el('p', { text: 'Poco texto (menos de 100 palabras): el índice es solo orientativo.' })]));
        if (l.ejemplos_frases_largas.length) {
          sec.appendChild(el('p', { text: 'Frases largas para empezar:' }));
          sec.appendChild(el('ul', {}, l.ejemplos_frases_largas.map(function (f) { return el('li', { text: '«' + f.texto + '» (' + f.palabras + ' palabras)' }); })));
        }
        sec.appendChild(el('p', { class: 'help', text: 'Escala: 90–100 muy fácil, 80 fácil, 70 bastante fácil, 60 normal (lo recomendable para una web general), 50 bastante difícil, 30 difícil, menos de 30 muy difícil. Fórmula adaptada al español; mide frases y palabras largas, no la calidad del contenido.'
          + (l.idioma_supuesto ? ' La página no declara idioma; se ha supuesto español.' : '') }));
      }
    }
    return sec;
  }

  // Recomendaciones priorizadas (lo recomendado: no penaliza)
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

  // Plan de acción: matriz impacto × esfuerzo
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

  // Simulador de mejora: estimación «antes / después» si se corrigen los N fallos que más restan
  function updateSimulation(run) {
    var host = run.view.sim;
    var s = run.data.informe.simulacion;
    host.textContent = '';
    if (!s) {
      host.appendChild(el('p', { text: 'No disponible: sin notas no hay nada que simular.' }));
      return;
    }
    if (!s.corregidos.length) {
      host.appendChild(el('p', { text: 'No hay fallos que simular: ninguna comprobación resta puntos.' }));
      return;
    }
    var names = s.corregidos.map(function (c) { return c.nombre; });
    var lead = s.corregidos.length === 1
      ? 'Si se corrigiera el fallo que más puntos resta (' + names[0] + ')'
      : 'Si se corrigieran los ' + s.corregidos.length + ' fallos que más puntos restan (' + joinList(names) + ')';
    if (s.fallos_totales > s.corregidos.length) lead += ', de ' + s.fallos_totales + ' fallos detectados';
    host.appendChild(el('p', { text: lead + ', las notas quedarían así:' }));

    var rows = [['seo', i18n.areas.seo], ['ia', i18n.areas.ia], ['accesibilidad', i18n.areas.accesibilidad]];
    host.appendChild(el('div', { class: 'table-wrap' }, [el('table', { class: 'data-table' }, [
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
      host.appendChild(el('p', { text: 'Con esas correcciones desaparecería el aviso de riesgo alto.' }));
    }
    host.appendChild(el('p', { class: 'help', text: 'Es una estimación, no una medición: supone que cada fallo se corrige por completo y que no aparecen otros. Los avisos y las comprobaciones sin datos no cambian.' }));
  }

  // Historial (Fase 4): notas de análisis anteriores de la misma URL, del más reciente al más antiguo
  function renderHistory(host, h) {
    if (!h.activo) {
      host.appendChild(el('p', { text: h.error || 'El historial no está disponible.' }));
      return;
    }
    var prev = h.anteriores || [];
    host.appendChild(el('p', { class: 'help', text: 'Cada análisis se guarda con su informe completo durante ' + plural(h.meses, 'mes', 'meses') + '. Este ya se ha guardado. «Comparar» enseña qué ha mejorado y qué ha empeorado desde esa auditoría.' }));
    if (!prev.length) {
      host.appendChild(el('p', { text: 'No hay análisis anteriores de esta URL.' }));
      return;
    }
    var note = function (v) { return v === null ? '—' : fmtNumber(v, 1); };
    var shortDate = function (iso) {
      var d = new Date(iso);
      return isNaN(d.getTime()) ? '—' : d.toLocaleString('es-ES', { dateStyle: 'short', timeStyle: 'short' });
    };
    host.appendChild(el('div', { class: 'table-wrap' }, [el('table', { class: 'data-table data-table--history' }, [
      el('caption', { class: 'visually-hidden', text: 'Notas de los análisis anteriores de esta URL' }),
      el('thead', {}, [el('tr', {}, [
        el('th', { scope: 'col', text: 'Fecha' }),
        el('th', { scope: 'col', class: 'num', text: i18n.areas.seo }),
        el('th', { scope: 'col', class: 'num', text: i18n.areas.ia }),
        el('th', { scope: 'col', class: 'num', text: i18n.areas.accesibilidad }),
        el('th', { scope: 'col', class: 'num', text: 'Global' }),
        el('th', { scope: 'col', class: 'num', text: 'Fallos críticos y altos' }),
        el('th', { scope: 'col', text: 'Observaciones' }),
        el('th', { scope: 'col', text: 'Comparar' })
      ])]),
      el('tbody', {}, prev.map(function (r) {
        var obs = [];
        if (r.bloqueado) obs.push('acceso bloqueado');
        else if (!r.pagespeed) obs.push('sin PageSpeed');
        if (r.incompleta) obs.push('nota incompleta');
        if (r.fiabilidad && r.fiabilidad.estado !== 'bloqueado') obs.push('análisis ' + r.fiabilidad.estado + ' (' + r.fiabilidad.evaluados + ' de ' + r.fiabilidad.posibles + ' puntos)');
        return el('tr', {}, [
          el('th', { scope: 'row', text: shortDate(r.fecha) }),
          el('td', { class: 'num', text: note(r.nota_seo) }),
          el('td', { class: 'num', text: note(r.nota_ia) }),
          el('td', { class: 'num', text: note(r.nota_accesibilidad) }),
          el('td', { class: 'num', text: note(r.nota_global) }),
          el('td', { class: 'num', text: r.criticos + ' y ' + r.altos }),
          el('td', { text: obs.join('; ') || '—' }),
          el('td', {}, [r.tiene_informe && h.id
            ? el('a', { href: 'index.php?comparar=' + r.id + ',' + h.id, text: 'Comparar' }, [el('span', { class: 'visually-hidden', text: ' con la auditoría del ' + shortDate(r.fecha) })])
            : el('span', { class: 'compare-table__meta', text: 'Sin informe' })])
        ]);
      }))
    ])]));
    host.appendChild(el('p', { class: 'help', text: 'Las notas «sin PageSpeed» no incluyen contraste ni Core Web Vitals, y las comprobaciones o la página pueden haber cambiado entre un análisis y otro: compáralas con cautela.' }));
  }

  /** «a, b y c» */
  function joinList(items) {
    if (items.length <= 1) return items.join('');
    return items.slice(0, -1).join(', ') + ' y ' + items[items.length - 1];
  }

  // 7. Análisis complementario (no puntúa)
  // Identidad detectada de la web (DESIGN.md §7). Los colores de las muestras son datos reales de la web
  // analizada, no de la interfaz; se aplican por CSSOM (la CSP no admite estilos en línea).
  var CONFIDENCE = { alta: 'Confianza alta', media: 'Confianza media', baja: 'Confianza baja' };

  function identityMeta(origen, confianza) {
    return el('p', { class: 'identity__meta' }, [
      el('span', { text: 'Origen: ' + origen }),
      ' · ',
      el('span', { class: 'identity__conf identity__conf--' + confianza, text: CONFIDENCE[confianza] || confianza })
    ]);
  }

  function identitySwatch(c, label) {
    var chip = el('span', { class: 'identity__chip', 'aria-hidden': 'true' });
    chip.style.backgroundColor = c.hex;
    var ratio = Number(c.contraste_blanco);
    return el('li', { class: 'identity__swatch' }, [
      chip,
      el('div', { class: 'identity__swatch-text' }, [
        el('p', { class: 'identity__hex' }, [label ? label + ' ' : '', el('code', { text: c.hex })]),
        el('p', { class: 'identity__contrast', text: 'Contraste sobre blanco: ' + fmtNumber(ratio, 2) + ':1' +
          (ratio >= 4.5 ? ' (apto para texto normal)' : ratio >= 3 ? ' (solo texto grande o elementos gráficos)' : ' (insuficiente para texto)') }),
        identityMeta(c.origen, c.confianza)
      ])
    ]);
  }

  function identityImage(img, alt) {
    var wrap = el('div', { class: 'identity__image' });
    if (/^https:\/\//i.test(img.url)) {
      wrap.appendChild(el('img', { src: img.url, alt: alt, loading: 'lazy', referrerpolicy: 'no-referrer', decoding: 'async' }));
    }
    return el('div', { class: 'identity__asset' }, [
      wrap,
      el('p', { class: 'identity__url' }, [el('code', { text: img.url })]),
      identityMeta(img.origen, img.confianza)
    ]);
  }

  function renderIdentity(host, d) {
    host.appendChild(el('p', { class: 'help', text: 'Datos extraídos de la página analizada, cada uno con su origen y una confianza. No puntúan. Los colores de las muestras son los de la web analizada.' }));

    var colors = el('section', { class: 'subsection', 'aria-labelledby': 'identity-colors' }, [
      el('h4', { id: 'identity-colors', text: 'Colores principales' })
    ]);
    if (d.colores && d.colores.length) {
      colors.appendChild(el('ul', { class: 'identity__swatches' }, d.colores.map(function (c) { return identitySwatch(c); })));
    } else {
      colors.appendChild(el('p', { class: 'is-missing', text: 'No se han encontrado colores en el CSS analizado.' }));
    }
    host.appendChild(colors);

    var theme = el('section', { class: 'subsection', 'aria-labelledby': 'identity-theme' }, [
      el('h4', { id: 'identity-theme', text: 'theme-color' })
    ]);
    theme.appendChild(d.theme_color
      ? el('ul', { class: 'identity__swatches' }, [identitySwatch(d.theme_color)])
      : el('p', { class: 'is-missing', text: 'La página no declara <meta name="theme-color">.' }));
    host.appendChild(theme);

    var fonts = el('section', { class: 'subsection', 'aria-labelledby': 'identity-fonts' }, [
      el('h4', { id: 'identity-fonts', text: 'Tipografías' })
    ]);
    if (d.tipografias && d.tipografias.length) {
      fonts.appendChild(el('ul', { class: 'identity__fonts' }, d.tipografias.map(function (f) {
        return el('li', {}, [el('p', { class: 'identity__font', text: f.nombre }), identityMeta(f.origen, f.confianza)]);
      })));
      fonts.appendChild(el('p', { class: 'help', text: 'Los nombres se muestran con la tipografía de esta herramienta: no se cargan las fuentes de la web analizada.' }));
    } else {
      fonts.appendChild(el('p', { class: 'is-missing', text: 'No se han encontrado tipografías propias (solo genéricas o del sistema).' }));
    }
    host.appendChild(fonts);

    var assets = el('section', { class: 'subsection', 'aria-labelledby': 'identity-assets' }, [
      el('h4', { id: 'identity-assets', text: 'Logotipo y favicon' })
    ]);
    var grid = el('div', { class: 'identity__assets' });
    grid.appendChild(el('div', {}, [
      el('p', { class: 'identity__label', text: 'Logotipo' }),
      d.logotipo ? identityImage(d.logotipo, 'Logotipo detectado' + (d.logotipo.alt ? ': ' + d.logotipo.alt : ''))
        : el('p', { class: 'is-missing', text: 'No se ha identificado el logotipo en el HTML.' })
    ]));
    grid.appendChild(el('div', {}, [
      el('p', { class: 'identity__label', text: 'Favicon' }),
      d.favicon ? identityImage(d.favicon, 'Favicon detectado') : el('p', { class: 'is-missing', text: 'Sin favicon.' })
    ]));
    assets.appendChild(grid);
    host.appendChild(assets);

    if (d.hojas_analizadas && d.hojas_analizadas.length) {
      var labels = { analizada: 'analizada', no_descargada: 'no descargada', omitida: 'omitida' };
      host.appendChild(el('section', { class: 'subsection', 'aria-labelledby': 'identity-sheets' }, [
        el('h4', { id: 'identity-sheets', text: 'Hojas de estilo enlazadas' }),
        el('ul', { class: 'identity__sheets' }, d.hojas_analizadas.map(function (h) {
          return el('li', {}, [el('code', { text: h.url }), ' — ' + (labels[h.estado] || h.estado) + (h.nota ? ' (' + h.nota + ')' : '')]);
        }))
      ]));
    }
    host.appendChild(el('div', { class: 'notice' }, [el('p', { text: 'Limitación: ' + d.limitacion })]));
  }

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
    if (c.tono) host.appendChild(renderTone(c.tono));
    if (c.pistas_manuales) host.appendChild(renderManualHints(c.pistas_manuales));
    if (c.transferencia) host.appendChild(renderTransfer(c.transferencia));
  }

  // Tono del contenido (análisis de sentimiento por léxico)
  function renderTone(t) {
    var sec = el('section', { class: 'subsection', 'aria-labelledby': 'tone-title' }, [
      el('h4', { id: 'tone-title', text: 'Tono del contenido' })
    ]);
    var help = 'Estimación automática con un léxico en español. La puntuación es el balance entre la carga positiva y la negativa '
      + '(−1: todo negativo; 0: equilibrado o sin carga; +1: todo positivo). Cada palabra con carga suma a su lado, '
      + 'las negaciones («no», «sin»…) invierten el sentido y palabras como «muy» lo intensifican. '
      + 'No entiende ironía, sarcasmo ni contexto: requiere revisión manual. No puntúa.';
    if (!t.disponible) {
      sec.appendChild(el('p', { text: 'No disponible. ' + (t.motivo || '') }));
      sec.appendChild(el('p', { class: 'help', text: help }));
      return sec;
    }
    var labels = { positivo: 'Positivo', negativo: 'Negativo', neutral: 'Neutral' };
    sec.appendChild(el('ul', { class: 'inline-stats' }, [
      el('li', {}, ['Tono: ', el('strong', { text: labels[t.etiqueta] || t.etiqueta })]),
      el('li', {}, ['Puntuación: ', el('strong', { text: fmtNumber(t.puntuacion, 2) }), ' (de −1 a +1)']),
      el('li', {}, ['Palabras con carga: ', el('strong', { text: fmtNumber(t.positivas, 0) + ' positivas / ' + fmtNumber(t.negativas, 0) + ' negativas' })]),
      el('li', {}, ['Palabras analizadas: ', el('strong', { text: fmtNumber(t.palabras_analizadas, 0) }), t.palabras_recortadas ? ' (solo las primeras)' : ''])
    ]));
    if (!t.concluyente) {
      sec.appendChild(el('div', { class: 'notice notice--warn' }, [
        el('p', { text: 'Resultado poco concluyente: el texto tiene muy pocas palabras con carga emocional.' })
      ]));
    }
    if (t.idioma_supuesto) {
      sec.appendChild(el('p', { class: 'help', text: 'La página no declara idioma (atributo lang); se ha supuesto español.' }));
    }
    function terms(title, list) {
      if (!list.length) return null;
      return el('p', {}, [el('strong', { text: title + ': ' }), list.map(function (x) {
        return x.termino + (x.veces > 1 ? ' (' + x.veces + ')' : '');
      }).join(', ')]);
    }
    function sentences(title, list) {
      if (!list.length) return null;
      return el('div', {}, [el('h5', { text: title }), el('ul', {}, list.map(function (x) {
        return el('li', { text: '«' + x.texto + '» (' + fmtNumber(x.valor, 1) + ')' });
      }))]);
    }
    [terms('Términos positivos', t.terminos_positivos), terms('Términos negativos', t.terminos_negativos),
      sentences('Frases más positivas', t.frases_positivas), sentences('Frases más negativas', t.frases_negativas)
    ].forEach(function (n) { if (n) sec.appendChild(n); });
    sec.appendChild(el('p', { class: 'help', text: help }));
    return sec;
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

  // Texto fijo del bloque de Preparación para IA y etiquetas de respaldo de cada criterio
  var IA_NOTICE = 'La nota de Preparación para IA mide preparación técnica. Cumplir los puntos no garantiza que una IA cite la página.';
  var RESPALDO = {
    oficial: ['oficial', 'lo documenta Google o el proveedor del bot'],
    experimento: ['experimento', 'hay un estudio controlado que lo respalda'],
    hipotesis: ['hipótesis', 'práctica habitual sin un estudio abierto que la respalde']
  };

  // 04. Bots de IA y buscadores en robots.txt
  var ACCESS_TEXT = { permitido: 'Permitido', bloqueado: 'Bloqueado', sin_datos: 'Sin datos' };
  var BOT_TYPES = [
    ['busqueda', 'Índice de búsqueda (buscadores y búsqueda con IA)', 'bots-search',
      'Si se bloquea a uno de OpenAI, Anthropic, Perplexity, Google o Bing, la comprobación C02 es un fallo.'],
    ['entrenamiento', 'Entrenamiento de modelos de IA', 'bots-training',
      'Bloquearlos es una decisión de no entrenar y no afecta a la búsqueda (Google-Extended no afecta a la Búsqueda de Google ni a AI Overviews). No resta puntos.'],
    ['usuario', 'A petición del usuario', 'bots-user',
      'Solo informativo, no puntúa: robots.txt puede no aplicarse a estos agentes; solo un bloqueo en cortafuegos o por IP lo garantiza.']
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
      sec.appendChild(el('p', { class: 'help', text: t[3] }));
      sec.appendChild(el('div', { class: 'table-wrap' }, [
        el('table', { class: 'data-table', 'aria-labelledby': t[2] }, [
          el('thead', {}, [el('tr', {}, ['Bot', 'Acceso a esta URL', 'Grupo aplicado', 'Regla'].map(function (h) {
            return el('th', { scope: 'col', text: h });
          }))]),
          el('tbody', {}, rows.map(function (r) {
            var access = ACCESS_TEXT[r.acceso] || r.acceso;
            var group = r.grupo === '*' ? '* (todos los bots)' : (r.grupo || (b.robots_txt === 'presente' ? 'Ninguno' : '—'));
            if (r.heredado_de) group += ' (heredado de ' + r.heredado_de + ')';
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
    sec.appendChild(el('p', { class: 'help', text: 'Los bots de entrenamiento recopilan contenido para entrenar modelos de IA; los de búsqueda, para mostrar la página en resultados y respuestas. Applebot sin reglas propias sigue las de Googlebot.' }));
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

  /** Glosario: no se muestra en el informe actual; se conserva para recuperarlo si se decide. */
  function renderGlossary() {
    var terms = i18n.glosario || {};
    return defList('glossary', Object.keys(terms).map(function (t) { return [t, terms[t]]; }));
  }

  // Evidencias del análisis (fuera de #results, después de las limitaciones; plegado)
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

    var prev = evidenceEl.querySelector('details');
    var wasOpen = !!(prev && prev.open);
    var hadFocus = !!(prev && prev.contains(document.activeElement));
    var body = el('div', { class: 'fold__body' }, [
      el('p', { class: 'help', text: 'Datos de la descarga y de las consultas en los que se basa el informe. Las evidencias de cada comprobación (selector, extracto y línea) figuran en cada punto de los bloques por área. No se guarda ninguno de estos datos.' })
    ]);
    var fold = el('details', { class: 'fold', 'data-key': 'evidencias' }, [
      el('summary', {}, [el('span', { class: 'fold__title fold__title--h2', text: 'Evidencias del análisis' })]),
      body
    ]);
    if (wasOpen) fold.open = true;
    evidenceEl.textContent = '';
    evidenceEl.appendChild(foldHeading('h2', 'Evidencias del análisis', 'evidence-title'));
    evidenceEl.appendChild(fold);
    body.appendChild(defList('facts', [
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
    if (hadFocus) fold.querySelector('summary').focus();
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

  // ---------- 10. Comparar dos URLs: resultado ----------

  var COUNT_ROWS = [
    ['NO_CUMPLE', 'Fallos'],
    ['AVISO', 'Avisos'],
    ['SIN_DATOS', 'Sin datos'],
    ['NA', 'No aplica'],
    ['CUMPLE', 'Sin errores detectables automáticamente']
  ];

  /**
   * Tabla con cabecera de columnas; rows: filas (la primera celda es la cabecera de fila). Con stack, en
   * pantallas estrechas cada fila se apila (CSS): el nombre arriba y las dos URL debajo, con su etiqueta.
   */
  function compareTable(labelledBy, headers, rows, stack) {
    var table = el('table', { class: 'data-table compare-table' + (stack ? ' compare-table--stack' : ''), 'aria-labelledby': labelledBy }, [
      el('thead', {}, [el('tr', {}, headers.map(function (h, i) {
        return el('th', { scope: 'col', class: i > 0 && h.num ? 'num' : null, text: h.text || h });
      }))]),
      el('tbody', {}, rows)
    ]);
    if (stack) {
      // Al cambiar el display de la tabla, algunos navegadores pierden su semántica: se declara con roles
      var setRole = function (sel, role) {
        Array.prototype.forEach.call(table.querySelectorAll(sel), function (n) {
          n.setAttribute('role', typeof role === 'function' ? role(n) : role);
        });
      };
      table.setAttribute('role', 'table');
      setRole('thead, tbody', 'rowgroup');
      setRole('tr', 'row');
      setRole('th', function (n) { return n.getAttribute('scope') === 'row' ? 'rowheader' : 'columnheader'; });
      setRole('td', 'cell');
    }
    return el('div', { class: 'table-wrap' }, [table]);
  }

  /** PDF de una comparación (dos auditorías o dos URL): notas, recuentos, lo que ha cambiado y el sitio. */
  function pdfComparison(cmp) {
    var X = window.AnalyzerCompare;
    var A = cmp.runs[0].data;
    var B = cmp.runs[1].data;
    var N = function (i) { return cmpName(cmp, i); };
    var n1 = function (v) { return v === null || v === undefined ? '—' : fmtNumber(v, 1); };
    var diff = function (a, b) {
      if (a === null || a === undefined || b === null || b === undefined) return '';
      var d = Math.round((b - a) * 10) / 10;
      return ' (' + (d > 0 ? '+' : '') + fmtNumber(d, 1) + ')';
    };
    var note = function (d, a) { return d.blocked ? null : d.puntuacion.areas[a].redondeada; };
    return window.RadarPdf.create({
      eyebrow: 'Radar web · ' + (cmp.audits ? 'Comparación de auditorías' : 'Comparación de dos URLs'),
      titulo: A.url === B.url ? A.url : A.url + ' frente a ' + B.url,
      subtitulo: [N(0) + ': ' + A.url + '.', N(1) + ': ' + B.url + '.']
    }).then(function (doc) {
      doc.h2('Notas de la página');
      doc.p('Entre paréntesis, la diferencia: ' + (cmp.audits ? 'nota reciente menos nota anterior.' : 'nota de la URL 2 menos nota de la URL 1.'), { size: 8.5, color: window.RadarPdf.colors.gris });
      doc.facts(AREAS.map(function (a) {
        return [i18n.areas[a], n1(note(A, a)) + ' → ' + n1(note(B, a)) + diff(note(A, a), note(B, a))];
      }));
      var ca = X.counts(A);
      var cb = X.counts(B);
      doc.h2('Recuento de comprobaciones');
      doc.facts(COUNT_ROWS.map(function (r) { return [r[1], String(ca[r[0]] || 0) + ' → ' + String(cb[r[0]] || 0)]; }));
      var rows = X.checkRows(A, B).filter(function (r) { return r.distinto; });
      doc.h2('Lo que ha cambiado');
      if (!rows.length) doc.p(cmp.audits ? 'Nada ha cambiado entre las dos auditorías.' : 'Las dos URL tienen el mismo resultado en todas las comprobaciones.');
      rows.forEach(function (r) {
        doc.p(r.id + ' · ' + r.nombre + ': ' + X.stateText(r.a, i18n) + ' → ' + X.stateText(r.b, i18n) + '.', { size: 9.5 });
      });
      var sa = cmp.runs[0].sitio;
      var sb = cmp.runs[1].sitio;
      if (cmp.audits && sa && sb && window.RadarSite) {
        var agg = [sa, sb].map(function (s) {
          return window.RadarSite.aggregate([window.RadarSite.homeAsPage(s.home)].concat(s.pages || []), { names: i18n.nombres_checks || {} });
        });
        doc.h2('El sitio: antes y ahora');
        doc.facts(['seo', 'ia', 'accesibilidad', 'global'].map(function (k) {
          return [k === 'global' ? 'Nota global (media)' : i18n.areas[k] + ' (media)', n1(agg[0].media[k]) + ' → ' + n1(agg[1].media[k]) + diff(agg[0].media[k], agg[1].media[k])];
        }).concat([['Páginas con nota', agg[0].analizadas + ' → ' + agg[1].analizadas]]));
      }
      doc.h2('Limitaciones');
      doc.p('Evaluación automática y parcial: no sustituye una auditoría manual y ningún resultado significa que la web cumpla una norma o la ley.', { size: 8.5 });
      doc.save(window.RadarPdf.fileName('comparacion', B.url));
    });
  }

  function renderComparison(cmp) {
    var A = cmp.runs[0].data;
    var B = cmp.runs[1].data;
    var N = function (i) { return cmpName(cmp, i); };
    var title = el('h2', { id: 'results-title', tabindex: '-1', text: cmp.audits ? 'Comparación de auditorías' : 'Comparación de dos URLs' });
    // PDF de la comparación: se descarga, generado con assets/js/pdf.js como el informe y la hoja de ruta
    var printButton = el('button', { type: 'button', class: 'rw-btn no-print', text: 'PDF · Comparación' });
    printButton.addEventListener('click', function () { pdfButton(printButton, function () { return pdfComparison(cmp); }); });

    results.appendChild(el('div', { class: 'report-head' }, [
      title,
      el('ul', { class: 'meta-list' }, cmp.audits ? [
        el('li', {}, [N(0) + ': ', el('strong', { text: A.url })]),
        el('li', {}, [N(1) + ': ', el('strong', { text: B.url })])
      ] : [
        el('li', {}, ['URL 1: ', el('strong', { text: A.url })]),
        el('li', {}, ['URL 2: ', el('strong', { text: B.url })]),
        el('li', { text: 'Fecha del análisis: ' + fmtDate(A.fecha) })
      ]),
      el('div', { class: 'report-actions no-print' }, [printButton]),
      el('p', { class: 'help', text: cmp.audits
        ? 'Dos auditorías guardadas en el historial, con la misma fórmula de puntuación. Muestra qué ha mejorado y qué ha empeorado entre una y otra; no cambia notas ni estados. Para ver una entera, ábrela desde el historial.'
        : 'Cada URL se ha analizado con su propia petición y con la misma fórmula de puntuación. La comparación solo pone los resultados lado a lado: no cambia notas ni estados.' })
    ]));

    if (A.url === B.url && !cmp.audits) {
      results.appendChild(el('div', { class: 'notice notice--warn' }, [
        el('p', { text: 'Las dos direcciones llevan, tras las redirecciones, a la misma URL final. Las diferencias solo pueden deberse a cambios de la página entre una descarga y otra.' })
      ]));
    }
    cmp.runs.forEach(function (r, i) {
      if (!r.data.blocked) return;
      results.appendChild(el('div', { class: 'notice notice--warn' }, [
        el('p', {}, [el('strong', { text: N(i) + ': acceso bloqueado. ' }), r.data.blocked.message]),
        el('p', { text: 'Sus comprobaciones dependientes del contenido figuran como «Sin datos» y no tiene nota.' })
      ]));
    });

    cmp.view = {};
    [['scores', 'cmp-notas', 'Notas por área y global'],
      ['diff', 'cmp-distintas', 'Comprobaciones con resultado distinto'],
      ['same', 'cmp-iguales', 'Comprobaciones con el mismo resultado'],
      ['facts', 'cmp-datos', 'Datos de las descargas']].forEach(function (s) {
      var sec = reportSection(s[1], s[2]);
      cmp.view[s[0]] = sec.body;
      results.appendChild(sec.node);
    });
    updateComparison(cmp);

    results.hidden = false;
    results.setAttribute('aria-busy', 'false');
    title.focus();
  }

  /** Repinta las tablas (al llegar PageSpeed cambian notas, C08 y C14). */
  function updateComparison(cmp) {
    var X = window.AnalyzerCompare;
    var N = function (i) { return cmpName(cmp, i); };
    var A = cmp.runs[0].data;
    var B = cmp.runs[1].data;
    var pendingIds = function (run) { return run.settled ? [] : PSI_IDS; };

    // Notas
    var host = cmp.view.scores;
    host.textContent = '';
    var pending = cmp.runs.some(function (r) { return !r.settled && !r.data.blocked; });
    if (pending) {
      host.appendChild(el('p', { class: 'help', text: 'Notas provisionales: falta incorporar contraste (C08) y Core Web Vitals (C14), que se están consultando a PageSpeed Insights.' }));
    }
    cmp.runs.forEach(function (r, i) {
      if (r.settled && !r.psiMerged && !r.data.blocked) {
        host.appendChild(el('p', { class: 'help', text: 'La nota de «' + N(i) + '» no incluye contraste (C08) ni Core Web Vitals (C14) porque PageSpeed Insights no estaba disponible.' }));
      }
    });
    var cell = function (v, incomplete, blocked) {
      if (blocked) return el('td', { class: 'num', text: 'Sin nota' });
      if (v === null) return el('td', { class: 'num', text: '—' });
      return el('td', { class: 'num' }, [fmtNumber(v, 1), incomplete ? el('span', { class: 'compare-table__meta', text: 'incompleta' }) : null]);
    };
    var scoreRows = X.scoreRows(A, B).map(function (r) {
      var label = r.clave === 'global' ? 'Nota global' : i18n.areas[r.clave];
      return el('tr', { class: r.clave === 'global' ? 'is-total' : null }, [
        el('th', { scope: 'row', text: label }),
        cell(r.a, r.incompleta_a, !!A.blocked),
        cell(r.b, r.incompleta_b, !!B.blocked),
        el('td', { class: 'num', text: X.diffText(r.diferencia) })
      ]);
    });
    var risk = function (d) {
      if (d.blocked) return '—';
      return d.puntuacion.riesgo_alto ? 'Sí' : 'No';
    };
    scoreRows.push(el('tr', {}, [
      el('th', { scope: 'row', text: 'Riesgo alto: revisar de inmediato' }),
      el('td', { class: 'num', text: risk(A) }),
      el('td', { class: 'num', text: risk(B) }),
      el('td', { class: 'num', text: '' })
    ]));
    host.appendChild(el('p', { class: 'help', text: cmp.audits
      ? 'Diferencia: nota reciente menos nota anterior (positiva si ha mejorado).'
      : 'Diferencia: nota de la URL 2 menos nota de la URL 1 (positiva si la URL 2 tiene más nota).' }));
    host.appendChild(compareTable('cmp-notas-t', ['Área', { text: N(0), num: true }, { text: N(1), num: true }, { text: 'Diferencia', num: true }], scoreRows));
    var incomplete = [A, B].some(function (d) {
      return !d.blocked && ['seo', 'ia', 'accesibilidad'].some(function (a) { return d.puntuacion.areas[a].incompleta; });
    });
    if (incomplete) host.appendChild(el('p', { class: 'help', text: i18n.nota_incompleta }));

    var ca = X.counts(A);
    var cb = X.counts(B);
    var total = function (c) { return COUNT_ROWS.reduce(function (s, r) { return s + (c[r[0]] || 0); }, 0); };
    host.appendChild(el('h4', { id: 'cmp-recuento', text: 'Recuento de comprobaciones' }));
    host.appendChild(compareTable('cmp-recuento', ['Resultado', { text: N(0), num: true }, { text: N(1), num: true }], COUNT_ROWS.map(function (s) {
      return el('tr', {}, [
        el('th', { scope: 'row', text: s[1] }),
        el('td', { class: 'num', text: String(ca[s[0]]) }),
        el('td', { class: 'num', text: String(cb[s[0]]) })
      ]);
    })));
    if (total(ca) !== total(cb)) {
      host.appendChild(el('p', { class: 'help', text: N(0) + ' tiene ' + total(ca) + ' comprobaciones y ' + N(1) + ', ' + total(cb)
        + ': la herramienta ha ido incorporando comprobaciones nuevas, así que el análisis más antiguo tiene menos.' }));
    }

    // Comprobaciones
    var rows = X.checkRows(A, B);
    var stateCell = function (c, id, run, label) {
      if (pendingIds(run).indexOf(id) !== -1 && !run.data.blocked) return el('td', { 'data-label': label, text: 'Pendiente de PageSpeed Insights' });
      return el('td', { 'data-label': label }, [el('span', { class: 'badge badge--' + c.estado, text: X.stateText(c, i18n) })]);
    };
    var checkRow = function (r) {
      return el('tr', {}, [
        el('th', { scope: 'row' }, [r.id + ' · ' + r.nombre, r.severidad ? el('span', { class: 'compare-table__meta', text: 'Gravedad ' + lower(i18n.severidades, r.severidad) }) : null]),
        stateCell(r.a, r.id, cmp.runs[0], N(0)),
        stateCell(r.b, r.id, cmp.runs[1], N(1))
      ]);
    };
    var headers = ['Comprobación', N(0), N(1)];
    var different = rows.filter(function (r) { return r.distinto; });
    var equal = rows.filter(function (r) { return !r.distinto; });

    host = cmp.view.diff;
    host.textContent = '';
    host.appendChild(el('p', { class: 'help', text: 'Cambia el estado o, si falla en las dos, el número de casos (y por tanto lo que resta). ' + different.length + ' de ' + rows.length + ' comprobaciones.' }));
    if (different.length) host.appendChild(compareTable('cmp-distintas-t', headers, different.map(checkRow), true));
    else host.appendChild(el('p', { text: cmp.audits ? 'Nada ha cambiado entre las dos auditorías.' : 'Las dos URL tienen el mismo resultado en todas las comprobaciones.' }));

    host = cmp.view.same;
    host.textContent = '';
    host.appendChild(el('p', { class: 'help', text: equal.length + ' de ' + rows.length + ' comprobaciones.' }));
    if (equal.length) host.appendChild(compareTable('cmp-iguales-t', headers, equal.map(checkRow), true));
    else host.appendChild(el('p', { text: 'Ninguna comprobación tiene el mismo resultado.' }));

    // Datos de las descargas
    host = cmp.view.facts;
    host.textContent = '';
    var fact = function (label, fn) {
      return el('tr', {}, [el('th', { scope: 'row', text: label })].concat(cmp.runs.map(function (r) {
        return el('td', { text: fn(r) });
      })));
    };
    host.appendChild(compareTable('cmp-datos-t', ['Dato', N(0), N(1)], [
      fact('URL solicitada', function (r) { return (r.data.fetch && r.data.fetch.requested_url) || r.url; }),
      fact('URL final', function (r) { return r.data.url; }),
      fact('Respuesta', function (r) {
        var f = r.data.fetch || {};
        return 'HTTP ' + (f.status || '—') + ' · ' + fmtNumber((f.bytes || 0) / 1024, 1) + ' KB';
      }),
      fact('Redirecciones', function (r) { return String(((r.data.fetch && r.data.fetch.redirects) || []).length); }),
      fact('PageSpeed Insights', function (r) { return psiText(r); })
    ]));
    if (!cmp.audits) host.appendChild(el('p', { class: 'help', text: 'No se guarda ninguno de estos datos.' }));
  }

  /** Nombre de cada lado de una comparación: «URL 1 / URL 2» o, con auditorías, «Anterior (fecha) / Reciente (fecha)». */
  function cmpName(cmp, i) {
    return cmp.names ? cmp.names[i] : 'URL ' + (i + 1);
  }

  /**
   * v4 · Comparar dos auditorías guardadas (index.php?comparar=ID1,ID2): la página buscada con el comparador de
   * siempre y, si las dos tienen análisis del sitio, sus notas medias y los fallos que más han cambiado.
   */
  function compareSaved(ids) {
    setLoading(true);
    setStatus('Cargando las dos auditorías del historial…', true);
    Promise.all(ids.map(function (id) {
      return postJson('api/history_report.php', { id: id }, new AbortController(), REQUEST_TIMEOUT_MS);
    })).then(function (rs) {
      setLoading(false);
      var reps = rs.map(function (r) { return r.data || {}; });
      var bad = reps.filter(function (d) { return !d.ok || !d.informe || !(d.informe.data || d.informe.home); })[0];
      if (bad) {
        setStatus('', false);
        showError((bad.error && bad.error.message) || 'Uno de los dos análisis no tiene un informe guardado.', 'Vuelve al historial y elige otros dos.');
        return;
      }
      reps.sort(function (a, b) { return a.fecha < b.fecha ? -1 : a.fecha > b.fecha ? 1 : 0; });
      var shortDate = fmtDate;
      var runs = reps.map(function (d) {
        var inf = d.informe;
        // Informes de «Analizar sitio» de la v3.0: solo tienen la portada y el sitio
        var data = inf.data || inf.home;
        return {
          url: data.url, data: data, settled: true, psi: inf.psi || null,
          psiMerged: !!inf.psiMerged, psiFailure: inf.psiMerged ? null : (inf.psiFailure || 'PageSpeed Insights no se incorporó a este informe.'),
          sitio: inf.sitio || (inf.home ? inf : null), fecha: d.fecha
        };
      });
      var cmp = { runs: runs, audits: true, view: null, cancel: function () {},
        names: ['Anterior (' + shortDate(runs[0].fecha) + ')', 'Reciente (' + shortDate(runs[1].fecha) + ')'] };
      input.value = runs[1].url;
      renderComparison(cmp);
      renderSiteComparison(runs[0].sitio, runs[1].sitio, cmp);
      // La comparación sale bajo el buscador: se lleva la vista (y el foco) a su título
      var cmpTitle = document.getElementById('results-title');
      if (cmpTitle) { cmpTitle.focus(); cmpTitle.scrollIntoView({ block: 'start' }); }
      setStatus('Comparación de dos auditorías guardadas: ' + fmtDate(runs[0].fecha) + ' y ' + fmtDate(runs[1].fecha) + '.', false);
    }, function () {
      setLoading(false);
      setStatus('', false);
      showError('No se han podido cargar las auditorías guardadas.', 'Comprueba tu conexión e inténtalo de nuevo.');
    });
  }

  /** Notas medias del sitio y fallos por comprobación (en % de páginas) en las dos auditorías. */
  function renderSiteComparison(sa, sb, cmp) {
    if (!window.RadarSite) return;
    var sec = reportSection('cmp-sitio', 'El sitio: antes y ahora');
    results.appendChild(sec.node);
    var host = sec.body;
    if (!sa || !sb) {
      host.appendChild(el('p', { text: 'Solo ' + (sa || sb ? 'una de las dos auditorías' : 'ninguna de las dos') + ' tiene el análisis del sitio terminado: no se puede comparar el sitio.' }));
      return;
    }
    var agg = [sa, sb].map(function (s) {
      return window.RadarSite.aggregate([window.RadarSite.homeAsPage(s.home)].concat(s.pages || []), { names: i18n.nombres_checks || {} });
    });
    var diff = function (a, b) {
      if (a === null || b === null) return '—';
      var d = Math.round((b - a) * 10) / 10;
      return (d > 0 ? '+' : '') + fmtNumber(d, 1);
    };
    var n1 = function (v) { return v === null ? '—' : fmtNumber(v, 1); };
    var rows = ['seo', 'ia', 'accesibilidad', 'global'].map(function (k) {
      return el('tr', { class: k === 'global' ? 'is-total' : null }, [
        el('th', { scope: 'row', text: k === 'global' ? 'Nota global (media)' : i18n.areas[k] + ' (media)' }),
        el('td', { class: 'num', text: n1(agg[0].media[k]) }),
        el('td', { class: 'num', text: n1(agg[1].media[k]) }),
        el('td', { class: 'num', text: diff(agg[0].media[k], agg[1].media[k]) })
      ]);
    });
    rows.push(el('tr', {}, [
      el('th', { scope: 'row', text: 'Páginas analizadas con nota' }),
      el('td', { class: 'num', text: String(agg[0].analizadas) }),
      el('td', { class: 'num', text: String(agg[1].analizadas) }),
      el('td', { class: 'num', text: '' })
    ]));
    host.appendChild(compareTable('cmp-sitio-t', ['Dato', { text: cmpName(cmp, 0), num: true }, { text: cmpName(cmp, 1), num: true }, { text: 'Diferencia', num: true }], rows));
    if (agg[0].analizadas !== agg[1].analizadas) {
      host.appendChild(el('p', { class: 'help', text: 'Las dos auditorías no tienen el mismo número de páginas (por ejemplo, una rápida y otra profunda): compara las medias con cuidado.' }));
    }
    // Fallos: % de páginas que fallan cada comprobación, de más a menos cambio
    var rate = function (a, id) {
      var c = a.checks.filter(function (x) { return x.id === id; })[0];
      return c && c.evaluadas ? c.fallan / c.evaluadas * 100 : (c ? 0 : null);
    };
    var ids = [];
    agg.forEach(function (a) { a.checks.forEach(function (c) { if (ids.indexOf(c.id) < 0 && c.id !== 'C16' && c.id !== 'C18') ids.push(c.id); }); });
    var changes = ids.map(function (id) {
      var a = rate(agg[0], id);
      var b = rate(agg[1], id);
      return { id: id, a: a, b: b, d: a === null || b === null ? 0 : b - a };
    }).filter(function (x) { return Math.abs(x.d) >= 0.5 || (x.a === null) !== (x.b === null); });
    changes.sort(function (x, y) { return x.d - y.d; });
    host.appendChild(el('h4', { text: 'Fallos que han cambiado (en % de páginas que fallan)' }));
    if (!changes.length) {
      host.appendChild(el('p', { text: 'Los fallos del sitio están igual en las dos auditorías.' }));
      return;
    }
    var pct = function (v) { return v === null ? '—' : fmtNumber(v, 0) + ' %'; };
    host.appendChild(compareTable('cmp-sitio-fallos', ['Comprobación', { text: cmpName(cmp, 0), num: true }, { text: cmpName(cmp, 1), num: true }, 'Cambio'], changes.map(function (x) {
      return el('tr', {}, [
        el('th', { scope: 'row', text: x.id + ' · ' + ((i18n.nombres_checks || {})[x.id] || x.id) }),
        el('td', { class: 'num', text: pct(x.a) }),
        el('td', { class: 'num', text: pct(x.b) }),
        el('td', { text: x.d < 0 ? 'Mejora' : x.d > 0 ? 'Empeora' : 'Igual' })
      ]);
    })));
  }


  // ---------- Navegación e impresión ----------

  // Los enlaces internos del informe abren los desplegables que contienen el destino y llevan el foco a él
  results.addEventListener('click', function (ev) {
    var a = ev.target.closest ? ev.target.closest('a[href^="#"]') : null;
    if (!a) return;
    var target = document.getElementById(a.getAttribute('href').slice(1));
    if (!target || !results.contains(target)) return;
    ev.preventDefault();
    for (var n = target; n && n !== results; n = n.parentElement) {
      if (n.tagName === 'DETAILS') n.open = true;
    }
    var focusable = target.tagName === 'DETAILS' ? target.querySelector('summary') : target;
    if (!focusable.hasAttribute('tabindex') && focusable.tagName !== 'SUMMARY') focusable.setAttribute('tabindex', '-1');
    focusable.focus();
  });

  // Al imprimir se abren todos los desplegables (si no, su contenido no sale) y después se restauran
  var openedForPrint = [];
  window.addEventListener('beforeprint', function () {
    openedForPrint = Array.prototype.filter.call(document.querySelectorAll('main details'), function (d) {
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
