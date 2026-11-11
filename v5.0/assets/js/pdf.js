/*
 * Radar web · PDF corporativos que se DESCARGAN (no es el diálogo de imprimir). Sin librerías: escribe el PDF
 * a mano (PDF 1.4, A4). Identidad de Proconsi: fondo blanco, Poppins incrustada (TrueType, WinAnsi; la cargan
 * assets/js/pdf-fuentes.js desde el propio servidor), negro, rojo con moderación y gris; cabecera discreta en
 * gris y logotipo abajo a la izquierda, bajo una línea fina gris. Si Poppins no llega, usa Helvetica.
 *
 * Uso con portada e índice (dos pasadas: la primera mide en qué página cae cada sección; la segunda escribe el
 * índice con esos números):
 *   RadarPdf.load().then(function (assets) {
 *     var doc = RadarPdf.build({ eyebrow, titulo, subtitulo: [líneas], lectura, cabecera }, function (doc) {
 *       doc.section('resumen', 'Resumen'); doc.p('…'); doc.table(cols, rows); doc.item({...});
 *     }, assets);
 *     doc.save('archivo.pdf');
 *   });
 * Uso sencillo (sin portada): RadarPdf.create(opts).then(function (doc) { doc.h2('…'); …; doc.save(…); }).
 *
 * Todo el texto se escribe como texto del PDF (se puede buscar y copiar); nada se interpreta como HTML. Los
 * paréntesis y barras se escapan (esc); los títulos de marcadores van en UTF-16.
 */
