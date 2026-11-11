/*
 * Radar web · perfiles de lectura (marketing, dirección, desarrollo web), modelo único del informe y resumen
 * ejecutivo. Funciones puras: reciben datos y devuelven datos (sin DOM), para que la pantalla y los dos PDF usen
 * exactamente lo mismo y nunca se contradigan. Se prueban en tests/js/perfiles_test.html.
 *
 * Reglas (config/perfiles.php, lang/perfiles.php):
 * - El perfil NO cambia notas, estados, pesos ni datos medidos. Solo el orden a igualdad de peso, el lenguaje,
 *   el nivel de detalle y la acción sugerida.
 * - Lista blanca de perfiles: cualquier otro valor cae al perfil por defecto.
 * - Textos de plantilla con datos reales del análisis: nada se inventa ni se pide a ningún servicio.
 */
(function (root) {
  'use strict';

  var PERFILES = ['marketing', 'direccion', 'desarrollo'];
  var AREAS = ['seo', 'ia', 'accesibilidad'];
  var BLOQUES = ['seo', 'ia', 'accesibilidad', 'web'];
  var SEV_ORDER = { CRITICO: 0, ALTO: 1, MEDIO: 2, BAJO: 3 };
  var REL_ORDER = { alta: 0, media: 1, baja: 2 };
  var STATE_RANK = { NO_CUMPLE: 4, AVISO: 3, CUMPLE: 2, SIN_DATOS: 1, NA: 0 };
  // Puntos de todo el sitio: los que la portada comprueba para todo el sitio y los que agrega el navegador
  var WEB_IDS = ['C16', 'C18', 'WEB-TITULOS', 'WEB-404'];

  // ---------------------------------------------------------------- perfil

  /** Perfil válido o el de por defecto (lista blanca; nunca se devuelve el valor recibido si no es válido). */
  function normalize(value, data) {
    var def = data && PERFILES.indexOf(data.por_defecto) !== -1 ? data.por_defecto : 'desarrollo';
    return typeof value === 'string' && PERFILES.indexOf(value) !== -1 ? value : def;
  }

  function relevance(data, id, perfil) {
    var r = data && data.relevancia && data.relevancia[id] && data.relevancia[id][perfil];
    return REL_ORDER[r] === undefined ? 'media' : r;
  }

  /** Rango de una nota con los umbrales de config/perfiles.php (sobre la nota redondeada a un decimal). */
  function range(data, score) {
    if (score === null || score === undefined || isNaN(score)) return null;
    var v = Math.round(Number(score) * 10) / 10;
    var list = (data && data.rangos) || [];
    for (var i = 0; i < list.length; i++) {
      if (v >= Number(list[i].desde)) return { clave: list[i].clave, texto: list[i].texto };
    }
    return null;
  }

  // ---------------------------------------------------------------- utilidades

  function num(v, d) {
    if (v === null || v === undefined || isNaN(v)) return '—';
    var dec = d === undefined ? 1 : d;
    return Number(v).toLocaleString('es-ES', { minimumFractionDigits: 0, maximumFractionDigits: dec });
  }

  /** «1 punto» / «3 puntos». */
  function plural(n, one, many) {
    return n + ' ' + (n === 1 ? one : many);
  }

  /** Une una lista en español: «a», «a y b», «a, b y c». */
  function joinList(items) {
    var xs = (items || []).filter(function (x) { return x !== '' && x !== null && x !== undefined; });
    if (xs.length <= 1) return xs.join('');
    return xs.slice(0, -1).join(', ') + ' y ' + xs[xs.length - 1];
  }

  /** Valor medido de una comprobación en texto breve (escalares, listas y objetos sencillos). */
  function fmtValue(v) {
    if (v === null || v === undefined || v === '') return '';
    if (typeof v === 'boolean') return v ? 'sí' : 'no';
    if (typeof v === 'number') return num(v, 2);
    if (typeof v === 'string') return v.length > 160 ? v.slice(0, 157) + '…' : v;
    if (Array.isArray(v)) return fmtValue(v.map(function (x) { return typeof x === 'object' ? fmtValue(x) : String(x); }).join(', '));
    if (typeof v === 'object') {
      return fmtValue(Object.keys(v).filter(function (k) { return v[k] !== null && typeof v[k] !== 'object'; })
        .map(function (k) { return k.replace(/_/g, ' ') + ': ' + (typeof v[k] === 'boolean' ? (v[k] ? 'sí' : 'no') : v[k]); }).join('; '));
    }
    return String(v);
  }

  // ---------------------------------------------------------------- modelo único

  function combine(a, b) {
    return (STATE_RANK[a] || 0) >= (STATE_RANK[b] || 0) ? a : b;
  }

  /** Estado de una fila del sitio agregado (assets/js/site.js aggregate().checks). */
  function siteState(row) {
    if (!row) return null;
    if (row.fallan > 0) return 'NO_CUMPLE';
    if (row.avisos > 0) return 'AVISO';
    if (row.evaluadas > 0) return 'CUMPLE';
    if (row.sin_datos > 0) return 'SIN_DATOS';
    return 'NA';
  }

  /**
   * Modelo único del informe: notas de la URL analizada (las que calcula el Scorer y guarda el historial) y
   * lista de puntos con el estado de la URL y, si ha terminado, del sitio entero.
   *
   * @param {Object} data respuesta de api/analyze.php (o api/score.php): url, fecha, checks, puntuacion, informe,
   *                      blocked, historial
   * @param {Object} opts {i18n, psiPendiente: bool, psiFallo: string|null, sitio: modelo de site.js o null}
   */
  function model(data, opts) {
    opts = opts || {};
    var i18n = opts.i18n || {};
    var blocked = !!(data && data.blocked);
    var p = (data && data.puntuacion) || { areas: {} };
    var cov = (data && data.informe && data.informe.cobertura) || {};
    var bloque = i18n.bloque_checks || {};
    var names = i18n.nombres_checks || {};
    var psiIds = Object.keys(i18n.psi_checks || {});
    var pendientes = opts.psiPendiente && !blocked ? psiIds : [];

    var notas = {};
    AREAS.forEach(function (a) {
      var s = p.areas && p.areas[a];
      var c = cov[a] || {};
      var evaluated = c.evaluados === undefined ? 1 : c.evaluados;
      notas[a] = blocked || !s || evaluated === 0 ? null : s.redondeada;
    });

    var hist = data && data.historial;
    var prevRow = hist && hist.activo && hist.anteriores && hist.anteriores.length ? hist.anteriores[0] : null;
    var anteriores = hist && hist.activo && hist.anteriores ? hist.anteriores : [];

    var sitio = opts.sitio && !opts.sitio.bloqueado ? opts.sitio : null;
    var siteRows = {};
    ((sitio && sitio.checks) || []).forEach(function (r) { siteRows[r.id] = r; });

    var points = {};
    var add = function (pt) { points[pt.id] = pt; };
    ((data && data.checks) || []).forEach(function (c) {
      var urlState = c.aplica === false ? 'NA' : (c.estado || 'SIN_DATOS');
      add({
        id: c.id,
        nombre: c.nombre || names[c.id] || c.id,
        bloque: WEB_IDS.indexOf(c.id) !== -1 ? 'web' : (bloque[c.id] || 'seo'),
        severidad: c.severidad || null,
        impacto: c.impacto || null,
        esfuerzo: c.esfuerzo || null,
        respaldo: c.respaldo || null,
        estadoUrl: urlState,
        estado: urlState,
        detalle: c.detalle || '',
        encontrado: fmtValue(c.valor_medido),
        esperado: fmtValue(c.umbral),
        recomendacion: c.recomendacion || '',
        ocurrencias: c.ocurrencias || null,
        sitio: null,
        pendiente: false,
        conNota: true
      });
    });
    // C08 y C14 mientras se espera a PageSpeed: sin datos y marcados como pendientes
    pendientes.forEach(function (id) {
      if (!points[id] || points[id].estado === 'SIN_DATOS') {
        var base = points[id] || { id: id, nombre: names[id] || id, bloque: bloque[id] || 'accesibilidad', severidad: null, impacto: null, esfuerzo: null,
          respaldo: null, detalle: '', encontrado: '', esperado: '', recomendacion: '', sitio: null, conNota: true };
        base.estado = base.estadoUrl = 'SIN_DATOS';
        base.pendiente = true;
        add(base);
      }
    });
    // Sitio entero: el estado del punto es el peor entre la URL y las páginas del sitio
    Object.keys(siteRows).forEach(function (id) {
      if (id === 'C16' || id === 'C18') return;   // se comprueban una vez, en la portada (la URL)
      var r = siteRows[id];
      var pt = points[id];
      if (!pt) {
        pt = { id: id, nombre: r.nombre || names[id] || id, bloque: bloque[id] || 'seo', severidad: r.severidad || null, impacto: null, esfuerzo: null,
          respaldo: null, estadoUrl: null, estado: 'SIN_DATOS', detalle: '', encontrado: '', esperado: '', recomendacion: '', pendiente: false, conNota: true };
        add(pt);
      }
      pt.sitio = { evaluadas: r.evaluadas, fallan: r.fallan, avisos: r.avisos, sin_datos: r.sin_datos, ejemplos: (r.ejemplos || []).map(function (x) { return x.url; }) };
      pt.estado = combine(pt.estado, siteState(r));
      if (!pt.recomendacion) pt.recomendacion = r.recomendacion || r.recomendacion_aviso || '';
    });
    // Puntos de todo el sitio que no son una comprobación: solo existen cuando el sitio ha terminado
    if (sitio) {
      var dup = sitio.titulos_repetidos || [];
      var dupPages = dup.reduce(function (n, g) { return n + g.urls.length; }, 0);
      add({
        id: 'WEB-TITULOS', nombre: textGeneral(i18n, 'WEB-TITULOS', 'nombre') || 'Títulos repetidos', bloque: 'web', severidad: null, impacto: null, esfuerzo: null,
        respaldo: null, estadoUrl: null, estado: dup.length ? 'NO_CUMPLE' : (sitio.con_titulo > 1 ? 'CUMPLE' : 'SIN_DATOS'),
        detalle: dup.length ? dupPages + ' de ' + sitio.con_titulo + ' páginas comparten title con otra. Por ejemplo: '
          + dup.slice(0, 3).map(function (g) { return '«' + g.titulo + '»'; }).join(', ') + '.' : '',
        encontrado: dup.length ? plural(dup.length, 'title repetido', 'titles repetidos') : '', esperado: 'Un title distinto por página',
        recomendacion: textGeneral(i18n, 'WEB-TITULOS', 'hacer'), sitio: null, pendiente: false, conNota: false
      });
      var c404 = sitio.pagina_404;
      if (c404 && c404.estado) {
        add({
          id: 'WEB-404', nombre: textGeneral(i18n, 'WEB-404', 'nombre') || 'Página de error 404', bloque: 'web', severidad: null, impacto: null, esfuerzo: null,
          respaldo: null, estadoUrl: null, estado: c404.estado, detalle: c404.detalle || '', encontrado: '', esperado: 'Código 404',
          recomendacion: textGeneral(i18n, 'WEB-404', 'hacer'), sitio: null, pendiente: false, conNota: false
        });
      }
    }

    var list = Object.keys(points).map(function (k) { return points[k]; });
    var checks = list.filter(function (x) { return x.conNota; });
    var cobertura = {
      total: checks.length,
      evaluados: checks.filter(function (x) { return ['CUMPLE', 'AVISO', 'NO_CUMPLE'].indexOf(x.estado) !== -1; }).length,
      sin_datos: checks.filter(function (x) { return x.estado === 'SIN_DATOS' && !x.pendiente; }).length,
      pendientes: checks.filter(function (x) { return x.pendiente; }).length,
      no_aplica: checks.filter(function (x) { return x.estado === 'NA'; }).length
    };

    return {
      url: data ? data.url : '',
      fecha: data ? data.fecha : null,
      bloqueado: blocked,
      mensajeBloqueo: blocked && data.blocked ? data.blocked.message || '' : '',
      notas: notas,
      incompletas: AREAS.filter(function (a) { return p.areas && p.areas[a] && p.areas[a].incompleta; }),
      anterior: prevRow ? { fecha: prevRow.fecha, seo: prevRow.nota_seo, ia: prevRow.nota_ia, accesibilidad: prevRow.nota_accesibilidad } : null,
      evolucion: anteriores.slice(0, 9).reverse().map(function (r) { return { fecha: r.fecha, seo: r.nota_seo, ia: r.nota_ia, accesibilidad: r.nota_accesibilidad }; }),
      riesgo: !!p.riesgo_alto,
      riesgoMotivos: p.riesgo_motivos || [],
      psiPendiente: pendientes.length > 0,
      psiFallo: opts.psiFallo || null,
      sitio: sitio ? { analizadas: sitio.analizadas, media: sitio.media, minima: sitio.minima, meta: sitio.meta || [] } : null,
      sitioTerminado: !!sitio,
      puntos: list,
      cobertura: cobertura
    };
  }

  function textGeneral(i18n, id, field) {
    var t = i18n.perfiles && i18n.perfiles.puntos && i18n.perfiles.puntos[id] && i18n.perfiles.puntos[id].general;
    return t && t[field] ? t[field] : '';
  }

  // ---------------------------------------------------------------- orden y textos

  function weight(i18n, pt) {
    var pen = i18n.penalizaciones || { CRITICO: 15, ALTO: 8, MEDIO: 4, BAJO: 1 };
    return pt.severidad && pen[pt.severidad] !== undefined ? pen[pt.severidad] : 0;
  }

  /**
   * Orden de los puntos dentro de un grupo: peso (gravedad, como el Scorer), después relevancia para el perfil,
   * después páginas afectadas y, por último, el ID. Orden estable: no depende del orden de llegada.
   */
  function sortPoints(points, perfil, i18n) {
    var data = i18n.perfiles || {};
    return points.slice().sort(function (a, b) {
      return (weight(i18n, b) - weight(i18n, a))
        || (REL_ORDER[relevance(data, a.id, perfil)] - REL_ORDER[relevance(data, b.id, perfil)])
        || (((b.sitio && b.sitio.fallan) || 0) - ((a.sitio && a.sitio.fallan) || 0))
        || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0);
    });
  }

  /**
   * Textos de un punto para un perfil: {significa, importa, hacer, comprobar, respaldo, respaldo_clave,
   * respaldoPerfil, pendiente}. Si falta una variante se usa el texto general y pendiente = true (el punto debe
   * figurar en docs/PENDIENTES_PERFILES.md); nunca se devuelve un texto vacío si hay uno general.
   */
  function pointText(pt, perfil, i18n) {
    var data = i18n.perfiles || {};
    var all = (data.puntos && data.puntos[pt.id]) || {};
    var v = all[perfil] || {};
    var general = all.general || {};
    var desc = (i18n.descripciones || {})[pt.id] || general.significa || '';
    var enfoque = ((i18n.enfoque || {})[pt.id] || [])[1] || '';
    var hacerGeneral = pt.recomendacion || general.hacer || (i18n.recomendaciones || {})[pt.id] || '';
    var out;
    if (perfil === 'desarrollo') {
      out = {
        significa: desc,
        importa: v.importa || enfoque,
        hacer: hacerGeneral,
        comprobar: v.comprobar || ''
      };
    } else {
      out = {
        significa: v.significa || desc,
        importa: v.importa || enfoque,
        hacer: v.hacer || hacerGeneral,
        comprobar: ''
      };
    }
    out.pendiente = perfil === 'desarrollo' ? !(v.importa && v.comprobar) : !(v.significa && v.importa && v.hacer);
    out.tecnico = hacerGeneral;   // recomendación técnica, para el detalle desplegable de marketing
    if (pt.respaldo && data.respaldo && data.respaldo[pt.respaldo]) {
      out.respaldo = data.respaldo[pt.respaldo][perfil] || '';
      out.respaldo_clave = pt.respaldo;
    }
    return out;
  }

  /** Nivel de detalle del perfil. */
  function detailLevel(perfil) {
    return {
      ocultarBajos: perfil === 'direccion',          // los de gravedad baja, plegados (nunca un crítico)
      plegarTecnico: perfil === 'marketing',        // detalles de implementación, desplegables
      glosario: perfil !== 'direccion',             // glosario disponible (en PDF) salvo en dirección
      tecnico: perfil === 'desarrollo'              // valor encontrado, esperado y cómo comprobarlo
    };
  }

  /** Puntos de un bloque agrupados por estado y ordenados según el perfil. */
  function groupBlock(m, bloqueId, perfil, i18n) {
    var pts = m.puntos.filter(function (x) { return x.bloque === bloqueId; });
    var by = function (st) { return sortPoints(pts.filter(function (x) { return x.estado === st; }), perfil, i18n); };
    return {
      fallos: by('NO_CUMPLE'),
      avisos: by('AVISO'),
      cumple: by('CUMPLE'),
      sinDatos: sortPoints(pts.filter(function (x) { return x.estado === 'SIN_DATOS'; }), perfil, i18n),
      noAplica: by('NA')
    };
  }

  root.RadarPerfil = {
    PERFILES: PERFILES,
    AREAS: AREAS,
    BLOQUES: BLOQUES,
    WEB_IDS: WEB_IDS,
    normalize: normalize,
    relevance: relevance,
    range: range,
    model: model,
    sortPoints: sortPoints,
    pointText: pointText,
    detailLevel: detailLevel,
    groupBlock: groupBlock,
    plural: plural,
    joinList: joinList,
    num: num,
    weight: weight
  };
})(typeof window !== 'undefined' ? window : this);
