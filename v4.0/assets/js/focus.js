/*
 * v4 · Vista por departamento (Marketing y contenidos, Desarrollo web, Diseño y UX, Dirección).
 * No cambia ninguna nota ni ningún estado: elige qué se enseña primero y cómo se explica.
 * Cada comprobación tiene un equipo que suele arreglarla y un «por qué importa» en lenguaje llano (lang/es.php 'enfoque').
 * Se puede cambiar de departamento con el informe en pantalla, sin volver a analizar.
 * Todo se pinta con textContent: nunca se interpreta HTML de la web analizada.
 */
(function (root) {
  'use strict';

  var SEV_ORDER = { CRITICO: 0, ALTO: 1, MEDIO: 2, BAJO: 3 };

  /**
   * Reparte los fallos y avisos entre equipos (función pura; la prueba tests/js/focus_test.js).
   * @param {Array} checks registros del informe ({id, estado, severidad, aplica, ...})
   * @param {Array} prioridades informe.prioridades ([{id}, ...], de más a menos importante)
   * @param {Object} enfoque {C01: ['desarrollo', 'por qué'], ...}
   * @return {{todos: Array, porEquipo: Object}} todos: ordenados por prioridad; porEquipo: {desarrollo: [...], ...}
   */
  function tasks(checks, prioridades, enfoque) {
    var rank = {};
    (prioridades || []).forEach(function (p, i) { rank[p.id] = i; });
    var list = (checks || []).filter(function (c) {
      return c.aplica !== false && (c.estado === 'NO_CUMPLE' || c.estado === 'AVISO');
    }).map(function (c) {
      var e = enfoque[c.id] || ['desarrollo', ''];
      return { check: c, equipo: e[0], porque: e[1] };
    });
    list.sort(function (a, b) {
      var fa = a.check.estado === 'NO_CUMPLE' ? 0 : 1;
      var fb = b.check.estado === 'NO_CUMPLE' ? 0 : 1;
      var ra = a.check.id in rank ? rank[a.check.id] : 999;
      var rb = b.check.id in rank ? rank[b.check.id] : 999;
      return fa - fb || ra - rb || (SEV_ORDER[a.check.severidad] || 9) - (SEV_ORDER[b.check.severidad] || 9)
        || (a.check.id < b.check.id ? -1 : 1);
    });
    var porEquipo = {};
    list.forEach(function (t) { (porEquipo[t.equipo] = porEquipo[t.equipo] || []).push(t); });
    return { todos: list, porEquipo: porEquipo };
  }

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { tasks: tasks };
    return;
  }

  var doc = root.document;

  function el(tag, attrs, children) {
    var n = doc.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (attrs[k] === null || attrs[k] === undefined) return;
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'class') n.className = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) {
      if (c === null || c === undefined) return;
      n.appendChild(typeof c === 'string' ? doc.createTextNode(c) : c);
    });
    return n;
  }

  function fmt(v) { return v === null || v === undefined ? '—' : Number(v).toLocaleString('es-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 }); }

  /** Una tarea: nombre, estado y, según el departamento, el porqué en llano y/o la recomendación. */
  function taskItem(t, i18n, perfil, showTeam) {
    var c = t.check;
    var estado = c.estado === 'NO_CUMPLE'
      ? 'Fallo de gravedad ' + String((i18n.severidades && i18n.severidades[c.severidad]) || c.severidad || '').toLowerCase()
      : 'Aviso';
    var plain = perfil === 'marketing' || perfil === 'direccion' || perfil === 'diseno';
    return el('li', { class: 'focus-task' }, [
      el('p', { class: 'focus-task__title' }, [
        el('strong', { text: (c.nombre || c.id) }),
        ' · ' + estado + (showTeam ? ' · ' + ((i18n.responsables || {})[t.equipo] || t.equipo) : '')
      ]),
      plain && t.porque ? el('p', { text: 'Por qué importa: ' + t.porque }) : null,
      perfil !== 'direccion' && c.recomendacion ? el('p', { text: 'Qué hacer: ' + c.recomendacion }) : null,
      perfil === 'web' && c.detalle ? el('p', { class: 'help', text: 'Medido: ' + c.detalle }) : null
    ]);
  }

  /** Recuento por equipo: «Desarrollo web: 5 (2 fallos críticos)». */
  function teamSummary(porEquipo, i18n, skip) {
    var keys = Object.keys(i18n.responsables || {}).filter(function (k) { return k !== skip && porEquipo[k]; });
    if (!keys.length) return null;
    return el('ul', { class: 'focus-teams' }, keys.map(function (k) {
      var items = porEquipo[k];
      var fails = items.filter(function (t) { return t.check.estado === 'NO_CUMPLE'; }).length;
      var crit = items.filter(function (t) { return t.check.estado === 'NO_CUMPLE' && t.check.severidad === 'CRITICO'; }).length;
      return el('li', {}, [
        el('strong', { text: i18n.responsables[k] + ': ' }),
        fails + (fails === 1 ? ' fallo' : ' fallos') + (crit ? ' (' + crit + (crit === 1 ? ' crítico' : ' críticos') + ')' : '')
          + ' y ' + (items.length - fails) + (items.length - fails === 1 ? ' aviso' : ' avisos') + ': '
          + items.map(function (t) { return t.check.nombre || t.check.id; }).join(', ') + '.'
      ]);
    }));
  }

  /**
   * Pinta la vista del departamento en host.
   * @param {Object} data respuesta de analyze.php (checks, puntuacion, informe, blocked)
   */
  function render(host, data, perfil, i18n) {
    host.textContent = '';
    var dep = (i18n.departamentos || {})[perfil] || (i18n.departamentos || {}).general || { nombre: '', intro: '' };
    host.appendChild(el('p', { class: 'focus-intro', text: dep.intro }));
    if (data.blocked) {
      host.appendChild(el('p', { text: 'La web ha bloqueado el acceso automático: no hay fallos que repartir.' }));
      return;
    }
    var t = tasks(data.checks, (data.informe || {}).prioridades, i18n.enfoque || {});

    if (perfil === 'direccion') {
      var p = data.puntuacion || {};
      var g = p.global || {};
      host.appendChild(el('p', { class: 'focus-headline' }, [
        'Nota global: ', el('strong', { text: fmt(g.redondeada) + ' / 100' }),
        ' (' + ['seo', 'ia', 'accesibilidad'].map(function (a) {
          return i18n.areas[a] + ' ' + fmt(((p.areas || {})[a] || {}).redondeada);
        }).join(' · ') + ').'
      ]));
      if (p.riesgo_alto) host.appendChild(el('p', { class: 'notice notice--fail', text: p.riesgo_mensaje || 'Riesgo alto: revisar de inmediato.' }));
      var top = t.todos.filter(function (x) { return x.check.estado === 'NO_CUMPLE'; }).slice(0, 5);
      host.appendChild(el('h4', { text: top.length ? 'Las ' + top.length + ' cosas más importantes' : 'Sin fallos detectables automáticamente' }));
      if (top.length) host.appendChild(el('ol', { class: 'focus-list' }, top.map(function (x) { return taskItem(x, i18n, perfil, true); })));
      host.appendChild(el('h4', { text: 'Reparto del trabajo por equipos' }));
      host.appendChild(teamSummary(t.porEquipo, i18n, null) || el('p', { text: 'No hay nada que repartir.' }));
      return;
    }

    if (!dep.responsable) {
      host.appendChild(el('h4', { text: 'Reparto del trabajo por equipos' }));
      host.appendChild(teamSummary(t.porEquipo, i18n, null) || el('p', { text: 'Sin fallos ni avisos detectables automáticamente.' }));
      return;
    }

    var mine = t.porEquipo[dep.responsable] || [];
    host.appendChild(el('h4', { text: mine.length ? 'Te toca (' + mine.length + ')' : 'Te toca' }));
    host.appendChild(mine.length
      ? el('ol', { class: 'focus-list' }, mine.map(function (x) { return taskItem(x, i18n, perfil, false); }))
      : el('p', { text: 'Nada pendiente de tu parte: sin errores detectables automáticamente.' }));
    var others = teamSummary(t.porEquipo, i18n, dep.responsable);
    if (others) {
      host.appendChild(el('h4', { text: 'Para pasar a otros equipos' }));
      host.appendChild(others);
    }
  }

  /** Equipo que suele arreglar una comprobación. */
  function owner(id, i18n) {
    return ((i18n.enfoque || {})[id] || ['desarrollo'])[0];
  }

  root.RadarFocus = { tasks: tasks, render: render, owner: owner };
})(typeof window !== 'undefined' ? window : this);
