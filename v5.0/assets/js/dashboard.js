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
  var state = { run: null, sitio: null, perfil: 'desarrollo', host: null, body: null, listeners: [],
    // Filtro de gravedad del detalle por bloque (vacío = todas). Solo oculta en pantalla; no cambia nada más.
    sev: { CRITICO: true, ALTO: true, MEDIO: true, BAJO: true, NINGUNA: true } };
  var SVGNS = 'http://www.w3.org/2000/svg';

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
    return el('li', { class: 'rw-point rw-point--' + RW.statusKey(pt.estado), id: 'punto-' + pt.id, tabindex: '-1', 'data-sev': pt.severidad || 'NINGUNA' }, [
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
      el('p', { class: 'rw-help', text: 'Dentro de cada estado, primero lo que más pesa en la nota (gravedad); a igualdad, lo más relevante para el perfil elegido.' }),
      severityFilter()
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

  // ---------------------------------------------------------------- resumen ejecutivo

  /** Abre los desplegables que contienen un punto y lleva el foco a él (enlaces de las prioridades). */
  function goToPoint(id, ev) {
    var target = doc.getElementById('punto-' + id);
    if (!target) return;
    if (ev) ev.preventDefault();
    // Si el filtro de gravedad lo ocultaba, se vuelve a mostrar esa gravedad
    if (target.hidden) {
      state.sev[target.getAttribute('data-sev')] = true;
      var box = doc.getElementById('filtro-sev-' + target.getAttribute('data-sev').toLowerCase());
      if (box) box.checked = true;
      applyFilter();
    }
    for (var n = target.parentNode; n && n !== doc; n = n.parentNode) { if (n.tagName === 'DETAILS') n.open = true; }
    target.focus();
    try { target.scrollIntoView({ block: 'start' }); } catch (e) { /* navegadores antiguos */ }
  }

  function fmtDate(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    return isNaN(d) ? String(iso) : d.toLocaleString('es-ES', { timeZone: 'Europe/Madrid', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });   // mismo formato que el resto: 11/11/2026, 10:29
  }

  /** Resumen ejecutivo (RadarPerfil.summary): el mismo contenido que abre los dos PDF. */
  function renderSummary(s) {
    var sec = el('section', { class: 'rw-dash__section rw-exec', id: 'resumen', 'aria-labelledby': 'resumen-t' }, [
      el('h3', { id: 'resumen-t', text: 'Resumen ejecutivo' }),
      el('p', { class: 'rw-exec__meta', text: s.url + ' · ' + fmtDate(s.fecha) + ' · Lectura: ' + s.perfilNombre }),
      el('p', { class: 'rw-exec__headline', text: s.titular })
    ]);
    if (!s.bloqueado && !state.conTarjetas) {
      sec.appendChild(el('ul', { class: 'rw-exec__scores' }, s.notas.map(function (n) {
        return el('li', {}, [
          el('span', { class: 'rw-exec__area', text: n.nombre }),
          el('strong', { text: n.nota === null || n.nota === undefined ? 'Sin nota' : RP.num(n.nota) + ' / 100' }),
          el('span', { class: 'rw-exec__range', text: (n.rango ? n.rango.texto : 'Sin datos suficientes') + (n.incompleta ? ' · incompleta' : '') })
        ]);
      })));
    }
    if (s.variacion) sec.appendChild(el('p', { class: 'rw-exec__line', text: s.variacion }));
    if (s.riesgo) sec.appendChild(el('p', { class: 'notice notice--risk', text: 'Riesgo alto: revisar de inmediato.' + (s.riesgoMotivos.length ? ' ' + s.riesgoMotivos.join('. ') + '.' : '') }));
    s.fallosBloque.forEach(function (t) { sec.appendChild(el('p', { class: 'notice notice--warn', text: t })); });

    if (!s.bloqueado) {
      sec.appendChild(el('h4', { text: 'Prioridades' }));
      if (s.prioridades.length) {
        sec.appendChild(el('ol', { class: 'rw-exec__list' }, s.prioridades.map(function (p) {
          var a = el('a', { href: '#punto-' + p.id, text: p.nombre });
          a.addEventListener('click', function (ev) { goToPoint(p.id, ev); });
          var meta = [];
          if (p.severidad) meta.push('gravedad ' + SEV_TEXT[p.severidad].toLowerCase());
          if (p.paginas) meta.push(p.paginas);
          if (p.esfuerzo || p.impacto) meta.push('esfuerzo ' + (p.esfuerzo || '—') + ', impacto ' + (p.impacto || '—'));
          return el('li', {}, [a, meta.length ? ' (' + meta.join('; ') + ')' : '', p.texto ? el('span', { class: 'rw-exec__do', text: p.texto }) : null]);
        })));
      }
      if (s.notaPrioridades) sec.appendChild(el('p', { class: 'rw-exec__line', text: s.notaPrioridades }));
      if (s.bien.length) {
        sec.appendChild(el('h4', { text: 'Lo que está bien' }));
        sec.appendChild(el('ul', { class: 'rw-exec__list' }, s.bien.map(function (b) { return el('li', { text: b.nombre }); })));
      }
      if (s.extra) sec.appendChild(el('p', { class: 'rw-exec__line' }, [el('strong', { text: s.extra.titulo + ': ' }), s.extra.texto]));
    }
    sec.appendChild(el('p', { class: 'rw-exec__line' }, [el('strong', { text: 'Cobertura: ' }), s.cobertura.texto]));
    if (s.cobertura.aviso) sec.appendChild(el('p', { class: 'notice notice--warn', text: s.cobertura.aviso }));
    sec.appendChild(el('p', { class: 'rw-help', text: s.alcance }));
    return sec;
  }

  // ---------------------------------------------------------------- tarjetas, filtro, evolución y cobertura

  /** Tres tarjetas (SEO, GEO, UX) con la nota de la URL, su rango y la variación frente al análisis anterior. Sin nota global. */
  function renderCards(m, s) {
    var cards = el('ul', { class: 'rw-cards' }, s.notas.map(function (n) {
      var pts = m.puntos.filter(function (p) { return p.conNota && ((i18n.bloque_checks || {})[p.id] || p.bloque) === n.area; });
      var ev = pts.filter(function (p) { return ['CUMPLE', 'AVISO', 'NO_CUMPLE'].indexOf(p.estado) !== -1; }).length;
      var has = n.nota !== null && n.nota !== undefined;
      return el('li', { class: 'rw-scorecard' + (n.rango ? ' rw-scorecard--' + n.rango.clave : '') }, [
        el('h3', { class: 'rw-scorecard__area', text: n.nombre }),
        el('p', { class: 'rw-scorecard__value' }, has
          ? [el('strong', { text: RP.num(n.nota) }), el('span', { class: 'rw-scorecard__max', text: ' / 100' })]
          : [el('strong', { text: 'Sin nota' })]),
        has ? RW.meter(n.nota, { etiqueta: n.nombre + ': ' + RP.num(n.nota) + ' de 100' }) : null,
        el('p', { class: 'rw-scorecard__range', text: (n.rango ? n.rango.texto : 'Sin datos suficientes') + (n.incompleta ? ' · nota incompleta' : '') }),
        m.anterior ? RW.delta(n.nota, m.anterior[n.area]) : null,
        el('p', { class: 'rw-scorecard__cov', text: RP.plural(ev, 'punto evaluado', 'puntos evaluados') + ' de ' + pts.length })
      ]);
    }));
    var wrap = el('section', { class: 'rw-dash__section', id: 'notas', 'aria-labelledby': 'notas-t' }, [
      el('h3', { id: 'notas-t', class: 'visually-hidden', text: 'Notas de la URL analizada' }), cards
    ]);
    if (m.sitio && m.sitio.media) {
      wrap.appendChild(el('p', { class: 'rw-help', text: 'Media del sitio (' + RP.plural(m.sitio.analizadas, 'página con nota', 'páginas con nota') + '): '
        + RP.AREAS.map(function (a) { return RP.areaName(i18n, a) + ' ' + RP.num(m.sitio.media[a]); }).join(' · ') + '. Es un dato secundario: las notas son las de la URL analizada.' }));
    }
    return wrap;
  }

  var SEV_FILTER = [['CRITICO', 'Crítica'], ['ALTO', 'Alta'], ['MEDIO', 'Media'], ['BAJO', 'Baja'], ['NINGUNA', 'Sin gravedad']];

  /** Filtro por gravedad del detalle (casillas de 44 px). Solo oculta en pantalla; el recuento se anuncia. */
  function severityFilter() {
    var status = el('p', { class: 'rw-help', id: 'filtro-estado', role: 'status', 'aria-live': 'polite' });
    var boxes = SEV_FILTER.map(function (s) {
      var id = 'filtro-sev-' + s[0].toLowerCase();
      var input = el('input', { type: 'checkbox', id: id, value: s[0] });
      input.checked = !!state.sev[s[0]];
      input.addEventListener('change', function () { state.sev[s[0]] = input.checked; applyFilter(); });
      return el('div', { class: 'rw-filter__option' }, [input, el('label', { for: id, text: s[1] })]);
    });
    return el('fieldset', { class: 'rw-filter' }, [el('legend', { text: 'Filtrar por gravedad' }), el('div', { class: 'rw-filter__options' }, boxes), status]);
  }

  function applyFilter() {
    if (!state.body) return;
    var pts = state.body.querySelectorAll('#detalle .rw-point');
    var shown = 0;
    Array.prototype.forEach.call(pts, function (li) {
      var ok = !!state.sev[li.getAttribute('data-sev')];
      li.hidden = !ok;
      if (ok) shown++;
    });
    var st = state.body.querySelector('#filtro-estado');
    if (st) st.textContent = shown === pts.length ? 'Se muestran los ' + pts.length + ' puntos.' : 'Se muestran ' + shown + ' de ' + pts.length + ' puntos.';
  }

  function svgEl(tag, attrs, text) {
    var n = doc.createElementNS(SVGNS, tag);
    Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function shortDate(iso) {
    var d = new Date(iso);
    return isNaN(d) ? String(iso || '') : d.toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
  }

  /**
   * Evolución de las tres notas de esta URL (historial). Solo con 2 puntos o más. SVG propio, sin animaciones:
   * cada área con su trazo (continuo, discontinuo, punteado) y su marcador, leyenda con texto y tabla equivalente.
   */
  function renderEvolution(m) {
    var rows = (m.evolucion || []).concat(m.bloqueado ? [] : [{ fecha: m.fecha, seo: m.notas.seo, ia: m.notas.ia, accesibilidad: m.notas.accesibilidad, actual: true }]);
    if (rows.length < 2) return null;
    var W = 640, H = 240, L = 36, R = 12, T = 14, B = 34;
    var x = function (i) { return L + i * (W - L - R) / (rows.length - 1); };
    var y = function (v) { return T + (100 - Math.max(0, Math.min(100, v))) * (H - T - B) / 100; };
    var s = svgEl('svg', { class: 'rw-evo', viewBox: '0 0 ' + W + ' ' + H, preserveAspectRatio: 'none', focusable: 'false' });
    [0, 50, 75, 90, 100].forEach(function (v) {
      s.appendChild(svgEl('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), class: 'rw-evo__grid' }));
      if (v !== 90) s.appendChild(svgEl('text', { x: L - 6, y: y(v) + 4, class: 'rw-evo__tick', 'text-anchor': 'end' }, String(v)));   // 90: solo la línea (umbral de «Muy bueno»)
    });
    rows.forEach(function (r, i) {
      if (rows.length <= 8 || i === 0 || i === rows.length - 1 || i % 2 === 0) {
        s.appendChild(svgEl('text', { x: x(i), y: H - 12, class: 'rw-evo__tick', 'text-anchor': i === 0 ? 'start' : (i === rows.length - 1 ? 'end' : 'middle') }, r.actual ? 'Hoy' : shortDate(r.fecha)));
      }
    });
    var series = [['seo', 'rw-evo__line--seo', 'continuo'], ['ia', 'rw-evo__line--ia', 'discontinuo'], ['accesibilidad', 'rw-evo__line--ux', 'punteado']];
    series.forEach(function (sr) {
      var pts = rows.map(function (r, i) { return r[sr[0]] === null || r[sr[0]] === undefined ? null : [x(i), y(r[sr[0]])]; });
      var seg = [];
      var flush = function () { if (seg.length > 1) s.appendChild(svgEl('polyline', { points: seg.join(' '), class: 'rw-evo__line ' + sr[1] })); seg = []; };
      pts.forEach(function (p) { if (p) seg.push(p[0] + ',' + p[1]); else flush(); });
      flush();
      pts.forEach(function (p) {
        if (!p) return;
        if (sr[0] === 'seo') s.appendChild(svgEl('circle', { cx: p[0], cy: p[1], r: 4, class: 'rw-evo__mark' }));
        else if (sr[0] === 'ia') s.appendChild(svgEl('rect', { x: p[0] - 4, y: p[1] - 4, width: 8, height: 8, class: 'rw-evo__mark rw-evo__mark--hollow' }));
        else s.appendChild(svgEl('path', { d: 'M' + p[0] + ' ' + (p[1] - 5) + ' L' + (p[0] + 5) + ' ' + (p[1] + 4) + ' L' + (p[0] - 5) + ' ' + (p[1] + 4) + ' Z', class: 'rw-evo__mark rw-evo__mark--ux' }));
      });
    });
    var legend = el('ul', { class: 'rw-evo__legend' }, series.map(function (sr) {
      return el('li', {}, [el('span', { class: 'rw-evo__key ' + sr[1], 'aria-hidden': 'true' }), RP.areaName(i18n, sr[0]) + ' (trazo ' + sr[2] + ')']);
    }));
    var last = rows[rows.length - 1], firstR = rows[0];
    var resumen = 'Del ' + (firstR.actual ? 'análisis actual' : shortDate(firstR.fecha)) + ' a hoy: ' + series.map(function (sr) {
      var d = RP.deltaText(last[sr[0]], firstR[sr[0]]);
      return RP.areaName(i18n, sr[0]) + ' ' + (d || 'sin datos');
    }).join(', ') + '.';
    var fig = RW.figure({
      titulo: 'Evolución de las notas de esta URL (' + RP.plural(rows.length, 'análisis', 'análisis') + ')',
      visual: el('div', {}, [s, legend]),
      resumen: resumen,
      tabla: {
        columnas: [{ texto: 'Análisis' }, { texto: 'SEO', num: true }, { texto: 'GEO', num: true }, { texto: 'UX', num: true }],
        filas: rows.map(function (r) { return [r.actual ? 'Este análisis' : shortDate(r.fecha), RP.num(r.seo), RP.num(r.ia), RP.num(r.accesibilidad)]; })
      },
      clave: 'evolucion'
    });
    // La leyenda debe leerse: se saca del bloque oculto a lectores (el gráfico sí se oculta; tiene resumen y tabla)
    var vis = fig.querySelector('.rw-figure__visual');
    if (vis) vis.parentNode.insertBefore(legend, vis.nextSibling);
    legend.setAttribute('aria-hidden', 'true');
    return el('section', { class: 'rw-dash__section', id: 'evolucion', 'aria-labelledby': 'evolucion-t' }, [
      el('h3', { id: 'evolucion-t', text: 'Evolución' }), fig
    ]);
  }

  /** Cobertura: evaluados, sin datos, pendientes y que no aplican (barra con leyenda y texto). */
  function renderCoverage(m, s) {
    var c = s.cobertura;
    var sec = el('section', { class: 'rw-dash__section', id: 'cobertura', 'aria-labelledby': 'cobertura-t' }, [
      el('h3', { id: 'cobertura-t', text: 'Cobertura' }),
      RW.segmentedBar([
        { clave: 'ok', texto: 'Evaluados', valor: c.evaluados },
        { clave: 'nodata', texto: 'Sin datos', valor: c.sin_datos },
        { clave: 'warn', texto: 'Pendientes de PageSpeed', valor: c.pendientes },
        { clave: 'na', texto: 'No aplican', valor: c.no_aplica }
      ], { baja: true }).node,
      el('p', { class: 'rw-help', text: c.texto })
    ]);
    if (c.aviso) sec.appendChild(el('p', { class: 'notice notice--warn', text: c.aviso }));
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
    var s = RP.summary(m, perfil, i18n);
    state.conTarjetas = !m.bloqueado;
    if (!m.bloqueado) state.body.appendChild(renderCards(m, s));
    state.body.appendChild(renderSummary(s));
    state.body.appendChild(renderBlocks(m, perfil));
    state.body.appendChild(renderNotEvaluated(m, perfil));
    var evo = renderEvolution(m);
    if (evo) state.body.appendChild(evo);
    state.body.appendChild(renderCoverage(m, s));
    applyFilter();
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
    summary: function () { var m = currentModel(); return m ? RP.summary(m, state.perfil, i18n) : null; },
    onProfile: function (fn) { state.listeners.push(fn); }
  };
})(window);
