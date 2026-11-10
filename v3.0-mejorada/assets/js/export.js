/* Exportación del informe a CSV y JSON (función 08). Se genera en el navegador; no se guarda nada en el servidor.
 * CSV para Excel en español: separador «;», UTF-8 con BOM, CRLF y todas las celdas entre comillas.
 * Protección frente a inyección de fórmulas: si una celda empieza por =, +, -, @, tabulador o retorno de carro,
 * se antepone un apóstrofo para que la hoja de cálculo la trate como texto. Sin librerías. */
(function (global) {
  'use strict';

  var BOM = '﻿';
  var SEP = ';';
  var EOL = '\r\n';
  var COLUMNS = ['id', 'comprobación', 'área', 'estado', 'detalle', 'severidad', 'valor medido', 'umbral', 'criterio', 'evidencia', 'recomendación', 'impacto', 'esfuerzo'];

  /** Celda CSV: neutraliza fórmulas y escapa comillas. */
  function csvCell(v) {
    var s = v === null || v === undefined ? '' : String(v);
    if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
    return '"' + s.replace(/"/g, '""') + '"';
  }

  /** Texto CSV (con BOM) a partir de filas (arrays de valores); la primera fila es la cabecera. */
  function toCsv(rows) {
    return BOM + rows.map(function (r) { return r.map(csvCell).join(SEP); }).join(EOL) + EOL;
  }

  /** Número con coma decimal y sin separador de miles (Excel en español lo lee como número). */
  function num(n) {
    var r = Math.round(n * 100) / 100;
    return String(r).replace('.', ',');
  }

  /** Valor medido (escalar, lista u objeto) como texto de una línea. */
  function valueText(v) {
    if (v === null || v === undefined || v === '') return '';
    if (typeof v === 'boolean') return v ? 'sí' : 'no';
    if (typeof v === 'number') return num(v);
    if (typeof v === 'string') return v;
    if (Array.isArray(v)) {
      var nested = v.some(function (x) { return x !== null && typeof x === 'object'; });
      return v.map(valueText).join(nested ? ' | ' : ', ');
    }
    return Object.keys(v).map(function (k) { return k.replace(/_/g, ' ') + ': ' + valueText(v[k]); }).join('; ');
  }

  function label(map, key) {
    return (map && map[key]) || key || '';
  }

  /** Áreas a las que se reparte la comprobación, con su peso (p. ej. «SEO 70 %; Preparación para IA 30 %»). */
  function areasText(pilares, names) {
    return ['seo', 'ia', 'accesibilidad'].filter(function (a) { return pilares && pilares[a] > 0; }).map(function (a) {
      return label(names, a) + ' ' + Math.round(pilares[a] * 100) + ' %';
    }).join('; ');
  }

  function evidenceText(list) {
    return (list || []).map(function (e) {
      var parts = [];
      if (e.selector) parts.push(e.selector + ':');
      if (e.extracto) parts.push(e.extracto);
      if (e.linea) parts.push('(línea ' + e.linea + ')');
      return parts.join(' ');
    }).join(' | ');
  }

  /** Filas del CSV: cabecera y una fila por comprobación. i18n: areas, estados, severidades, niveles. */
  function buildRows(checks, i18n) {
    i18n = i18n || {};
    var rows = [COLUMNS.slice()];
    (checks || []).forEach(function (c) {
      rows.push([
        c.id,
        c.nombre || '',
        areasText(c.pilares, i18n.areas),
        c.aplica === false ? 'No aplica' : label(i18n.estados, c.estado),
        c.detalle || '',
        label(i18n.severidades, c.severidad),
        valueText(c.valor_medido),
        c.umbral === null || c.umbral === undefined ? '' : valueText(c.umbral),
        c.criterio || '',
        evidenceText(c.evidencia),
        c.recomendacion || '',
        label(i18n.niveles, c.impacto),
        label(i18n.niveles, c.esfuerzo)
      ]);
    });
    return rows;
  }

  /** JSON del análisis completo, con la fecha de exportación y si la puntuación es provisional. */
  function buildJson(data, meta) {
    var out = {
      herramienta: 'Analizador web de Proconsi',
      exportado: (meta && meta.exportado) || new Date().toISOString(),
      puntuacion_provisional: !!(meta && meta.provisional)
    };
    Object.keys(data || {}).forEach(function (k) { out[k] = data[k]; });
    // v3.0: con acceso bloqueado no se ha evaluado el contenido; no se exporta un 100 que parezca un resultado
    if (data && data.blocked) {
      out.puntuacion = { calculable: false, motivo: 'Acceso bloqueado: no se ha evaluado el contenido de la página.' };
    }
    return JSON.stringify(out, null, 2);
  }

  /** Nombre de archivo: analisis-<dominio>-<AAAAMMDD-HHMM>.<ext> (solo caracteres seguros). */
  function fileName(url, date, ext) {
    var host = '';
    try { host = new URL(url).hostname; } catch (e) { host = 'pagina'; }
    host = host.toLowerCase().replace(/[^a-z0-9.\-]/g, '').replace(/^\.+/, '') || 'pagina';
    var d = date || new Date();
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    var stamp = d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes());
    return 'analisis-' + host + '-' + stamp + '.' + ext;
  }

  /** Descarga un texto como archivo mediante un Blob (no pasa por el servidor). */
  function download(name, mime, text) {
    var blob = new Blob([text], { type: mime });
    var href = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = href;
    a.download = name;
    a.hidden = true;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(href); }, 1000);
  }

  global.AnalyzerExport = {
    COLUMNS: COLUMNS,
    csvCell: csvCell,
    toCsv: toCsv,
    valueText: valueText,
    buildRows: buildRows,
    buildJson: buildJson,
    fileName: fileName,
    download: download
  };
})(window);
