/*
 * Radar web · componentes visuales del informe (docs/DESIGN.md y docs/COMPONENTES.md).
 *
 * Funciones que reciben arrays u objetos de datos y devuelven nodos DOM. Todo el texto se escribe con
 * textContent y los atributos con setAttribute (nunca se interpreta HTML), así que lo que llega de la web analizada
 * queda escapado. Los anchos se fijan con el CSSOM (element.style), compatible con la CSP de la pantalla.
 * Los componentes no saben de qué bloque (SEO, Preparación para IA, Accesibilidad) viene el dato ni
 * calculan notas: solo dibujan lo que reciben. Colores y texturas, en assets/css/componentes.css y graficos.css.
 */
(function (root) {
  'use strict';

  var SVG_NS = 'http://www.w3.org/2000/svg';

  // Estado → clave visual. «No aplica» no es un estado del motor: se pasa como 'NA'.
  var STATUS_KEY = { CUMPLE: 'ok', AVISO: 'warn', NO_CUMPLE: 'fail', SIN_DATOS: 'nodata', NA: 'na' };
  var STATUS_ICON = { ok: '✓', warn: '!', fail: '✕', nodata: '–', na: '○' };
  // Textos por defecto (los mismos que lang/es.php; la pantalla puede pasar los suyos)
  var STATUS_TEXT = {
    CUMPLE: 'Sin errores detectables automáticamente',
    AVISO: 'Aviso',
    NO_CUMPLE: 'Fallo',
    SIN_DATOS: 'Sin datos',
    NA: 'No aplica'
  };
  var SEVERITY_KEY = { BAJO: 'baja', MEDIO: 'media', ALTO: 'alta', CRITICO: 'critica' };
  var SEVERITY_TEXT = { BAJO: 'Baja', MEDIO: 'Media', ALTO: 'Alta', CRITICO: 'Crítica' };

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    setAttrs(node, attrs);
    append(node, children);
    return node;
  }

  function svg(tag, attrs) {
    var node = document.createElementNS(SVG_NS, tag);
    setAttrs(node, attrs);
    return node;
  }

  function setAttrs(node, attrs) {
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') node.setAttribute('class', v);
      else if (k === 'text') node.textContent = String(v);
      else node.setAttribute(k, v === true ? '' : String(v));
    });
  }

  function append(node, children) {
    (children || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) return;
      node.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
    });
  }

  function num(n, decimals) {
    if (n === null || n === undefined || isNaN(n)) return '—';
    var d = decimals === undefined ? 1 : decimals;
    return Number(n).toLocaleString('es-ES', { minimumFractionDigits: d, maximumFractionDigits: d });
  }

  function clampPct(v) {
    return Math.max(0, Math.min(100, Number(v) || 0));
  }

  function width(node, pct) {
    node.style.width = clampPct(pct) + '%';   // CSSOM: permitido por la CSP (no hay atributos style)
    return node;
  }

  var uid = 0;
  function nextId(prefix) {
    uid += 1;
    return (prefix || 'rw') + '-' + uid;
  }

  /** Clave visual de un estado del motor (o 'NA'). Desconocido → sin datos: nunca se dibuja como éxito ni fallo. */
  function statusKey(estado) {
    return STATUS_KEY[estado] || 'nodata';
  }

  /**
   * Estado: muestra (relleno o textura) + icono + texto. Nunca depende solo del color.
   * @param {string} estado CUMPLE | AVISO | NO_CUMPLE | SIN_DATOS | NA
   * @param {Object} [opts] {texto: etiqueta propia, compacto: solo muestra con texto oculto (celdas)}
   */
  function status(estado, opts) {
    opts = opts || {};
    var key = statusKey(estado);
    var text = opts.texto || STATUS_TEXT[estado] || STATUS_TEXT.SIN_DATOS;
    if (opts.compacto) {
      return el('span', { class: 'rw-status rw-status--compact rw-st--' + key }, [
        el('span', { class: 'rw-swatch', 'aria-hidden': 'true' }),
        el('span', { class: 'rw-visually-hidden', text: text })
      ]);
    }
    return el('span', { class: 'rw-status rw-st--' + key }, [
      el('span', { class: 'rw-swatch', 'aria-hidden': 'true' }),
      el('span', { class: 'rw-status__icon', 'aria-hidden': 'true', text: STATUS_ICON[key] }),
      el('span', { class: 'rw-status__text', text: text })
    ]);
  }

  /** Chip de gravedad: texto siempre visible; la marca de la rampa del rojo es un refuerzo. */
  function severityChip(severidad, texto) {
    if (!severidad) return null;
    return el('span', { class: 'rw-chip rw-sev--' + (SEVERITY_KEY[severidad] || 'media') }, [
      el('span', { class: 'rw-chip__mark', 'aria-hidden': 'true' }),
      'Gravedad ' + String(texto || SEVERITY_TEXT[severidad] || severidad).toLowerCase()
    ]);
  }

  /**
   * Medidor 0–100 (sin colores por valor: no hay umbrales acordados). Decorativo por defecto, porque la cifra
   * va escrita al lado; con opts.etiqueta se anuncia como imagen con ese texto.
   */
  function meter(value, opts) {
    opts = opts || {};
    var fill = width(el('span', { class: 'rw-meter__fill' }), value);
    var attrs = opts.etiqueta ? { class: 'rw-meter', role: 'img', 'aria-label': opts.etiqueta } : { class: 'rw-meter', 'aria-hidden': 'true' };
    return el('span', attrs, [fill]);
  }

  /** Tabla equivalente de un gráfico. columnas: [{texto, num}]; filas: [[celda, …]] (la primera celda es cabecera de fila). */
  function table(spec) {
    var cols = spec.columnas || [];
    return el('div', { class: 'rw-table-wrap' }, [el('table', { class: 'rw-table' }, [
      spec.titulo ? el('caption', { text: spec.titulo }) : null,
      el('thead', {}, [el('tr', {}, cols.map(function (c) {
        return el('th', { scope: 'col', class: c.num ? 'rw-num' : null, text: c.texto });
      }))]),
      el('tbody', {}, (spec.filas || []).map(function (row) {
        return el('tr', {}, row.map(function (cell, i) {
          var isNum = cols[i] && cols[i].num;
          var content = cell && cell.nodeType ? [cell] : [cell === null || cell === undefined ? '—' : String(cell)];
          return i === 0 ? el('th', { scope: 'row' }, content) : el('td', { class: isNum ? 'rw-num' : null }, content);
        }));
      }))
    ])]);
  }

  /**
   * Figura de un gráfico: título que es la conclusión, visual (oculto a lectores de pantalla), resumen textual
   * y tabla equivalente plegada. spec: {titulo, resumen, resumenOculto, visual: Node, tabla: {columnas, filas}, clave}
   * resumenOculto: el resumen solo se anuncia a lectores de pantalla (cuando la leyenda visible ya lo dice).
   */
  function figure(spec) {
    var capId = nextId('rw-cap');
    return el('figure', { class: 'rw-figure', 'aria-labelledby': capId }, [
      el('figcaption', { id: capId, class: 'rw-figure__title', text: spec.titulo }),
      spec.visual ? el('div', { class: 'rw-figure__visual', 'aria-hidden': 'true' }, [spec.visual]) : null,
      spec.resumen ? el('p', { class: 'rw-figure__summary' + (spec.resumenOculto ? ' rw-visually-hidden' : ''), text: spec.resumen }) : null,
      spec.tabla ? el('details', { class: 'rw-details rw-figure__data', 'data-key': spec.clave ? 'tabla-' + spec.clave : null }, [
        el('summary', { text: 'Ver los datos en una tabla' }),
        table({ titulo: spec.titulo, columnas: spec.tabla.columnas, filas: spec.tabla.filas })
      ]) : null
    ]);
  }

  /** Aviso de gráfico sin datos: se explica el motivo en lugar de dibujar un gráfico vacío. */
  function empty(titulo, motivo) {
    return el('div', { class: 'rw-empty' }, [
      el('p', { class: 'rw-empty__title', text: titulo }),
      el('p', { class: 'rw-empty__text', text: 'Sin datos suficientes: ' + motivo })
    ]);
  }

  /**
   * Barra segmentada (máx. 5 tramos con valor > 0, 2 px de separación) con leyenda visible.
   * segmentos: [{clave, texto, valor}]; clave de estado (ok, warn, fail, nodata, na) o de gravedad
   * (sev-baja, sev-media, sev-alta, sev-critica).
   */
  function segmentedBar(segmentos, opts) {
    opts = opts || {};
    var segs = (segmentos || []).filter(function (s) { return Number(s.valor) > 0; }).slice(0, 5);
    var total = segs.reduce(function (n, s) { return n + Number(s.valor); }, 0);
    var bar = el('div', { class: 'rw-segbar' + (opts.baja ? ' rw-segbar--low' : '') }, segs.map(function (s) {
      var seg = el('span', { class: 'rw-seg ' + segClass(s.clave) });
      seg.style.flexGrow = String(s.valor);   // proporcional; los 2 px de separación no deforman el reparto
      return seg;
    }));
    var legend = el('ul', { class: 'rw-legend' }, segs.map(function (s) {
      return el('li', {}, [
        el('span', { class: 'rw-swatch ' + segClass(s.clave), 'aria-hidden': 'true' }),
        el('span', { class: 'rw-legend__icon', 'aria-hidden': 'true', text: STATUS_ICON[s.clave] || '' }),
        el('span', {}, [s.texto + ': ', el('strong', { text: String(s.valor) })])
      ]);
    }));
    return { node: el('div', { class: 'rw-segbar-wrap' }, [bar, legend]), total: total, segmentos: segs };
  }

  function segClass(clave) {
    return /^sev-/.test(clave) ? 'rw-' + clave : 'rw-st--' + clave;
  }

  /**
   * Barras horizontales ordenadas de mayor a menor (máx. 5), un solo color, empiezan en cero.
   * items: [{texto, valor, valorTexto}]
   */
  function rankedBars(items, opts) {
    opts = opts || {};
    var list = (items || []).slice().sort(function (a, b) { return b.valor - a.valor; }).slice(0, opts.max || 5);
    var max = list.reduce(function (m, x) { return Math.max(m, Number(x.valor) || 0); }, 0) || 1;
    return el('ul', { class: 'rw-bars' }, list.map(function (x) {
      return el('li', { class: 'rw-bars__row' }, [
        el('span', { class: 'rw-bars__label', text: x.texto }),
        el('span', { class: 'rw-bars__track' }, [width(el('span', { class: 'rw-bars__bar' }), (Number(x.valor) || 0) / max * 100)]),
        el('span', { class: 'rw-bars__value', text: x.valorTexto || num(x.valor) })
      ]);
    }));
  }

  /**
   * Gráfico de bala de una métrica con tres franjas grises (bueno, mejorable, deficiente) y marcador negro.
   * m: {nombre, valor, valorTexto, bueno, deficiente, maximo, franja: 'bueno'|'mejorable'|'deficiente'}
   */
  function bullet(m) {
    var max = Math.max(Number(m.maximo) || 0, Number(m.deficiente) * 1.5, Number(m.valor) * 1.05);
    var pct = function (v) { return Number(v) / max * 100; };
    var track = el('span', { class: 'rw-bullet__track' }, [
      width(el('span', { class: 'rw-bullet__band rw-bullet__band--good' }), pct(m.bueno)),
      width(el('span', { class: 'rw-bullet__band rw-bullet__band--mid' }), pct(m.deficiente) - pct(m.bueno)),
      el('span', { class: 'rw-bullet__band rw-bullet__band--poor' })
    ]);
    var marker = el('span', { class: 'rw-bullet__marker' });
    marker.style.left = clampPct(pct(m.valor)) + '%';
    track.appendChild(marker);
    return el('div', { class: 'rw-bullet' }, [
      el('span', { class: 'rw-bullet__name', text: m.nombre }),
      track,
      el('span', { class: 'rw-bullet__value' }, [el('strong', { text: m.valorTexto }), ' · ' + m.franja])
    ]);
  }

  /**
   * Matriz páginas × puntos con estados compactos y leyenda. Desplazamiento horizontal con la primera columna fija.
   * spec: {titulo, columnas: [{id, texto}], filas: [{texto, enlace, celdas: {id: estado}}], textos: {estado: texto}}
   */
  function matrix(spec) {
    var texts = spec.textos || STATUS_TEXT;
    var keys = ['CUMPLE', 'AVISO', 'NO_CUMPLE', 'SIN_DATOS', 'NA'];
    return el('div', { class: 'rw-matrix-block' }, [
      el('ul', { class: 'rw-legend' }, keys.map(function (k) {
        return el('li', {}, [status(k, { compacto: true, texto: texts[k] || STATUS_TEXT[k] }), el('span', { 'aria-hidden': 'true', text: ' ' + (texts[k] || STATUS_TEXT[k]) })]);
      })),
      el('div', { class: 'rw-table-wrap rw-matrix-wrap', tabindex: '0', role: 'region', 'aria-label': spec.titulo + ' (desplazable)' }, [
        el('table', { class: 'rw-matrix' }, [
          el('caption', { class: 'rw-visually-hidden', text: spec.titulo }),
          el('thead', {}, [el('tr', {}, [el('th', { scope: 'col', text: 'Página' })].concat((spec.columnas || []).map(function (c) {
            return el('th', { scope: 'col', title: c.texto }, [el('abbr', { title: c.texto, text: c.id })]);
          })))]),
          el('tbody', {}, (spec.filas || []).map(function (r) {
            var head = r.enlace ? el('a', { href: r.enlace, rel: 'noopener noreferrer', text: r.texto }) : r.texto;
            return el('tr', {}, [el('th', { scope: 'row' }, [head])].concat((spec.columnas || []).map(function (c) {
              var e = r.celdas[c.id];
              return el('td', {}, [status(e || 'SIN_DATOS', { compacto: true, texto: c.id + ': ' + (texts[e || 'SIN_DATOS'] || STATUS_TEXT[e] || STATUS_TEXT.SIN_DATOS) })]);
            })));
          }))
        ])
      ])
    ]);
  }

  /**
   * Diferencia con el análisis anterior: ▲ en negro, ▼ en rojo, siempre con texto.
   * Devuelve null si falta alguno de los dos valores.
   */
  function delta(actual, anterior) {
    if (actual === null || actual === undefined || anterior === null || anterior === undefined) return null;
    var d = Math.round((Number(actual) - Number(anterior)) * 10) / 10;
    if (d === 0) return el('span', { class: 'rw-delta rw-delta--same', text: '= sin cambios respecto al análisis anterior' });
    var up = d > 0;
    return el('span', { class: 'rw-delta ' + (up ? 'rw-delta--up' : 'rw-delta--down') }, [
      el('span', { 'aria-hidden': 'true', text: up ? '▲ ' : '▼ ' }),
      (up ? '+' : '−') + num(Math.abs(d)) + (up ? ' (sube)' : ' (baja)') + ' respecto al análisis anterior'
    ]);
  }

  /**
   * Minigráfico de evolución (0–100 fijo), del más antiguo al más reciente; último punto marcado y etiquetado.
   * Solo con 3 valores o más; si no, null.
   */
  function sparkline(values, opts) {
    opts = opts || {};
    var vals = (values || []).filter(function (v) { return v !== null && v !== undefined && !isNaN(v); });
    if (vals.length < 3) return null;
    var w = 120, h = 32, pad = 4;
    var x = function (i) { return pad + i * (w - 2 * pad - 28) / (vals.length - 1); };
    var y = function (v) { return pad + (100 - clampPct(v)) * (h - 2 * pad) / 100; };
    var s = svg('svg', { class: 'rw-spark', viewBox: '0 0 ' + w + ' ' + h, width: w, height: h, role: 'img',
      'aria-label': opts.etiqueta || ('Evolución: ' + vals.map(function (v) { return num(v); }).join(', ')) });
    s.appendChild(svg('line', { x1: pad, y1: h - pad, x2: w - pad - 28, y2: h - pad, class: 'rw-spark__base' }));
    s.appendChild(svg('polyline', { class: 'rw-spark__line', points: vals.map(function (v, i) { return x(i) + ',' + y(v); }).join(' ') }));
    var last = vals.length - 1;
    s.appendChild(svg('circle', { class: 'rw-spark__dot', cx: x(last), cy: y(vals[last]), r: 4 }));
    var t = svg('text', { class: 'rw-spark__label', x: x(last) + 7, y: Math.max(11, y(vals[last]) + 4) });
    t.textContent = num(vals[last]);
    s.appendChild(t);
    return s;
  }

  root.RadarComponents = {
    el: el,
    num: num,
    statusKey: statusKey,
    status: status,
    severityChip: severityChip,
    meter: meter,
    table: table,
    figure: figure,
    empty: empty,
    segmentedBar: segmentedBar,
    rankedBars: rankedBars,
    bullet: bullet,
    matrix: matrix,
    delta: delta,
    sparkline: sparkline,
    STATUS_TEXT: STATUS_TEXT
  };
})(typeof window !== 'undefined' ? window : this);
