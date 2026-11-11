/*
 * Radar web · pantalla de resultados por capas (dashboard). Pinta, a partir del modelo único de
 * assets/js/perfiles.js, lo que falla o falta por bloque (SEO, GEO, UX y toda la web) con el perfil de lectura
 * elegido. Cambiar de perfil vuelve a pintar el MISMO resultado (no repite el análisis ni llama a PageSpeed).
 *
 * Todo se escribe con textContent (RadarComponents.el); nunca se interpreta HTML de la web analizada.
 */
(function (root) {
  'use strict';

  var doc = root.document;
  var RW = root.RadarComponents;
  var RP = root.RadarPerfil;
  var i18n = {};
  var state = { run: null, sitio: null, perfil: 'desarrollo', host: null, body: null, listeners: [] };

  var BLOCK_NAMES = { web: 'Toda la web' };
  var SEV_TEXT = { CRITICO: 'Crítica', ALTO: 'Alta', MEDIO: 'Media', BAJO: 'Baja' };

  function el(tag, attrs, children) { return RW.el(tag, attrs, children); }
  function blockName(b) { return BLOCK_NAMES[b] || (i18n.areas && i18n.areas[b]) || b; }
  function levelText(v) { return v ? String((i18n.niveles || {})[v] || v).toLowerCase() : ''; }
  function stateText(st) {
    if (st === 'NA') return 'No aplica';
    return (i18n.estados || {})[st] || RW.STATUS_TEXT[st] || st;
  }

  // ---------------------------------------------------------------- perfil

  function profileFromUrl() {
    var qs = new URLSearchParams(root.location.search);
    return qs.has('perfil') ? RP.normalize(qs.get('perfil'), i18n.perfiles) : null;
  }

  /** Recuerda el perfil en la dirección (?perfil=), sin recargar ni guardar nada en el servidor. */
  function rememberProfile(p) {
    try {
      var url = new URL(root.location.href);
      url.searchParams.set('perfil', p);
      root.history.replaceState(root.history.state, '', url.pathname + url.search + url.hash);
    } catch (e) { /* sin History API: el perfil vale para esta pantalla */ }
  }

  function setProfile(p, opts) {
    var v = RP.normalize(p, i18n.perfiles);
    state.perfil = v;
    Array.prototype.forEach.call(doc.querySelectorAll('input[data-perfil]'), function (r) { r.checked = r.value === v; });
    if (!(opts && opts.silencioso)) rememberProfile(v);
    renderBody();
    state.listeners.forEach(function (fn) { fn(v); });
  }

  /** Selector del perfil: grupo de botones de opción con nombre y una línea descriptiva. */
  function selector(name, idPrefix) {
    var data = i18n.perfiles || {};
    var items = (data.lista || RP.PERFILES).map(function (p) {
      var info = (data.perfiles || {})[p] || { nombre: p, descripcion: '' };
      var id = idPrefix + '-' + p;
      var input = el('input', { type: 'radio', name: name, id: id, value: p, 'data-perfil': '', 'aria-describedby': id + '-d' });
      input.checked = p === state.perfil;
      input.addEventListener('change', function () { if (input.checked) setProfile(p); });
      return el('div', { class: 'rw-profile__option' }, [
        input,
        el('label', { for: id }, [
          el('span', { class: 'rw-profile__name', text: info.nombre }),
          el('span', { class: 'rw-profile__desc', id: id + '-d', text: info.descripcion })
        ])
      ]);
    });
    return el('fieldset', { class: 'rw-profile' }, [
      el('legend', { text: 'Perfil de lectura' }),
      el('div', { class: 'rw-profile__options' }, items),
      el('p', { class: 'rw-help', text: 'Cambia el lenguaje, el orden a igualdad de gravedad y el nivel de detalle. Las notas y los estados son los mismos para los tres perfiles.' })
    ]);
  }

  // ---------------------------------------------------------------- puntos

  function sitioText(pt) {
    var s = pt.sitio;
    if (!s || !s.evaluadas) return '';
    var t = s.fallan > 0
      ? 'En el sitio: no cumple en ' + s.fallan + ' de ' + RP.plural(s.evaluadas, 'página', 'páginas') + (s.avisos ? ' (y ' + s.avisos + ' con aviso)' : '')
      : s.avisos > 0 ? 'En el sitio: aviso en ' + s.avisos + ' de ' + RP.plural(s.evaluadas, 'página', 'páginas')
        : 'En el sitio: sin errores detectables automáticamente en ' + RP.plural(s.evaluadas, 'página', 'páginas');
    var ex = (s.ejemplos || []).slice(0, 3).map(function (u) { try { var x = new URL(u); return x.pathname + x.search; } catch (e) { return u; } });
    return t + '.' + (ex.length && (s.fallan || s.avisos) ? ' Por ejemplo: ' + ex.join(', ') + '.' : '');
  }

  /** Un punto con los textos del perfil. Los datos medidos son los mismos en los tres perfiles. */
  function renderPoint(pt, perfil, lvl) {
    var t = RP.pointText(pt, perfil, i18n);
    var chips = [RW.status(pt.estado, { texto: stateText(pt.estado) })];
    if (pt.severidad) chips.push(RW.severityChip(pt.severidad, SEV_TEXT[pt.severidad]));
    if (pt.pendiente) chips.push(el('span', { class: 'rw-tag', text: 'Pendiente de PageSpeed' }));
    if (t.pendiente) chips.push(el('span', { class: 'rw-tag', text: 'Texto de perfil pendiente' }));
    var body = [
      el('div', { class: 'rw-point__head' }, chips),
      t.significa ? el('p', { class: 'rw-point__text', text: t.significa }) : null,
      t.importa ? el('p', { class: 'rw-point__text' }, [el('strong', { text: 'Por qué importa: ' }), t.importa]) : null
    ];
    if (pt.estado === 'NO_CUMPLE' || pt.estado === 'AVISO') {
      if (t.hacer) body.push(el('p', { class: 'rw-point__text' }, [el('strong', { text: perfil === 'direccion' ? 'Decisión: ' : 'Qué hacer: ' }), t.hacer]));
      if (perfil === 'direccion' && (pt.esfuerzo || pt.impacto)) {
        body.push(el('p', { class: 'rw-point__meta', text: 'Estimación: esfuerzo ' + (levelText(pt.esfuerzo) || '—') + ' · impacto ' + (levelText(pt.impacto) || '—') + '.' }));
      }
    }
    var evidence = [];
    if (pt.detalle) evidence.push(el('p', { class: 'rw-point__text' }, [el('strong', { text: pt.id.indexOf('WEB-') === 0 ? 'Encontrado en el sitio: ' : 'Encontrado en la URL: ' }), pt.detalle]));
    var st = sitioText(pt);
    if (st) evidence.push(el('p', { class: 'rw-point__text', text: st }));
    if (lvl.tecnico) {
      var facts = [];
      if (pt.encontrado) facts.push(['Valor encontrado', pt.encontrado]);
      if (pt.esperado) facts.push(['Valor esperado', pt.esperado]);
      if (t.comprobar) facts.push(['Cómo comprobarlo', t.comprobar]);
      if (facts.length) {
        evidence.push(el('dl', { class: 'rw-point__facts' }, facts.reduce(function (acc, f) {
          return acc.concat([el('dt', { text: f[0] }), el('dd', { text: f[1] })]);
        }, [])));
      }
    }
    if (lvl.plegarTecnico) {
      var tech = [];
      if (t.tecnico && t.tecnico !== t.hacer) tech.push(el('p', { class: 'rw-point__text' }, [el('strong', { text: 'Recomendación técnica: ' }), t.tecnico]));
      tech = tech.concat(evidence);
      if (tech.length) body.push(el('details', { class: 'rw-details rw-point__more' }, [el('summary', { text: 'Detalle para el equipo web' })].concat(tech)));
    } else {
      body = body.concat(evidence);
    }
    if (t.respaldo) body.push(el('p', { class: 'rw-point__meta', text: t.respaldo }));
    return el('li', { class: 'rw-point rw-point--' + RW.statusKey(pt.estado), id: 'punto-' + pt.id, tabindex: '-1' }, [
      el('h5', { class: 'rw-point__title' }, [el('span', { class: 'rw-point__id', text: pt.id.indexOf('WEB-') === 0 ? 'Sitio' : pt.id }), ' ', pt.nombre])
    ].concat(body));
  }

  function pointList(points, perfil, lvl) {
    return el('ul', { class: 'rw-points' }, points.map(function (pt) { return renderPoint(pt, perfil, lvl); }));
  }

  /** Lista de fallos o avisos; en dirección, los de gravedad baja quedan plegados (nunca uno crítico). */
  function failList(points, perfil, lvl) {
    if (!lvl.ocultarBajos) return pointList(points, perfil, lvl);
    var main = points.filter(function (p) { return p.severidad !== 'BAJO'; });
    var low = points.filter(function (p) { return p.severidad === 'BAJO'; });
    return el('div', {}, [
      main.length ? pointList(main, perfil, lvl) : null,
      low.length ? el('details', { class: 'rw-details' }, [
        el('summary', { text: 'Ver ' + RP.plural(low.length, 'punto de gravedad baja', 'puntos de gravedad baja') }),
        pointList(low, perfil, lvl)
      ]) : null
    ]);
  }

  function group(title, points, perfil, lvl, opts) {
    if (!points.length) return null;
    var list = opts && opts.fallos ? failList(points, perfil, lvl) : pointList(points, perfil, lvl);
    if (opts && opts.plegado) {
      return el('details', { class: 'rw-details rw-group' }, [el('summary', { text: title + ' (' + points.length + ')' }), list]);
    }
    return el('div', { class: 'rw-group' }, [el('h4', { text: title + ' (' + points.length + ')' }), list]);
  }

  // ---------------------------------------------------------------- bloques

  function renderBlocks(m, perfil) {
    var lvl = RP.detailLevel(perfil);
    var wrap = el('section', { class: 'rw-dash__section', id: 'detalle', 'aria-labelledby': 'detalle-t' }, [
      el('h3', { id: 'detalle-t', text: 'Detalle por bloque' }),
      el('p', { class: 'rw-help', text: 'Dentro de cada estado, primero lo que más pesa en la nota (gravedad); a igualdad, lo más relevante para el perfil elegido.' })
    ]);
    RP.BLOQUES.forEach(function (b) {
      var g = RP.groupBlock(m, b, perfil, i18n);
      var n = g.fallos.length + g.avisos.length;
      if (b === 'web' && !m.sitioTerminado && !n && !g.cumple.length) {
        wrap.appendChild(el('details', { class: 'rw-details rw-block', id: 'bloque-web' }, [
          el('summary', {}, [el('span', { class: 'rw-block__name', text: blockName(b) }), el('span', { class: 'rw-block__count', text: 'Al terminar el análisis del sitio' })]),
          el('p', { class: 'rw-help', text: 'Enlaces rotos, sitemap, títulos repetidos y página de error: se muestran cuando termina el recorrido del sitio.' })
        ]));
        return;
      }
      var counts = [];
      if (g.fallos.length) counts.push(RP.plural(g.fallos.length, 'fallo', 'fallos'));
      if (g.avisos.length) counts.push(RP.plural(g.avisos.length, 'aviso', 'avisos'));
      if (!counts.length) counts.push('Sin errores detectables automáticamente');
      var details = el('details', { class: 'rw-details rw-block', id: 'bloque-' + b, 'data-key': 'bloque-' + b }, [
        el('summary', {}, [el('span', { class: 'rw-block__name', text: blockName(b) }), el('span', { class: 'rw-block__count', text: counts.join(' · ') })]),
        group('No cumple', g.fallos, perfil, lvl, { fallos: true }),
        group('Avisos', g.avisos, perfil, lvl, { fallos: true }),
        group('Sin errores detectables automáticamente', g.cumple, perfil, lvl, { plegado: true })
      ]);
      wrap.appendChild(details);
    });
    return wrap;
  }

  /** Puntos que no se han podido evaluar o no aplican: aparte, sin mezclarlos con los resultados. */
  function renderNotEvaluated(m, perfil) {
    var lvl = RP.detailLevel(perfil);
    var pend = m.puntos.filter(function (p) { return p.pendiente; });
    var nod = RP.sortPoints(m.puntos.filter(function (p) { return p.estado === 'SIN_DATOS' && !p.pendiente && p.conNota; }), perfil, i18n);
    var na = RP.sortPoints(m.puntos.filter(function (p) { return p.estado === 'NA'; }), perfil, i18n);
    var sec = el('section', { class: 'rw-dash__section', id: 'no-evaluados', 'aria-labelledby': 'no-evaluados-t' }, [
      el('h3', { id: 'no-evaluados-t', text: 'Puntos no evaluados' })
    ]);
    if (!pend.length && !nod.length && !na.length) {
      sec.appendChild(el('p', { text: 'Se han podido evaluar todos los puntos que aplican a esta URL.' }));
      return sec;
    }
    sec.appendChild(el('p', { class: 'rw-help', text: 'No restan ni suman: la nota se calcula solo con lo evaluado.' }));
    [
      ['Pendientes de PageSpeed Insights', pend],
      ['Sin datos', nod],
      ['No aplican a esta página', na]
    ].forEach(function (x) {
      if (!x[1].length) return;
      sec.appendChild(el('details', { class: 'rw-details rw-group' }, [el('summary', { text: x[0] + ' (' + x[1].length + ')' }), pointList(x[1], perfil, lvl)]));
    });
    return sec;
  }

  // ---------------------------------------------------------------- pintado

  function currentModel() {
    var run = state.run;
    if (!run || !run.data) return null;
    return RP.model(run.data, {
      i18n: i18n,
      psiPendiente: !run.psiMerged && !run.psiFailure,
      psiFallo: run.psiFailure || null,
      sitio: state.sitio
    });
  }

  function renderBody() {
    if (!state.body) return;
    var m = currentModel();
    state.body.textContent = '';
    if (!m) return;
    var perfil = state.perfil;
    if (m.bloqueado) {
      state.body.appendChild(el('p', { class: 'notice notice--warn', text: 'Acceso bloqueado: las comprobaciones que dependen del contenido de la página aparecen como «Sin datos».' }));
    }
    state.body.appendChild(renderBlocks(m, perfil));
    state.body.appendChild(renderNotEvaluated(m, perfil));
  }

  /**
   * Pinta la pantalla de resultados de un análisis en host (lo crea app.js). Se vuelve a llamar al llegar
   * PageSpeed y al terminar el sitio; el selector de perfil se conserva para no perder el foco.
   */
  function render(run, host) {
    if (state.run !== run) state.sitio = null;
    state.run = run;
    if (host && host !== state.host) {
      state.host = host;
      host.textContent = '';
      host.appendChild(selector('perfil-informe', 'perfil-informe'));
      state.body = el('div', { class: 'rw-dash__body' });
      host.appendChild(state.body);
    }
    renderBody();
  }

  function setSite(sitio) {
    state.sitio = sitio || null;
    renderBody();
  }

  function init(data) {
    i18n = data || {};
    var fromUrl = profileFromUrl();
    state.perfil = RP.normalize(fromUrl || i18n.perfil_inicial, i18n.perfiles);
    // Selector de la pantalla de búsqueda (index.php): mismo perfil que el del informe
    Array.prototype.forEach.call(doc.querySelectorAll('input[name="perfil"][data-perfil]'), function (r) {
      r.checked = r.value === state.perfil;
      r.addEventListener('change', function () { if (r.checked) setProfile(r.value); });
    });
  }

  root.RadarDashboard = {
    init: init,
    render: render,
    setSite: setSite,
    profile: function () { return state.perfil; },
    model: currentModel,
    onProfile: function (fn) { state.listeners.push(fn); }
  };
})(window);
