/* Comparación de dos URLs (función 10). Lógica pura: no hace peticiones ni toca el DOM.
 * Cada URL se analiza con su propia petición a api/analyze.php (una URL por petición) y con el mismo Scorer;
 * aquí solo se ponen los dos resultados lado a lado. No cambia notas ni estados. Sin librerías. */
(function (global) {
  'use strict';

  var SCORE_KEYS = ['seo', 'ia', 'accesibilidad', 'global'];
  var COUNT_STATES = ['NO_CUMPLE', 'AVISO', 'SIN_DATOS', 'NA', 'CUMPLE'];

  /** Estado comparable de una comprobación: «NA» si no aplica; «SIN_DATOS» si falta en el resultado. */
  function stateOf(c) {
    if (!c) return 'SIN_DATOS';
    if (c.aplica === false) return 'NA';
    return String(c.estado || 'SIN_DATOS');
  }

  /** Casos de un fallo (solo NO_CUMPLE: es lo que cambia la penalización). */
  function casesOf(c) {
    return stateOf(c) === 'NO_CUMPLE' ? Math.max(1, Number(c.ocurrencias) || 1) : 0;
  }

  function round1(n) {
    return Math.round(n * 10) / 10;
  }

  /** Nota mostrada de un área o de la global (null si no se puede calcular: acceso bloqueado o sin datos). */
  function scoreOf(result, key) {
    if (!result || result.blocked || !result.puntuacion) return null;
    var s = key === 'global' ? result.puntuacion.global : (result.puntuacion.areas || {})[key];
    if (!s || typeof s.redondeada !== 'number') return null;
    return s.redondeada;
  }

  function incompleteOf(result, key) {
    if (!result || result.blocked || !result.puntuacion) return false;
    var s = key === 'global' ? result.puntuacion.global : (result.puntuacion.areas || {})[key];
    return !!(s && s.incompleta);
  }

  /**
   * Filas de notas: SEO, IA, accesibilidad y global. «diferencia» = URL 2 − URL 1 sobre las notas mostradas
   * (redondeadas), para que cuadre con lo que se ve; null si alguna de las dos no es calculable.
   */
  function scoreRows(a, b) {
    return SCORE_KEYS.map(function (k) {
      var va = scoreOf(a, k);
      var vb = scoreOf(b, k);
      return {
        clave: k,
        a: va,
        b: vb,
        incompleta_a: incompleteOf(a, k),
        incompleta_b: incompleteOf(b, k),
        diferencia: va === null || vb === null ? null : round1(vb - va)
      };
    });
  }

  /**
   * Filas de comprobaciones (unión de ids, ordenadas). «distinto» si cambia el estado o, siendo fallo en las
   * dos, cambia el número de casos (cambia lo que resta).
   */
  function checkRows(a, b) {
    var byId = function (list) {
      var out = {};
      (list || []).forEach(function (c) { if (c && c.id) out[c.id] = c; });
      return out;
    };
    var ca = byId(a && a.checks);
    var cb = byId(b && b.checks);
    var ids = Object.keys(ca);
    Object.keys(cb).forEach(function (id) { if (ids.indexOf(id) === -1) ids.push(id); });
    ids.sort();
    return ids.map(function (id) {
      var x = ca[id];
      var y = cb[id];
      var sa = stateOf(x);
      var sb = stateOf(y);
      var na = casesOf(x);
      var nb = casesOf(y);
      return {
        id: id,
        nombre: (x && x.nombre) || (y && y.nombre) || id,
        severidad: (x && x.severidad) || (y && y.severidad) || null,
        a: { estado: sa, casos: na },
        b: { estado: sb, casos: nb },
        distinto: sa !== sb || na !== nb
      };
    });
  }

  /** Recuento de estados de un resultado (NO_CUMPLE, AVISO, SIN_DATOS, NA, CUMPLE). */
  function counts(result) {
    var out = {};
    COUNT_STATES.forEach(function (s) { out[s] = 0; });
    ((result && result.checks) || []).forEach(function (c) {
      var s = stateOf(c);
      out[s] = (out[s] || 0) + 1;
    });
    return out;
  }

  /** Diferencia con signo y coma decimal: «+12,3», «−5,0», «0,0». */
  function diffText(d) {
    if (d === null || d === undefined) return '—';
    var abs = Math.abs(d).toFixed(1).replace('.', ',');
    if (d > 0) return '+' + abs;
    if (d < 0) return '−' + abs;
    return '0,0';
  }

  /** Texto de un estado con sus casos: «Fallo (7 casos)». */
  function stateText(cell, i18n) {
    var label = cell.estado === 'NA' ? 'No aplica' : ((i18n && i18n.estados && i18n.estados[cell.estado]) || cell.estado);
    return cell.casos > 1 ? label + ' (' + cell.casos + ' casos)' : label;
  }

  global.AnalyzerCompare = {
    SCORE_KEYS: SCORE_KEYS,
    stateOf: stateOf,
    scoreRows: scoreRows,
    checkRows: checkRows,
    counts: counts,
    diffText: diffText,
    stateText: stateText
  };
})(window);