(function (root) {
  'use strict';

  var W = 595.28;            // A4 en puntos
  var H = 841.89;
  var M = 50;                // margen lateral
  var TOP = 78;              // donde empieza el contenido bajo la cabecera
  var BOTTOM = 76;           // por encima del pie
  var FOOT_LINE = 60;        // línea fina gris del pie
  var LOGO_W = 90;           // ancho del logotipo en el pie (normas de Proconsi)
  var C = {                  // paleta de Proconsi
    negro: [0, 0, 0], rojo: [0.890, 0.024, 0.075], gris: [0.424, 0.424, 0.424], grisClaro: [0.706, 0.706, 0.706],
    linea: [0.851, 0.851, 0.851], panel: [0.957, 0.953, 0.949], blanco: [1, 1, 1], g4: [0.180, 0.180, 0.180]
  };
  var PIE = 'Radar web · Evaluación automática y parcial: no sustituye una auditoría manual.';

  // Anchos de Helvetica y Helvetica-Bold (AFM de Adobe, milésimas de em) para los caracteres 32–126 (respaldo)
  var WR = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
    278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611,
    722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
    556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
  var WB = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
    333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611,
    722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
    611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];
  // Caracteres de WinAnsi fuera de Latin-1 (0x80–0x9F) y sustitutos de los que no existen en la fuente
  var WIN = { '€': 0x80, '‚': 0x82, '„': 0x84, '…': 0x85, '‘': 0x91, '’': 0x92, '“': 0x93, '”': 0x94, '•': 0x95, '–': 0x96, '—': 0x97, '™': 0x99 };
  var SUB = { '✕': 'x', '✓': 'v', '−': '-', '→': '->', '←': '<-', '≥': '>=', '≤': '<=', '▾': '', '▴': '', ' ': ' ', ' ': ' ', ' ': ' ' };
  var SPECIAL_W = { 0x80: 556, 0x85: 1000, 0x91: 222, 0x92: 222, 0x93: 333, 0x94: 333, 0x95: 350, 0x96: 556, 0x97: 1000, 0x99: 1000,
    0xAB: 556, 0xBB: 556, 0xB7: 278, 0xBA: 365, 0xAA: 370, 0xBF: 611, 0xA1: 333, 0xB0: 400, 0xD7: 584 };
  var WIN_UNI = {};          // código WinAnsi → Unicode (para leer los anchos de Poppins)
  Object.keys(WIN).forEach(function (ch) { WIN_UNI[WIN[ch]] = ch.charCodeAt(0); });

  /** Texto de JavaScript → bytes WinAnsi (cada carácter de la cadena es un byte). */
  function winAnsi(text) {
    var out = '';
    var s = String(text === null || text === undefined ? '' : text);
    for (var i = 0; i < s.length; i++) {
      var ch = s.charAt(i);
      if (SUB[ch] !== undefined) { out += SUB[ch]; continue; }
      var code = s.charCodeAt(i);
      if (code >= 32 && code < 127) out += ch;
      else if (code >= 160 && code < 256) out += ch;
      else if (WIN[ch]) out += String.fromCharCode(WIN[ch]);
      else if (code === 10 || code === 9) out += ' ';
      else {
        var base = ch.normalize ? ch.normalize('NFD').charAt(0) : '';
        out += base && base.charCodeAt(0) < 127 ? base : '?';
      }
    }
    return out;
  }

  function helveticaWidth(b, bold) {
    if (b >= 32 && b < 127) return (bold ? WB : WR)[b - 32];
    if (SPECIAL_W[b]) return SPECIAL_W[b];
    var base = String.fromCharCode(b).normalize ? String.fromCharCode(b).normalize('NFD').charCodeAt(0) : 0;
    if (base >= 32 && base < 127) return (bold ? WB : WR)[base - 32];
    return 556;
  }

  /** Ancho en puntos de un texto ya convertido a WinAnsi (metrics: anchos 0–255 de la fuente o null = Helvetica). */
  function measure(enc, size, bold, metrics) {
    var w = 0;
    var tbl = metrics ? metrics[bold ? 'bold' : 'regular'] : null;
    for (var i = 0; i < enc.length; i++) {
      var b = enc.charCodeAt(i);
      w += tbl ? (tbl[b] || 0) : helveticaWidth(b, bold);
    }
    return w * size / 1000;
  }

  function width(enc, size, bold) { return measure(enc, size, bold, null); }

  /** Parte un texto en líneas que caben en maxW (las palabras muy largas, como las URL, se cortan). */
  function wrapWith(text, size, bold, maxW, metrics) {
    var enc = winAnsi(text);
    var words = enc.split(' ');
    var lines = [];
    var line = '';
    words.forEach(function (word) {
      var tryLine = line ? line + ' ' + word : word;
      if (measure(tryLine, size, bold, metrics) <= maxW) { line = tryLine; return; }
      if (line) lines.push(line);
      line = '';
      while (measure(word, size, bold, metrics) > maxW) {
        var cut = word.length;
        while (cut > 1 && measure(word.slice(0, cut), size, bold, metrics) > maxW) cut--;
        lines.push(word.slice(0, cut));
        word = word.slice(cut);
      }
      line = word;
    });
    if (line || !lines.length) lines.push(line);
    return lines;
  }

  function wrap(text, size, bold, maxW) { return wrapWith(text, size, bold, maxW, null); }

  function esc(enc) { return enc.replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)'); }
  function n(v) { return (Math.round(v * 100) / 100).toString(); }
  function rgb(c) { return n(c[0]) + ' ' + n(c[1]) + ' ' + n(c[2]); }
  function fmt(v) { return v === null || v === undefined ? '—' : (Math.round(v * 10) / 10).toLocaleString('es-ES'); }

  /** Cadena de texto del PDF en UTF-16BE (marcadores y metadatos: admiten cualquier carácter). */
  function utf16(text) {
    var s = String(text || '');
    var hex = 'FEFF';
    for (var i = 0; i < s.length; i++) hex += ('000' + s.charCodeAt(i).toString(16).toUpperCase()).slice(-4);
    return '<' + hex + '>';
  }

  // ---------------------------------------------------------------- TrueType (anchos de Poppins)

  /**
   * Lee de un TTF (cadena binaria) lo necesario para incrustarlo con WinAnsi: anchos de los códigos 0–255 en
   * milésimas de em, caja, ascendente y descendente. Devuelve null si el archivo no es un TrueType válido.
   */
  function parseTtf(bin) {
    try {
      var u16 = function (o) { return (bin.charCodeAt(o) << 8) | bin.charCodeAt(o + 1); };
      var i16 = function (o) { var v = u16(o); return v & 0x8000 ? v - 0x10000 : v; };
      var u32 = function (o) { return ((u16(o) * 65536) + u16(o + 2)) >>> 0; };
      var numTables = u16(4);
      var tables = {};
      for (var t = 0; t < numTables; t++) {
        var rec = 12 + t * 16;
        tables[bin.substr(rec, 4)] = u32(rec + 8);
      }
      if (!tables.cmap || !tables.hmtx || !tables.hhea || !tables.head || !tables.glyf) return null;
      var upem = u16(tables.head + 18);
      var bbox = [i16(tables.head + 36), i16(tables.head + 38), i16(tables.head + 40), i16(tables.head + 42)];
      var ascent = i16(tables.hhea + 4), descent = i16(tables.hhea + 6);
      var numH = u16(tables.hhea + 34);
      // cmap: subtabla Windows Unicode BMP (3,1), formato 4
      var cm = tables.cmap, sub = 0;
      for (var k = 0, nt = u16(cm + 2); k < nt; k++) {
        var e = cm + 4 + k * 8;
        if (u16(e) === 3 && u16(e + 2) === 1) { sub = cm + u32(e + 4); break; }
      }
      if (!sub || u16(sub) !== 4) return null;
      var segX2 = u16(sub + 6);
      var ends = sub + 14, starts = ends + segX2 + 2, deltas = starts + segX2, ranges = deltas + segX2;
      var glyph = function (code) {
        for (var s = 0; s < segX2 / 2; s++) {
          var end = u16(ends + s * 2);
          if (code > end) continue;
          var start = u16(starts + s * 2);
          if (code < start) return 0;
          var ro = u16(ranges + s * 2);
          if (!ro) return (code + i16(deltas + s * 2)) & 0xFFFF;
          var g = u16(ranges + s * 2 + ro + (code - start) * 2);
          return g ? (g + i16(deltas + s * 2)) & 0xFFFF : 0;
        }
        return 0;
      };
      var adv = function (g) { return u16(tables.hmtx + 4 * Math.min(g, numH - 1)); };
      var widths = [];
      for (var c = 0; c < 256; c++) {
        var uni = c >= 128 && c < 160 ? WIN_UNI[c] : c;
        var gid = uni ? glyph(uni) : 0;
        widths.push(c < 32 || !gid ? 0 : Math.round(adv(gid) * 1000 / upem));
      }
      var k1000 = function (v) { return Math.round(v * 1000 / upem); };
      return { widths: widths, bbox: bbox.map(k1000), ascent: k1000(ascent), descent: k1000(descent) };
    } catch (e) { return null; }
  }

  // ---------------------------------------------------------------- logotipo (PNG → JPEG con fondo blanco)
  var logoPromise = null;
  function loadLogo(src) {
    if (logoPromise) return logoPromise;
    logoPromise = new Promise(function (resolve) {
      if (typeof document === 'undefined' || !src) { resolve(null); return; }
      var img = new Image();
      img.onload = function () {
        try {
          var canvas = document.createElement('canvas');
          canvas.width = img.naturalWidth;
          canvas.height = img.naturalHeight;
          var ctx = canvas.getContext('2d');
          ctx.fillStyle = '#ffffff';
          ctx.fillRect(0, 0, canvas.width, canvas.height);
          ctx.drawImage(img, 0, 0);
          var data = canvas.toDataURL('image/jpeg', 0.92);
          resolve({ bytes: atob(data.slice(data.indexOf(',') + 1)), w: canvas.width, h: canvas.height });
        } catch (e) { resolve(null); }
      };
      img.onerror = function () { resolve(null); };
      img.src = src;
    });
    return logoPromise;
  }

  /** Fuentes cargadas (pdf-fuentes.js) → {regular: {..., metrics}, bold: {...}} o null. */
  function prepareFonts(f) {
    if (!f || !f.regular || !f.bold) return null;
    var r = parseTtf(f.regular.raw), b = parseTtf(f.bold.raw);
    if (!r || !b) return null;
    return { regular: { file: f.regular, m: r, name: 'Poppins-Regular' }, bold: { file: f.bold, m: b, name: 'Poppins-SemiBold' } };
  }

  // ---------------------------------------------------------------- documento
  /**
   * opts: {eyebrow, titulo, subtitulo: [líneas], lectura, cabecera, portada: bool}
   * assets: {logo, fonts} (load()); toc: [{key, titulo, pagina}] de la primera pasada (o null).
   */
  function Doc(opts, assets, toc) {
    this.opts = opts || {};
    assets = assets || {};
    this.logo = assets.logo || null;
    this.fonts = assets.fonts || null;
    this.metrics = this.fonts ? { regular: this.fonts.regular.m.widths, bold: this.fonts.bold.m.widths } : null;
    this.toc = toc || null;
    this.pages = [];
    this.annots = [];          // por página: [{rect, key}]
    this.anchors = {};         // key → {page, y}
    this.sections = [];        // [{key, titulo, nivel, page, y}]
    if (this.opts.portada) {
      this.cover();
      this.newPage();
    } else {
      this.newPage(true);
    }
  }

  Doc.prototype.wrap = function (text, size, bold, maxW) { return wrapWith(text, size, bold, maxW, this.metrics); };
  Doc.prototype.width = function (enc, size, bold) { return measure(enc, size, bold, this.metrics); };

  Doc.prototype.newPage = function (first) {
    this.ops = [];
    this.pages.push(this.ops);
    this.annots.push([]);
    this.header();
    this.y = first ? this.titleBlock() : H - TOP;
  };

  Doc.prototype.page = function () { return this.pages.length - 1; };

  Doc.prototype.op = function (s) { this.ops.push(s); };

  Doc.prototype.rect = function (x, y, w, h, color) { this.op(rgb(color) + ' rg ' + n(x) + ' ' + n(y) + ' ' + n(w) + ' ' + n(h) + ' re f'); };

  Doc.prototype.strokeRect = function (x, y, w, h, color, lw) {
    this.op(rgb(color) + ' RG ' + n(lw || 0.75) + ' w ' + n(x) + ' ' + n(y) + ' ' + n(w) + ' ' + n(h) + ' re S');
  };

  Doc.prototype.line = function (x1, y1, x2, y2, color, lw, dash) {
    this.op((dash ? '[1 2] 0 d ' : '') + rgb(color) + ' RG ' + n(lw || 0.75) + ' w ' + n(x1) + ' ' + n(y1) + ' m ' + n(x2) + ' ' + n(y2) + ' l S' + (dash ? ' [] 0 d' : ''));
  };

  /** Texto en una línea (ya en WinAnsi o no); y es la línea base. */
  Doc.prototype.text = function (x, y, str, size, bold, color, encoded) {
    var enc = encoded ? str : winAnsi(str);
    this.op('BT /' + (bold ? 'F2' : 'F1') + ' ' + n(size) + ' Tf ' + rgb(color || C.negro) + ' rg ' + n(x) + ' ' + n(y) + ' Td (' + esc(enc) + ') Tj ET');
  };

  /** Cabecera discreta en gris (no en la portada): nombre del documento y URL. */
  Doc.prototype.header = function () {
    if (this.opts.portada && this.pages.length === 1) return;
    var left = winAnsi(this.opts.cabecera || this.opts.eyebrow || 'Radar web');
    this.text(M, H - 40, left, 7.5, false, C.gris, true);
    var url = this.opts.titulo ? this.wrap(this.opts.titulo, 7.5, false, W - 2 * M - this.width(left, 7.5, false) - 30)[0] : '';
    if (url) this.text(W - M - this.width(url, 7.5, false), H - 40, url, 7.5, false, C.gris, true);
    this.line(M, H - 48, W - M, H - 48, C.linea, 0.5);
  };

  /** Título en la primera página de un documento sin portada (fondo blanco). Devuelve la «y» siguiente. */
  Doc.prototype.titleBlock = function () {
    var o = this.opts;
    var y = H - TOP - 8;
    this.rect(M, y + 2.5, 22, 2, C.rojo);
    this.text(M + 30, y, (o.eyebrow || '').toUpperCase(), 8, true, C.rojo);
    y -= 30;
    var self = this;
    this.wrap(o.titulo || '', 20, true, W - 2 * M).forEach(function (l) { self.text(M, y, l, 20, true, C.negro, true); y -= 25; });
    (o.subtitulo || []).forEach(function (s) {
      self.wrap(s, 9.5, false, W - 2 * M).forEach(function (l) { self.text(M, y + 6, l, 9.5, false, C.gris, true); y -= 14; });
    });
    return y - 14;
  };

  /** Portada blanca: título, datos, «Lectura: X» y el índice (con números de la primera pasada). */
  Doc.prototype.cover = function () {
    this.ops = [];
    this.pages.push(this.ops);
    this.annots.push([]);
    var o = this.opts;
    var self = this;
    var y = H - 110;
    this.rect(M, y + 2.5, 22, 2, C.rojo);
    this.text(M + 30, y, (o.eyebrow || '').toUpperCase(), 9, true, C.rojo);
    y -= 40;
    this.wrap(o.titulo || '', 24, true, W - 2 * M).forEach(function (l) { self.text(M, y, l, 24, true, C.negro, true); y -= 30; });
    y -= 4;
    (o.subtitulo || []).forEach(function (s) {
      self.wrap(s, 10, false, W - 2 * M).forEach(function (l) { self.text(M, y, l, 10, false, C.gris, true); y -= 15; });
    });
    if (o.lectura) {
      y -= 8;
      this.text(M, y, 'Lectura: ' + o.lectura, 11, true, C.negro);
      y -= 16;
    }
    y -= 34;
    this.text(M, y, 'Contenido', 15, true, C.negro);
    y -= 10;
    this.rect(M, y, 26, 2.5, C.rojo);
    y -= 12;
    (this.toc || []).forEach(function (e, i) {
      y -= 20;
      var label = winAnsi((i + 1) + '. ' + e.titulo);
      var num = winAnsi(String(e.pagina));
      self.text(M, y, label, 10.5, false, C.negro, true);
      var lw = self.width(label, 10.5, false), nw = self.width(num, 10.5, true);
      self.line(M + lw + 6, y + 1, W - M - nw - 6, y + 1, C.grisClaro, 0.6, true);
      self.text(W - M - nw, y, num, 10.5, true, C.negro, true);
      self.annots[0].push({ rect: [M, y - 5, W - M, y + 13], key: e.key });
    });
    this.y = y;
  };

  /** Asegura sitio para h puntos; si no cabe, página nueva. */
  Doc.prototype.need = function (h) {
    if (this.y - h < BOTTOM) this.newPage(false);
  };

  Doc.prototype.space = function (h) { this.y -= h; };

  /** Ancla de destino para enlaces internos (en la posición actual). */
  Doc.prototype.anchor = function (key) {
    this.anchors[key] = { page: this.page(), y: Math.min(H - 52, this.y + 16) };
  };

  /** Enlace interno: rectángulo [x1, y1, x2, y2] de la página actual hacia el ancla key. */
  Doc.prototype.link = function (rect, key) { this.annots[this.page()].push({ rect: rect, key: key }); };

  /**
   * Sección con título (nivel 1 = h2 en el índice; nivel 2 = h3, solo en los marcadores). opts.nuevaPagina
   * empieza en página nueva. Se registra para el índice, los marcadores y los enlaces.
   */
  Doc.prototype.section = function (key, title, opts) {
    opts = opts || {};
    var nivel = opts.nivel || 1;
    if (opts.nuevaPagina && this.y < H - TOP - 1) this.newPage(false);
    // reservar: alto del primer contenido (p. ej., el primer punto), para que el título no quede solo al pie
    if (opts.reservar) this.need(Math.min((nivel === 1 ? 90 : 50) + opts.reservar, H - TOP - BOTTOM - 10));
    if (nivel === 1) this.h2(title); else this.h3(title);
    var y = this.y + (nivel === 1 ? 50 : 26);
    this.anchors[key] = { page: this.page(), y: Math.min(H - 52, y) };
    this.sections.push({ key: key, titulo: title, nivel: nivel, page: this.page() });
  };

  /** Título de sección con barra roja corta debajo. */
  Doc.prototype.h2 = function (text) {
    var lines = this.wrap(text, 15, true, W - 2 * M);
    this.need(24 + lines.length * 19 + 160);   // título + el principio de su contenido: nunca huérfano al pie
    this.y -= 10;
    var self = this;
    lines.forEach(function (l) { self.y -= 19; self.text(M, self.y, l, 15, true, C.negro, true); });
    this.y -= 9;
    this.rect(M, this.y, 26, 2.5, C.rojo);
    this.y -= 14;
  };

  Doc.prototype.h3 = function (text, color) {
    var lines = this.wrap(text, 11, true, W - 2 * M);
    this.need(lines.length * 15 + 48);
    this.y -= 6;
    var self = this;
    lines.forEach(function (l) { self.y -= 15; self.text(M, self.y, l, 11, true, color || C.negro, true); });
    this.y -= 4;
  };

  /** Párrafo con salto de línea automático. opts: {size, bold, color, indent, gap, link: clave} */
  Doc.prototype.p = function (text, opts) {
    opts = opts || {};
    var size = opts.size || 9.5;
    var lead = size * 1.45;
    var x = M + (opts.indent || 0);
    var lines = this.wrap(text, size, !!opts.bold, W - M - x);
    var self = this;
    lines.forEach(function (l) {
      self.need(lead);
      self.y -= lead;
      self.text(x, self.y + 2, l, size, !!opts.bold, opts.color || C.g4, true);
      if (opts.link) self.link([x, self.y - 1, x + self.width(l, size, !!opts.bold), self.y + size + 1], opts.link);
    });
    this.y -= opts.gap === undefined ? 4 : opts.gap;
  };

  /** Alto que ocupará un párrafo (para no partir bloques pequeños). */
  Doc.prototype.measureP = function (text, opts) {
    opts = opts || {};
    var size = opts.size || 9.5;
    return this.wrap(text, size, !!opts.bold, W - M - (M + (opts.indent || 0))).length * size * 1.45 + (opts.gap === undefined ? 4 : opts.gap);
  };

  /** Lista de datos «etiqueta: valor» en dos columnas. */
  Doc.prototype.facts = function (rows) {
    var self = this;
    rows.forEach(function (r) {
      var lines = self.wrap(r[1], 9.5, false, W - 2 * M - 120);
      self.need(lines.length * 13.5 + 4);
      self.text(M, self.y - 11.5, r[0], 8.5, true, C.gris);
      lines.forEach(function (l) { self.y -= 13.5; self.text(M + 120, self.y + 2, l, 9.5, false, C.negro, true); });
      self.y -= 4;
    });
  };

  /** Fila de tarjetas de nota: [{etiqueta, nota (0–100 o null), rango, detalle, detalle2}] */
  Doc.prototype.scores = function (cards) {
    var gap = 12;
    var cw = (W - 2 * M - gap * (cards.length - 1)) / cards.length;
    var ch = 104;
    this.need(ch + 12);
    var top = this.y - 4;
    var self = this;
    cards.forEach(function (c) {
      var x = M + cards.indexOf(c) * (cw + gap);
      self.strokeRect(x, top - ch, cw, ch, C.linea, 0.75);
      self.rect(x, top - 2.5, cw, 2.5, C.negro);
      self.text(x + 12, top - 20, String(c.etiqueta || '').toUpperCase(), 8, true, C.negro);
      var v = winAnsi(fmt(c.nota));
      self.text(x + 12, top - 50, v, 26, true, C.negro, true);
      self.text(x + 14 + self.width(v, 26, true), top - 50, '/100', 9, false, C.gris);
      var barW = cw - 24;
      self.rect(x + 12, top - 62, barW, 4, C.linea);
      if (typeof c.nota === 'number') self.rect(x + 12, top - 62, barW * Math.max(0, Math.min(100, c.nota)) / 100, 4, C.negro);
      if (c.rango) self.text(x + 12, top - 76, c.rango, 8.5, true, C.negro);
      var dl = self.wrap(c.detalle || '', 7.5, false, cw - 24).slice(0, 2);
      dl.forEach(function (l, k) { self.text(x + 12, top - 88 - k * 9.5, l, 7.5, false, C.gris, true); });
    });
    this.y = top - ch - 14;
  };

  /** Alto de un punto completo (limitado a una página: si es más largo, se parte). */
  Doc.prototype.measureItem = function (d) {
    var self = this;
    var first = this.wrap(d.titulo, 10, true, W - M - (M + 16)).length * 14 + 12;
    var full = first + (d.linea ? this.measureP(d.linea, { size: 8, indent: 16, gap: 1 }) : 0) + (d.texto ? this.measureP(d.texto, { size: 9, indent: 16, gap: 1 }) : 0)
      + (d.textos || []).reduce(function (a, t) { return a + (t && t[1] ? 14 + self.measureP(t[1], { size: 9, indent: 16, gap: 1 }) : 0); }, 0)
      + (d.arreglo ? 14 + this.measureP(d.arreglo, { size: 9, indent: 16, gap: 1 }) : 0) + 14;
    return Math.min(full, H - TOP - BOTTOM - 20);
  };

  /** Punto de una lista: {titulo, linea, texto, arreglo, estado: NO_CUMPLE | AVISO, ancla, textos: [[etiqueta, texto]]} */
  Doc.prototype.item = function (d) {
    var x = M + 16;
    var maxW = W - M - x;
    var title = this.wrap(d.titulo, 10, true, maxW);
    var first = title.length * 14 + 12;
    this.need(this.measureItem(d));
    if (d.ancla) this.anchor(d.ancla);
    var top = this.y;
    // Marca: cuadrado rojo relleno = no cumple; contorno rojo = aviso (no solo por color: también lo dice la línea)
    if (d.estado === 'NO_CUMPLE') this.rect(M, top - 12, 7, 7, C.rojo);
    else if (d.estado === 'AVISO') this.strokeRect(M + 0.5, top - 11.5, 6, 6, C.rojo, 1);
    else if (d.estado === 'CUMPLE') this.rect(M, top - 12, 7, 7, C.negro);
    else this.rect(M, top - 10, 7, 2.5, C.negro);   // requisito o sin resultado: guion negro
    var self = this;
    title.forEach(function (l) { self.y -= 14; self.text(x, self.y + 2, l, 10, true, C.negro, true); });
    if (d.linea) this.p(d.linea, { size: 8, color: C.gris, indent: 16, gap: 1 });
    if (d.texto) this.p(d.texto, { size: 9, indent: 16, gap: 1 });
    (d.textos || []).forEach(function (t) {
      if (!t || !t[1]) return;
      self.need(24);
      self.y -= 3;
      self.text(x, self.y - 9, String(t[0]).toUpperCase(), 7, true, t[2] || C.gris);
      self.y -= 11;
      self.p(t[1], { size: 9, indent: 16, gap: 1 });
    });
    if (d.arreglo) {
      this.need(24);
      this.y -= 3;
      this.text(x, this.y - 9, 'CÓMO ARREGLARLO', 7, true, C.rojo);
      this.y -= 11;
      this.p(d.arreglo, { size: 9, indent: 16, gap: 1 });
    }
    this.y -= 6;
    // Separador entre puntos; si ya no cabe, no se pinta (una raya suelta abriendo página queda mal)
    if (this.y - 8 >= BOTTOM) this.line(M, this.y, W - M, this.y, C.linea, 0.5);
    this.y -= 8;
  };

  /** Aviso destacado en un panel gris con filete rojo a la izquierda. */
  Doc.prototype.note = function (text) {
    var lines = this.wrap(text, 9, false, W - 2 * M - 24);
    var h = lines.length * 12.8 + 16;
    this.need(h + 8);
    var top = this.y - 4;
    this.rect(M, top - h, W - 2 * M, h, C.panel);
    this.rect(M, top - h, 3, h, C.rojo);
    var self = this;
    lines.forEach(function (l, i) { self.text(M + 14, top - 15 - i * 12.8, l, 9, false, C.g4, true); });
    this.y = top - h - 10;
  };

  /**
   * Tabla con cabecera gris que se repite en cada página. cols: [{titulo, ancho (fracción), alinear: 'right'}];
   * rows: [[celda, …]] (texto o {texto, bold, link}). Una fila nunca se parte entre páginas.
   */
  Doc.prototype.table = function (cols, rows, opts) {
    opts = opts || {};
    var size = opts.size || 8.5;
    var lead = size * 1.4;
    var pad = 5;
    var total = W - 2 * M;
    var sum = cols.reduce(function (a, c) { return a + (c.ancho || 1); }, 0);
    var ws = cols.map(function (c) { return total * (c.ancho || 1) / sum; });
    var self = this;
    // Una celda es texto o {texto, bold, link} (ojo: 'texto'.bold es un método de String)
    var isBold = function (c) { return !!(c && typeof c === 'object' && c.bold); };
    var cellLines = function (cell, i, bold) {
      var txt = cell && typeof cell === 'object' ? cell.texto : cell;
      return self.wrap(txt === null || txt === undefined ? '' : String(txt), size, bold, ws[i] - 2 * pad);
    };
    var drawRow = function (cells, head) {
      var lines = cells.map(function (c, i) { return cellLines(c, i, head || isBold(c)); });
      var h = Math.max.apply(null, lines.map(function (l) { return l.length; })) * lead + 2 * pad - 2;
      var top = self.y;
      if (head) self.rect(M, top - h, total, h, C.panel);
      var x = M;
      lines.forEach(function (ls, i) {
        var bold = head || isBold(cells[i]);
        ls.forEach(function (l, k) {
          var tx = cols[i].alinear === 'right' ? x + ws[i] - pad - self.width(l, size, bold) : x + pad;
          self.text(tx, top - pad - size - k * lead + 1.5, l, size, bold, head ? C.negro : C.g4, true);
        });
        if (!head && cells[i] && typeof cells[i] === 'object' && cells[i].link) self.link([x, top - h, x + ws[i], top], cells[i].link);
        x += ws[i];
      });
      self.line(M, top - h, M + total, top - h, head ? C.gris : C.linea, head ? 0.75 : 0.5);
      self.y = top - h;
      return h;
    };
    var headH = Math.max.apply(null, cols.map(function (c, i) { return cellLines(c.titulo, i, true).length; })) * lead + 2 * pad - 2;
    var firstRowH = rows.length ? Math.max.apply(null, rows[0].map(function (c, i) { return cellLines(c, i, isBold(c)).length; })) * lead + 2 * pad : 0;
    this.need(headH + firstRowH + 4);
    if (opts.caption) { this.p(opts.caption, { size: 8.5, bold: true, color: C.negro, gap: 2 }); }
    drawRow(cols.map(function (c) { return c.titulo; }), true);
    rows.forEach(function (r) {
      var rh = Math.max.apply(null, r.map(function (c, i) { return cellLines(c, i, isBold(c)).length; })) * lead + 2 * pad - 2;
      if (self.y - rh < BOTTOM) {
        self.newPage(false);
        drawRow(cols.map(function (c) { return c.titulo; }), true);   // cabecera repetida
      }
      drawRow(r, false);
    });
    this.y -= 12;
  };

  /** Pie de cada página: línea fina gris, logotipo abajo a la izquierda, aviso y «Página X de Y». */
  Doc.prototype.footers = function () {
    var total = this.pages.length;
    var self = this;
    this.pages.forEach(function (ops, i) {
      self.ops = ops;
      self.line(M, FOOT_LINE, W - M, FOOT_LINE, C.linea, 0.5);
      var after = M;
      if (self.logo) {
        var lh = LOGO_W * self.logo.h / self.logo.w;
        self.op('q ' + n(LOGO_W) + ' 0 0 ' + n(lh) + ' ' + n(M) + ' ' + n(FOOT_LINE - 12 - lh) + ' cm /Logo Do Q');
        after = M + LOGO_W + 14;
      } else {
        self.text(M, FOOT_LINE - 26, 'proconsi', 12, true, C.negro);
        after = M + 70;
      }
      var num = winAnsi('Página ' + (i + 1) + ' de ' + total);
      var nw = self.width(num, 7, true);
      var pie = self.wrap(PIE, 6.5, false, W - M - nw - 14 - after);
      pie.slice(0, 2).forEach(function (l, k) { self.text(after, FOOT_LINE - 20 - k * 8.5, l, 6.5, false, C.gris, true); });
      self.text(W - M - nw, FOOT_LINE - 20, num, 7, true, C.negro, true);
    });
  };

  /** Objetos de una fuente incrustada (TrueType, WinAnsi) o de Helvetica. */
  function fontObjects(add, f, fallback) {
    if (!f) return add('<< /Type /Font /Subtype /Type1 /BaseFont /' + fallback + ' /Encoding /WinAnsiEncoding >>');
    var file = f.file;
    var stream = file.data
      ? '<< /Length ' + file.data.length + ' /Length1 ' + file.length1 + ' /Filter /FlateDecode >>\nstream\n' + file.data + '\nendstream'
      : '<< /Length ' + file.raw.length + ' /Length1 ' + file.length1 + ' >>\nstream\n' + file.raw + '\nendstream';
    var fileId = add(stream);
    var m = f.m;
    var desc = add('<< /Type /FontDescriptor /FontName /' + f.name + ' /Flags 32 /FontBBox [' + m.bbox.join(' ') + '] /ItalicAngle 0 /Ascent '
      + m.ascent + ' /Descent ' + m.descent + ' /CapHeight 700 /StemV ' + (f.name.indexOf('Semi') !== -1 ? 120 : 80) + ' /FontFile2 ' + fileId + ' 0 R >>');
    return add('<< /Type /Font /Subtype /TrueType /BaseFont /' + f.name + ' /FirstChar 32 /LastChar 255 /Widths [' + m.widths.slice(32).join(' ')
      + '] /Encoding /WinAnsiEncoding /FontDescriptor ' + desc + ' 0 R >>');
  }

  /** Bytes del PDF (cadena en la que cada carácter es un byte). */
  Doc.prototype.bytes = function () {
    if (!this.footersDone) { this.footers(); this.footersDone = true; }
    var self = this;
    var objs = [];
    var add = function (body) { objs.push(body); return objs.length; };
    var catalog = add(null);
    var pagesId = add(null);
    var f1 = fontObjects(add, this.fonts && this.fonts.regular, 'Helvetica');
    var f2 = fontObjects(add, this.fonts && this.fonts.bold, 'Helvetica-Bold');
    var logoId = this.logo
      ? add('<< /Type /XObject /Subtype /Image /Width ' + this.logo.w + ' /Height ' + this.logo.h
        + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + this.logo.bytes.length + ' >>\nstream\n' + this.logo.bytes + '\nendstream')
      : 0;
    var resources = '<< /Font << /F1 ' + f1 + ' 0 R /F2 ' + f2 + ' 0 R >>' + (logoId ? ' /XObject << /Logo ' + logoId + ' 0 R >>' : '') + ' >>';
    // Números de objeto de las páginas, reservados antes para los destinos de los enlaces
    var contentIds = [], pageIds = [];
    this.pages.forEach(function (ops) {
      var content = ops.join('\n');
      contentIds.push(add('<< /Length ' + content.length + ' >>\nstream\n' + content + '\nendstream'));
      pageIds.push(add(null));
    });
    var dest = function (key) {
      var a = self.anchors[key];
      return a ? '[' + pageIds[a.page] + ' 0 R /XYZ 0 ' + n(a.y) + ' 0]' : null;
    };
    this.pages.forEach(function (ops, i) {
      var annots = self.annots[i].map(function (l) {
        var d = dest(l.key);
        return d ? add('<< /Type /Annot /Subtype /Link /Rect [' + l.rect.map(n).join(' ') + '] /Border [0 0 0] /Dest ' + d + ' >>') : 0;
      }).filter(Boolean);
      objs[pageIds[i] - 1] = '<< /Type /Page /Parent ' + pagesId + ' 0 R /MediaBox [0 0 ' + n(W) + ' ' + n(H) + '] /Resources ' + resources
        + ' /Contents ' + contentIds[i] + ' 0 R' + (annots.length ? ' /Annots [' + annots.map(function (a) { return a + ' 0 R'; }).join(' ') + ']' : '') + ' >>';
    });
    // Marcadores (outline): secciones de nivel 1 con sus subsecciones
    var outlineId = 0;
    var tops = [];
    this.sections.forEach(function (s) {
      if (s.nivel === 1 || !tops.length) tops.push({ s: s, kids: [] });
      else tops[tops.length - 1].kids.push(s);
    });
    if (tops.length) {
      outlineId = add(null);
      var mk = function (list, parent) {
        var ids = list.map(function () { return add(null); });
        list.forEach(function (it, i) {
          var s = it.s || it;
          var kidIds = it.kids && it.kids.length ? mk(it.kids, ids[i]) : null;
          objs[ids[i] - 1] = '<< /Title ' + utf16(s.titulo) + ' /Parent ' + parent + ' 0 R' + (i ? ' /Prev ' + ids[i - 1] + ' 0 R' : '')
            + (i < ids.length - 1 ? ' /Next ' + ids[i + 1] + ' 0 R' : '') + ' /Dest ' + (dest(s.key) || '[' + pageIds[s.page] + ' 0 R /Fit]')
            + (kidIds ? ' /First ' + kidIds[0] + ' 0 R /Last ' + kidIds[kidIds.length - 1] + ' 0 R /Count -' + kidIds.length : '') + ' >>';
        });
        return ids;
      };
      var topIds = mk(tops, outlineId);
      objs[outlineId - 1] = '<< /Type /Outlines /First ' + topIds[0] + ' 0 R /Last ' + topIds[topIds.length - 1] + ' 0 R /Count ' + topIds.length + ' >>';
    }
    objs[catalog - 1] = '<< /Type /Catalog /Pages ' + pagesId + ' 0 R /Lang (es)' + (outlineId ? ' /Outlines ' + outlineId + ' 0 R /PageMode /UseOutlines' : '') + ' >>';
    objs[pagesId - 1] = '<< /Type /Pages /Kids [' + pageIds.map(function (k) { return k + ' 0 R'; }).join(' ') + '] /Count ' + pageIds.length + ' >>';
    var info = add('<< /Title ' + utf16((this.opts.eyebrow ? this.opts.eyebrow + ' · ' : '') + (this.opts.titulo || 'Radar web'))
      + ' /Author (Proconsi - Radar web) /Producer (Radar web) >>');
    var out = '%PDF-1.4\n%\xe2\xe3\xcf\xd3\n';
    var offsets = [];
    objs.forEach(function (body, i) {
      offsets.push(out.length);
      out += (i + 1) + ' 0 obj\n' + body + '\nendobj\n';
    });
    var xref = out.length;
    out += 'xref\n0 ' + (objs.length + 1) + '\n0000000000 65535 f \n';
    offsets.forEach(function (o) { out += ('0000000000' + o).slice(-10) + ' 00000 n \n'; });
    out += 'trailer\n<< /Size ' + (objs.length + 1) + ' /Root ' + catalog + ' 0 R /Info ' + info + ' 0 R >>\nstartxref\n' + xref + '\n%%EOF\n';
    return out;
  };

  /** Descarga el PDF con el nombre indicado. */
  Doc.prototype.blob = function () {
    var bin = this.bytes();
    var arr = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i) & 0xff;
    return new Blob([arr], { type: 'application/pdf' });
  };

  Doc.prototype.save = function (fileName) {
    var href = URL.createObjectURL(this.blob());
    var a = document.createElement('a');
    a.href = href;
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(href); }, 2000);
  };

  /** Índice a partir de las secciones de nivel 1 de una pasada. */
  function tocOf(doc) {
    return doc.sections.filter(function (s) { return s.nivel === 1; }).map(function (s) { return { key: s.key, titulo: s.titulo, pagina: s.page + 1 }; });
  }

  /**
   * Documento con portada e índice en dos pasadas: la primera compone para saber en qué página cae cada sección;
   * la segunda vuelve a componer con el índice ya numerado. Si algo se moviera (no debería: la portada siempre
   * ocupa una página), se repite una vez más. compose(doc) debe ser síncrona y determinista.
   */
  function build(opts, compose, assets) {
    var o = {};
    Object.keys(opts || {}).forEach(function (k) { o[k] = opts[k]; });
    o.portada = true;
    var first = new Doc(o, assets, []);
    compose(first);
    var toc = tocOf(first);
    var doc = null;
    for (var pass = 2; pass <= 3; pass++) {
      doc = new Doc(o, assets, toc);
      compose(doc);
      var again = tocOf(doc);
      doc.passes = pass;
      if (JSON.stringify(again) === JSON.stringify(toc)) break;
      toc = again;
    }
    doc.tocEntries = toc;
    return doc;
  }

  /** Nombre de archivo: proconsi-radar-<tipo>-<dominio>-<fecha>.pdf */
  function fileName(kind, url) {
    var host = '';
    try { host = new URL(url).hostname.replace(/^www\./, ''); } catch (e) { host = ''; }
    // Mismo sello de fecha que los CSV y el JSON: AAAAMMDD-HHMM (hora local)
    var d = new Date();
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    var stamp = d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes());
    return ['proconsi-radar', kind, host, stamp].filter(Boolean).join('-').replace(/[^a-z0-9.\-]/gi, '-') + '.pdf';
  }

  /** Logotipo y Poppins (si están): {logo, fonts}. Nunca falla: lo que no llegue se sustituye. */
  function load() {
    var src = (typeof document !== 'undefined' && document.querySelector('.brand__logo')) ? document.querySelector('.brand__logo').getAttribute('src') : null;
    var fontsP = root.RadarPdfFonts ? root.RadarPdfFonts.load().then(prepareFonts, function () { return null; }) : Promise.resolve(null);
    return Promise.all([loadLogo(src), fontsP]).then(function (r) { return { logo: r[0], fonts: r[1] }; });
  }

  var api = {
    load: load,
    build: build,
    create: function (opts) { return load().then(function (assets) { return new Doc(opts || {}, assets); }); },
    createSync: function (opts, logo, fonts) { return new Doc(opts || {}, { logo: logo || null, fonts: fonts || null }); },
    buildSync: build,
    parseTtf: parseTtf,
    prepareFonts: prepareFonts,
    fileName: fileName,
    winAnsi: winAnsi,
    wrap: wrap,
    width: width,
    colors: C,
    PAGE: { W: W, H: H, M: M, TOP: TOP, BOTTOM: BOTTOM }
  };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.RadarPdf = api;
})(typeof window !== 'undefined' ? window : this);
