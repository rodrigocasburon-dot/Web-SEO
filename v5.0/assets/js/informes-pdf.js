/*
 * Radar web · contenido de los dos PDF (informe y hoja de ruta) por perfil de lectura. Usa el mismo modelo y el
 * mismo resumen ejecutivo que la pantalla (assets/js/perfiles.js), así que nunca se contradicen. Solo compone:
 * la maquetación (portada, índice en dos pasadas, enlaces, marcadores, tablas y pie) está en assets/js/pdf.js.
 *
 * Sin nota global (decisión del grupo). El perfil no cambia notas ni estados; un fallo crítico nunca se oculta.
 */
(function (root) {
  'use strict';

  var RP = root.RadarPerfil;
  var BLOCKS = [['seo', 'SEO'], ['ia', 'GEO'], ['accesibilidad', 'UX y accesibilidad'], ['web', 'Toda la web']];
  var PLAZOS = [
    ['corto', 'Corto plazo', 'Fallos críticos y fallos de impacto alto con esfuerzo bajo.'],
    ['medio', 'Medio plazo', 'El resto de fallos de impacto alto y los de esfuerzo bajo.'],
    ['largo', 'Largo plazo', 'Fallos de impacto medio o bajo con esfuerzo medio o alto, y los avisos (no restan).']
  ];

  function colors() { return root.RadarPdf.colors; }

  function fmtDate(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    return isNaN(d) ? String(iso) : d.toLocaleString('es-ES', { timeZone: 'Europe/Madrid', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });   // mismo formato que el resto: 11/11/2026, 10:29
  }

  function lower(map, k) { return k ? String((map || {})[k] || k).toLowerCase() : ''; }

  function stateText(i18n, st) {
    if (st === 'NA') return 'No aplica';
    return (i18n.estados || {})[st] || st;
  }

  function label(pt, perfil) {
    return (perfil === 'desarrollo' && pt.id.indexOf('WEB-') !== 0 ? pt.id + ' · ' : '') + pt.nombre;
  }

  function areaOf(i18n, pt) { return (i18n.bloque_checks || {})[pt.id] || pt.bloque; }

  /** Páginas del sitio afectadas por un punto (texto breve) o ''. */
  function siteLine(pt) {
    var s = pt.sitio;
    if (!s || !s.evaluadas) return '';
    if (s.fallan) return 'Falla en ' + s.fallan + ' de ' + RP.plural(s.evaluadas, 'página', 'páginas') + (s.avisos ? ' y tiene avisos en ' + s.avisos : '') + '.';
    if (s.avisos) return 'Aviso en ' + s.avisos + ' de ' + RP.plural(s.evaluadas, 'página', 'páginas') + '.';
    return 'Sin errores detectables automáticamente en ' + RP.plural(s.evaluadas, 'página', 'páginas') + '.';
  }

  // ---------------------------------------------------------------- plazos de la hoja de ruta

  /** Cuadrante como src/Report.php quadrant(): impacto alto / esfuerzo bajo. */
  function quadrant(pt) {
    var hi = String(pt.impacto || '').toUpperCase() === 'ALTO';
    var low = String(pt.esfuerzo || '').toUpperCase() === 'BAJO';
    return hi ? (low ? 'inmediata' : 'planificar') : (low ? 'rapidas' : 'valorar');
  }

  /** Plazo de un punto: corto (críticos e «inmediata»), medio («planificar» y «rapidas»), largo (resto y avisos). */
  function plazo(pt) {
    if (pt.estado !== 'NO_CUMPLE') return 'largo';
    if (pt.severidad === 'CRITICO') return 'corto';
    if (!pt.impacto && !pt.esfuerzo) return 'medio';       // puntos de todo el sitio sin cuadrante
    var q = quadrant(pt);
    return q === 'inmediata' ? 'corto' : (q === 'valorar' ? 'largo' : 'medio');
  }

  // ---------------------------------------------------------------- piezas comunes

  /** Punto con los textos del perfil, en datos para doc.item(). */
  function itemOf(pt, perfil, i18n, opts) {
    opts = opts || {};
    var t = RP.pointText(pt, perfil, i18n);
    var line = [stateText(i18n, pt.estado)];
    if (pt.severidad) line.push('gravedad ' + lower(i18n.severidades, pt.severidad));
    if (opts.bloque) line.push((i18n.areas || {})[areaOf(i18n, pt)] || (pt.bloque === 'web' ? 'Toda la web' : ''));
    if (perfil === 'direccion' && (pt.esfuerzo || pt.impacto)) line.push('esfuerzo ' + (lower(i18n.niveles, pt.esfuerzo) || '—') + ', impacto ' + (lower(i18n.niveles, pt.impacto) || '—'));
    var textos = [];
    if (!opts.breve) textos.push(['Qué significa', t.significa]);
    textos.push(['Por qué importa', t.importa]);
    if (pt.estado === 'NO_CUMPLE' || pt.estado === 'AVISO') textos.push([perfil === 'direccion' ? 'Decisión' : 'Qué hacer', t.hacer, colors().rojo]);
    if (!opts.breve) {
      textos.push([pt.id.indexOf('WEB-') === 0 ? 'Encontrado en el sitio' : 'Encontrado en la URL', pt.detalle]);
      textos.push(['En el sitio', siteLine(pt)]);
    }
    if (perfil === 'desarrollo') {
      if (!opts.breve) {
        textos.push(['Valor encontrado', pt.encontrado]);
        textos.push(['Valor esperado', pt.esperado]);
      }
      textos.push(['Cómo comprobarlo', t.comprobar]);
    }
    if (perfil === 'marketing' && !opts.breve && t.tecnico && t.tecnico !== t.hacer) textos.push(['Detalle para el equipo web', t.tecnico]);
    if (t.respaldo) textos.push(['Respaldo', String(t.respaldo).replace(/^Respaldo:\s*/, '')]);
    return { titulo: label(pt, perfil), estado: pt.estado, linea: line.filter(Boolean).join(' · '), textos: textos, ancla: opts.ancla === false ? null : 'punto-' + pt.id };
  }

  /** Resumen ejecutivo (RadarPerfil.summary) en una página: abre los dos PDF. */
  function summaryPdf(doc, s) {
    var C = colors();
    var startPage = doc.page();
    doc.section('resumen', 'Resumen ejecutivo');
    doc.p(s.url + ' · ' + fmtDate(s.fecha) + ' · Lectura: ' + s.perfilNombre, { size: 8.5, color: C.gris });
    doc.p(s.titular, { size: 12, bold: true, color: C.negro, gap: 6 });
    if (s.bloqueado) {
      s.fallosBloque.forEach(function (t) { doc.note(t); });
    } else {
      doc.scores(s.notas.map(function (n) {
        return {
          etiqueta: n.nombre, nota: n.nota,
          rango: (n.rango ? n.rango.texto : 'Sin datos suficientes') + (n.incompleta ? ' · incompleta' : ''),
          detalle: n.variacion ? 'Frente al análisis anterior: ' + n.variacion : ''
        };
      }));
      if (s.variacion) doc.p(s.variacion, { size: 9 });
      if (s.riesgo) doc.note('Riesgo alto: revisar de inmediato.' + (s.riesgoMotivos.length ? ' ' + s.riesgoMotivos.join('. ') + '.' : ''));
      s.fallosBloque.forEach(function (t) { doc.note(t); });
      doc.h3('Prioridades');
      s.prioridades.forEach(function (p, i) {
        var meta = [];
        if (p.severidad) meta.push('gravedad ' + ({ CRITICO: 'crítica', ALTO: 'alta', MEDIO: 'media', BAJO: 'baja' })[p.severidad]);
        if (p.paginas) meta.push(p.paginas);
        if (p.esfuerzo || p.impacto) meta.push('esfuerzo ' + (p.esfuerzo || '—') + ', impacto ' + (p.impacto || '—'));
        doc.p((i + 1) + '. ' + p.nombre + (meta.length ? ' (' + meta.join('; ') + ')' : ''), { size: 9.5, bold: true, color: C.negro, gap: 1, link: 'punto-' + p.id });
        if (p.texto) doc.p(p.texto, { size: 9, indent: 13, gap: 4 });
      });
      if (s.notaPrioridades) doc.p(s.notaPrioridades, { size: 9 });
      if (s.bien.length) {
        doc.h3('Lo que está bien');
        s.bien.forEach(function (b) { doc.p('• ' + b.nombre, { size: 9, gap: 1 }); });
        doc.space(4);
      }
      if (s.extra) doc.p(s.extra.titulo + ': ' + s.extra.texto, { size: 9 });
    }
    doc.p('Cobertura: ' + s.cobertura.texto, { size: 9 });
    if (s.cobertura.aviso) doc.note(s.cobertura.aviso);
    doc.p(s.alcance, { size: 8, color: C.gris });
    doc.resumenPaginas = doc.page() - startPage + 1;
  }

  function options(kind, m, s, extraLines) {
    var titles = { informe: 'Radar web · Informe de análisis', hoja: 'Radar web · Hoja de ruta' };
    var sub = ['Fecha del análisis: ' + fmtDate(m.fecha) + '.'];
    if (m.sitio && m.sitio.meta && m.sitio.meta.length) sub = sub.concat(m.sitio.meta);
    else if (!m.bloqueado) sub.push('El análisis del sitio no había terminado al descargar este documento: lo que se indica del sitio puede estar incompleto.');
    return { eyebrow: titles[kind], cabecera: titles[kind], titulo: m.url, subtitulo: sub.concat(extraLines || []), lectura: s.perfilNombre };
  }

  function methodParagraphs(doc, i18n) {
    var pen = i18n.penalizaciones || {};
    doc.p('Cada área (SEO, GEO y UX) parte de 100. Solo restan los fallos («No cumple»), según su gravedad: crítica ' + pen.CRITICO
      + ', alta ' + pen.ALTO + ', media ' + pen.MEDIO + ' y baja ' + pen.BAJO + ' puntos, con un peso algo mayor si el mismo fallo se repite (1 + ln k). Después, la resta de cada área se ajusta con un factor para que las tres tengan la misma escala (normalización). '
      + 'Los avisos, los puntos sin datos y los que no aplican no restan. Un fallo resta una sola vez y se reparte entre las áreas a las que afecta.');
    doc.p('Las notas son las de la URL analizada; la media del sitio es un dato secundario. No se muestra una nota global: cada área se lee por separado.');
    doc.p('El perfil de lectura solo cambia el lenguaje, el orden a igualdad de gravedad y el nivel de detalle. Las notas, los estados y los pesos son los mismos en los tres perfiles.');
    doc.p('Rangos de nota: 0–49 Crítico, 50–74 Mejorable, 75–89 Bueno y 90–100 Muy bueno.');
    doc.p('GEO mide la preparación técnica para buscadores y asistentes con IA. Varias de sus señales son experimentales o hipótesis y así se indica en cada punto; ninguna garantiza que la página aparezca citada.');
    doc.p('Evaluación automática y parcial: no sustituye una auditoría manual y ningún resultado significa que la web cumpla una norma o la ley.');
  }

  function manualReview(doc, i18n) {
    var rm = i18n.revisiones_manuales || {};
    var ids = Object.keys(rm);
    if (!ids.length) return;
    doc.h3('Qué debe revisar una persona (no puntúa)');
    doc.table([{ titulo: 'ID', ancho: 0.7 }, { titulo: 'Revisión', ancho: 2.2 }, { titulo: 'Qué comprobar', ancho: 5 }],
      ids.map(function (id) { return [id, rm[id][0], rm[id][1]]; }));
  }

  // ---------------------------------------------------------------- informe

  function report(doc, m, perfil, i18n) {
    var s = RP.summary(m, perfil, i18n);
    var lvl = RP.detailLevel(perfil);
    var C = colors();
    summaryPdf(doc, s);

    // Las tres notas
    doc.section('notas', 'Las tres notas', { nuevaPagina: true });
    if (m.bloqueado) {
      doc.note('Acceso bloqueado: no se ha podido calcular ninguna nota. ' + (m.mensajeBloqueo || ''));
    } else {
      doc.scores(s.notas.map(function (n) {
        var pts = m.puntos.filter(function (p) { return p.conNota && areaOf(i18n, p) === n.area; });
        var ev = pts.filter(function (p) { return ['CUMPLE', 'AVISO', 'NO_CUMPLE'].indexOf(p.estado) !== -1; }).length;
        var nd = pts.filter(function (p) { return p.estado === 'SIN_DATOS'; }).length;
        return { etiqueta: n.nombre, nota: n.nota, rango: (n.rango ? n.rango.texto : 'Sin datos suficientes') + (n.incompleta ? ' · incompleta' : ''),
          detalle: ev + ' evaluados · ' + nd + ' sin datos' + (n.variacion ? ' · ' + n.variacion : '') };
      }));
      doc.p('Notas de la URL analizada. Solo restan los fallos; los avisos y lo que no se ha podido medir no restan.', { size: 8.5, color: C.gris });
      if (m.sitio && m.sitio.media) {
        doc.p('Media del sitio (' + RP.plural(m.sitio.analizadas, 'página con nota', 'páginas con nota') + '): '
          + ['seo', 'ia', 'accesibilidad'].map(function (a) { return RP.areaName(i18n, a) + ' ' + RP.num(m.sitio.media[a]) + ' (peor página: ' + RP.num(m.sitio.minima[a]) + ')'; }).join(' · ') + '.', { size: 9 });
      }
      if (m.evolucion.length) {
        var rows = m.evolucion.map(function (r) { return [fmtDate(r.fecha), RP.num(r.seo), RP.num(r.ia), RP.num(r.accesibilidad)]; });
        rows.push([{ texto: 'Este análisis', bold: true }, RP.num(m.notas.seo), RP.num(m.notas.ia), RP.num(m.notas.accesibilidad)]);
        doc.table([{ titulo: 'Análisis', ancho: 3 }, { titulo: 'SEO', ancho: 1, alinear: 'right' }, { titulo: 'GEO', ancho: 1, alinear: 'right' }, { titulo: 'UX', ancho: 1, alinear: 'right' }],
          rows, { caption: 'Evolución de esta URL (historial)' });
      }
      if (m.riesgo) doc.note('Riesgo alto: revisar de inmediato. ' + (m.riesgoMotivos || []).join('. ') + '.');
    }

    // Bloques
    BLOCKS.forEach(function (b, idx) {
      var g = RP.groupBlock(m, b[0], perfil, i18n);
      var firstPt = g.fallos.concat(g.avisos).filter(function (pt) { return !(lvl.ocultarBajos && pt.severidad === 'BAJO'); })[0];
      doc.section('bloque-' + b[0], b[1], { nuevaPagina: idx === 0, reservar: firstPt ? doc.measureItem(itemOf(firstPt, perfil, i18n)) : 60 });
      if (b[0] === 'web' && !m.sitioTerminado && !g.fallos.length && !g.avisos.length && !g.cumple.length) {
        doc.p('Enlaces rotos, sitemap, títulos repetidos y página de error se evalúan al terminar el recorrido del sitio, que no había terminado.');
        return;
      }
      var counts = [RP.plural(g.fallos.length, 'fallo', 'fallos'), RP.plural(g.avisos.length, 'aviso', 'avisos'),
        RP.plural(g.cumple.length, 'punto sin errores detectables', 'puntos sin errores detectables')];
      doc.p(counts.join(' · ') + '.', { size: 9, color: C.gris });
      var compact = [];
      g.fallos.concat(g.avisos).forEach(function (pt) {
        // Dirección: los de gravedad baja, en una tabla breve (nunca un crítico ni un alto)
        if (lvl.ocultarBajos && pt.severidad === 'BAJO') { compact.push(pt); return; }
        doc.item(itemOf(pt, perfil, i18n));
      });
      if (compact.length) {
        doc.anchor('bajos-' + b[0]);
        compact.forEach(function (pt) { doc.anchors['punto-' + pt.id] = doc.anchors['bajos-' + b[0]]; });
        doc.table([{ titulo: 'Punto', ancho: 2.5 }, { titulo: 'Estado', ancho: 1.1 }, { titulo: 'Decisión', ancho: 4 }],
          compact.map(function (pt) { return [label(pt, perfil), stateText(i18n, pt.estado), RP.pointText(pt, perfil, i18n).hacer]; }),
          { caption: 'Gravedad baja' });
      }
      if (g.cumple.length) {
        doc.table([{ titulo: 'Sin errores detectables automáticamente', ancho: 5 }, { titulo: 'Gravedad', ancho: 1.2 }],
          g.cumple.map(function (pt) { return [label(pt, perfil), pt.severidad ? (i18n.severidades || {})[pt.severidad] || pt.severidad : '—']; }));
      }
    });

    // No evaluados
    doc.section('no-evaluados', 'Puntos no evaluados', { nuevaPagina: true });
    var ne = m.puntos.filter(function (p) { return p.pendiente || p.estado === 'SIN_DATOS' || p.estado === 'NA'; });
    if (!ne.length) {
      doc.p('Se han podido evaluar todos los puntos que aplican a esta URL.');
    } else {
      doc.p('No restan ni suman: la nota se calcula solo con lo evaluado.', { size: 9, color: C.gris });
      doc.table([{ titulo: 'Punto', ancho: 3 }, { titulo: 'Bloque', ancho: 1.2 }, { titulo: 'Motivo', ancho: 2.6 }],
        RP.sortPoints(ne, perfil, i18n).map(function (p) {
          var why = p.pendiente ? 'Pendiente de PageSpeed Insights' : (p.estado === 'NA' ? 'No aplica a esta página' : 'Sin datos: no se ha podido medir');
          return [label(p, perfil), p.bloque === 'web' ? 'Toda la web' : RP.areaName(i18n, p.bloque), why];
        }));
    }

    // Glosario (no en dirección)
    if (lvl.glosario) {
      var gl = (i18n.perfiles && i18n.perfiles.glosario) || [];
      if (gl.length) {
        doc.section('glosario', 'Glosario');
        doc.table([{ titulo: 'Término', ancho: 1.6 }, { titulo: 'Qué significa', ancho: 5 }], gl.map(function (r) { return [{ texto: r[0], bold: true }, r[1]]; }));
      }
    }

    doc.section('metodo', 'Notas metodológicas');
    methodParagraphs(doc, i18n);
    manualReview(doc, i18n);
    return s;
  }

  // ---------------------------------------------------------------- hoja de ruta

  function roadmapGroups(m, perfil, i18n) {
    var pts = m.puntos.filter(function (p) { return p.estado === 'NO_CUMPLE' || p.estado === 'AVISO'; });
    var out = { corto: [], medio: [], largo: [] };
    pts.forEach(function (p) { out[plazo(p)].push(p); });
    Object.keys(out).forEach(function (k) {
      var f = RP.sortPoints(out[k].filter(function (p) { return p.estado === 'NO_CUMPLE'; }), perfil, i18n);
      var a = RP.sortPoints(out[k].filter(function (p) { return p.estado === 'AVISO'; }), perfil, i18n);
      out[k] = f.concat(a);
    });
    return out;
  }

  function roadmap(doc, m, perfil, i18n) {
    var s = RP.summary(m, perfil, i18n);
    var C = colors();
    summaryPdf(doc, s);
    var groups = roadmapGroups(m, perfil, i18n);

    doc.section('plazos', 'Prioridades por plazo', { nuevaPagina: true });
    doc.p('Dentro de cada plazo, primero los fallos por gravedad (lo que más resta) y, a igualdad, lo más relevante para el perfil; después los avisos.', { size: 9, color: C.gris });
    var n = 0;
    PLAZOS.forEach(function (pl) {
      var fp = groups[pl[0]][0];
      doc.section('plazo-' + pl[0], pl[1] + ' (' + groups[pl[0]].length + ')', { nivel: 2, reservar: fp ? 20 + doc.measureItem(itemOf(fp, perfil, i18n, { breve: true, bloque: true })) : 40 });
      doc.p(pl[2], { size: 8.5, color: C.gris });
      if (!groups[pl[0]].length) { doc.p('Nada en este plazo.'); return; }
      groups[pl[0]].forEach(function (pt) {
        n++;
        var it = itemOf(pt, perfil, i18n, { breve: true, bloque: true });
        it.titulo = n + '. ' + it.titulo;
        doc.item(it);
      });
    });

    doc.section('tareas', 'Tabla de tareas', { nuevaPagina: true });
    var rows = [];
    var k = 0;
    PLAZOS.forEach(function (pl) {
      groups[pl[0]].forEach(function (pt) {
        k++;
        var t = RP.pointText(pt, perfil, i18n);
        rows.push([String(k), { texto: label(pt, perfil) + (pt.estado === 'AVISO' ? ' (aviso, no resta)' : '') + ': ' + RP.firstSentence(t.hacer), link: 'punto-' + pt.id },
          pt.bloque === 'web' ? 'Web' : RP.areaName(i18n, areaOf(i18n, pt)),
          pt.severidad ? (i18n.severidades || {})[pt.severidad] || pt.severidad : (pt.estado === 'AVISO' ? 'Aviso' : '—'),
          pt.esfuerzo ? (i18n.niveles || {})[pt.esfuerzo] || pt.esfuerzo : '—',
          pt.impacto ? (i18n.niveles || {})[pt.impacto] || pt.impacto : '—',
          pl[1].replace(' plazo', '')]);
      });
    });
    if (rows.length) {
      doc.table([{ titulo: 'Nº', ancho: 0.45, alinear: 'right' }, { titulo: 'Tarea', ancho: 4.8 }, { titulo: 'Bloque', ancho: 0.9 }, { titulo: 'Gravedad', ancho: 1.2 },
        { titulo: 'Esfuerzo', ancho: 0.95 }, { titulo: 'Impacto', ancho: 0.95 }, { titulo: 'Plazo', ancho: 0.8 }], rows, { size: 8 });
    } else {
      doc.p('Nada que corregir: no se han detectado fallos ni avisos de forma automática.');
    }

    doc.section('riesgos', 'Riesgos');
    if (m.bloqueado) doc.note('Acceso bloqueado: no se ha podido evaluar la página. ' + (m.mensajeBloqueo || ''));
    if (m.riesgo) doc.note('Riesgo alto: revisar de inmediato. ' + (m.riesgoMotivos || []).join('. ') + '.');
    var graves = RP.sortPoints(m.puntos.filter(function (p) { return p.estado === 'NO_CUMPLE' && (p.severidad === 'CRITICO' || p.severidad === 'ALTO'); }), perfil, i18n);
    if (graves.length) {
      doc.p('Qué puede pasar si no se corrigen los fallos críticos y altos:', { size: 9.5, bold: true, color: C.negro });
      graves.forEach(function (pt) {
        doc.p('• ' + label(pt, perfil) + ': ' + RP.pointText(pt, perfil, i18n).importa, { size: 9, gap: 2, link: 'punto-' + pt.id });
      });
    } else if (!m.bloqueado) {
      doc.p('No hay fallos críticos ni altos detectables automáticamente.');
    }
    s.fallosBloque.forEach(function (t) { if (!m.bloqueado) doc.note(t); });
    if (s.cobertura.aviso) doc.note(s.cobertura.aviso);
    doc.p('Riesgo residual: hay aspectos que la herramienta no puede medir (calidad del texto alternativo, orden real del teclado, contraste sobre imágenes y calidad de los subtítulos). Se listan en «Método».', { size: 9 });

    doc.section('metodo', 'Método');
    doc.p('Plazos: corto plazo, los fallos críticos y los de impacto alto con esfuerzo bajo; medio plazo, el resto de fallos de impacto alto y los de esfuerzo bajo; largo plazo, los fallos de impacto medio o bajo con esfuerzo medio o alto, y todos los avisos. '
      + 'El impacto y el esfuerzo son estimaciones de cada comprobación, no mediciones.');
    methodParagraphs(doc, i18n);
    manualReview(doc, i18n);
    // Destinos de los enlaces de la tabla de tareas y del resumen: el punto dentro de su plazo
    return s;
  }

  // ---------------------------------------------------------------- descarga

  /** Compone y descarga un PDF ('informe' | 'hoja') con el modelo actual. Devuelve una promesa. */
  function download(kind, m, perfil, i18n) {
    var s = RP.summary(m, perfil, i18n);
    return root.RadarPdf.load().then(function (assets) {
      var doc = root.RadarPdf.build(options(kind, m, s), function (d) {
        if (kind === 'hoja') roadmap(d, m, perfil, i18n); else report(d, m, perfil, i18n);
      }, assets);
      doc.save(root.RadarPdf.fileName(kind === 'hoja' ? 'hoja-de-ruta' : 'informe', m.url).replace('.pdf', '-' + s.perfil + '.pdf'));
      return doc;
    });
  }

  root.RadarInformesPdf = {
    report: report,
    roadmap: roadmap,
    summary: summaryPdf,
    options: options,
    plazo: plazo,
    roadmapGroups: roadmapGroups,
    download: download
  };
})(window);
