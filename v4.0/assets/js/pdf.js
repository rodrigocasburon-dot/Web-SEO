/*
 * Radar web · PDF corporativos que se DESCARGAN (no es el diálogo de imprimir). Sin librerías: escribe el PDF
 * a mano (PDF 1.4, A4) con las fuentes estándar Helvetica y Helvetica-Bold (WinAnsi: cubre el español) y el
 * logotipo de Proconsi como imagen JPEG. Identidad de proconsi.com: cabecera con el logotipo y filete rojo,
 * banda negra con el título, títulos con barra roja corta, tarjetas de nota y pie con número de página.
 *
 * Uso:  RadarPdf.create({ eyebrow, titulo, subtitulo: [líneas] }).then(function (doc) {
 *         doc.h2('…'); doc.p('…'); doc.scores([...]); doc.item({...}); doc.save('archivo.pdf');
 *       });
 * Todo el texto se escribe como texto del PDF (se puede buscar y copiar); nada se interpreta como HTML.
 */
(function (root) {
  'use strict';

  var W = 595.28;            // A4 en puntos
  var H = 841.89;
  var M = 50;                // margen lateral
  var TOP = 92;              // donde empieza el contenido en las páginas 2 y siguientes
  var BOTTOM = 64;           // por encima del pie
  var C = {                  // paleta de DESIGN.md §2
    negro: [0, 0, 0], rojo: [0.890, 0.024, 0.075], gris: [0.424, 0.424, 0.424], grisClaro: [0.706, 0.706, 0.706],
    linea: [0.851, 0.851, 0.851], panel: [0.957, 0.953, 0.949], blanco: [1, 1, 1], g4: [0.180, 0.180, 0.180]
  };
  var PIE = 'Proconsi · Radar web · Evaluación automática y parcial. No sustituye una auditoría manual.';

  // Anchos de Helvetica y Helvetica-Bold (AFM de Adobe, milésimas de em) para los caracteres 32–126
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
  var SUB = { '✕': 'x', '✓': 'v', '−': '-', '→': '->', '←': '<-', '≥': '>=', '≤': '<=', '▾': '', '▴': '', ' ': ' ', ' ': ' ', ' ': ' ' };
  var SPECIAL_W = { 0x80: 556, 0x85: 1000, 0x91: 222, 0x92: 222, 0x93: 333, 0x94: 333, 0x95: 350, 0x96: 556, 0x97: 1000, 0x99: 1000,
    0xAB: 556, 0xBB: 556, 0xB7: 278, 0xBA: 365, 0xAA: 370, 0xBF: 611, 0xA1: 333, 0xB0: 400, 0xD7: 584 };

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

  function charWidth(b, bold) {
    if (b >= 32 && b < 127) return (bold ? WB : WR)[b - 32];
    if (SPECIAL_W[b]) return SPECIAL_W[b];
    // Letras acentuadas: el ancho de su letra base
    var base = String.fromCharCode(b).normalize ? String.fromCharCode(b).normalize('NFD').charCodeAt(0) : 0;
    if (base >= 32 && base < 127) return (bold ? WB : WR)[base - 32];
    return 556;
  }

  /** Ancho en puntos de un texto ya convertido a WinAnsi. */
  function width(enc, size, bold) {
    var w = 0;
    for (var i = 0; i < enc.length; i++) w += charWidth(enc.charCodeAt(i), bold);
    return w * size / 1000;
  }

  /** Parte un texto en líneas que caben en maxW (las palabras muy largas, como las URL, se cortan). */
  function wrap(text, size, bold, maxW) {
    var enc = winAnsi(text);
    var words = enc.split(' ');
    var lines = [];
    var line = '';
    words.forEach(function (word) {
      var tryLine = line ? line + ' ' + word : word;
      if (width(tryLine, size, bold) <= maxW) { line = tryLine; return; }
      if (line) lines.push(line);
      line = '';
      while (width(word, size, bold) > maxW) {
        var cut = word.length;
        while (cut > 1 && width(word.slice(0, cut), size, bold) > maxW) cut--;
        lines.push(word.slice(0, cut));
        word = word.slice(cut);
      }
      line = word;
    });
    if (line || !lines.length) lines.push(line);
    return lines;
  }

  function esc(enc) { return enc.replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)'); }
  function n(v) { return (Math.round(v * 100) / 100).toString(); }
  function rgb(c) { return n(c[0]) + ' ' + n(c[1]) + ' ' + n(c[2]); }
  function fmt(v) { return v === null || v === undefined ? '—' : (Math.round(v * 10) / 10).toLocaleString('es-ES'); }

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

  // ---------------------------------------------------------------- documento
  function Doc(opts, logo) {
    this.opts = opts;
    this.logo = logo;
    this.pages = [];
    this.newPage(true);
  }

  Doc.prototype.newPage = function (first) {
    this.ops = [];
    this.pages.push(this.ops);
    this.header();
    this.y = first ? this.titleBand() : H - TOP;
  };

  Doc.prototype.op = function (s) { this.ops.push(s); };

  Doc.prototype.rect = function (x, y, w, h, color) { this.op(rgb(color) + ' rg ' + n(x) + ' ' + n(y) + ' ' + n(w) + ' ' + n(h) + ' re f'); };

  Doc.prototype.strokeRect = function (x, y, w, h, color, lw) {
    this.op(rgb(color) + ' RG ' + n(lw || 0.75) + ' w ' + n(x) + ' ' + n(y) + ' ' + n(w) + ' ' + n(h) + ' re S');
  };

  Doc.prototype.line = function (x1, y1, x2, y2, color, lw) {
    this.op(rgb(color) + ' RG ' + n(lw || 0.75) + ' w ' + n(x1) + ' ' + n(y1) + ' m ' + n(x2) + ' ' + n(y2) + ' l S');
  };

  /** Texto en una línea (ya en WinAnsi o no); y es la línea base. */
  Doc.prototype.text = function (x, y, str, size, bold, color, encoded) {
    var enc = encoded ? str : winAnsi(str);
    this.op('BT /' + (bold ? 'F2' : 'F1') + ' ' + n(size) + ' Tf ' + rgb(color || C.negro) + ' rg ' + n(x) + ' ' + n(y) + ' Td (' + esc(enc) + ') Tj ET');
  };

  Doc.prototype.header = function () {
    if (this.logo) {
      var h = 24;
      var w = h * this.logo.w / this.logo.h;
      this.op('q ' + n(w) + ' 0 0 ' + n(h) + ' ' + n(M) + ' ' + n(H - 50) + ' cm /Logo Do Q');
    } else {
      this.text(M, H - 42, 'proconsi', 16, true, C.negro);
    }
    var tag = winAnsi('RADAR WEB');
    this.text(W - M - width(tag, 8, true), H - 40, tag, 8, true, C.gris, true);
    this.rect(M, H - 62, W - 2 * M, 1.5, C.rojo);
  };

  /** Banda negra del título (solo en la primera página). Devuelve la «y» donde sigue el contenido. */
  Doc.prototype.titleBand = function () {
    var o = this.opts;
    var titleLines = wrap(o.titulo || '', 22, true, W - 2 * M);
    var subLines = [];
    (o.subtitulo || []).forEach(function (s) { subLines = subLines.concat(wrap(s, 9.5, false, W - 2 * M)); });
    var h = 34 + 14 + titleLines.length * 27 + (subLines.length ? 8 + subLines.length * 14 : 0) + 22;
    var top = H - 78;
    this.rect(0, top - h, W, h, C.negro);
    var y = top - 30;
    this.rect(M, y + 2.5, 22, 2, C.rojo);
    this.text(M + 30, y, (o.eyebrow || '').toUpperCase(), 8, true, C.blanco);
    y -= 32;
    var self = this;
    titleLines.forEach(function (l) { self.text(M, y, l, 22, true, C.blanco, true); y -= 27; });
    y += 4;
    subLines.forEach(function (l) { y -= 14; self.text(M, y, l, 9.5, false, C.grisClaro, true); });
    return top - h - 28;
  };

  /** Asegura sitio para h puntos; si no cabe, página nueva. */
  Doc.prototype.need = function (h) {
    if (this.y - h < BOTTOM) this.newPage(false);
  };

  Doc.prototype.space = function (h) { this.y -= h; };

  /** Título de sección con barra roja corta debajo. */
  Doc.prototype.h2 = function (text) {
    var lines = wrap(text, 15, true, W - 2 * M);
    this.need(24 + lines.length * 19 + 40);
    this.y -= 10;
    var self = this;
    lines.forEach(function (l) { self.y -= 19; self.text(M, self.y, l, 15, true, C.negro, true); });
    this.y -= 9;
    this.rect(M, this.y, 26, 2.5, C.rojo);
    this.y -= 14;
  };

  Doc.prototype.h3 = function (text, color) {
    var lines = wrap(text, 11, true, W - 2 * M);
    this.need(lines.length * 15 + 24);
    this.y -= 6;
    var self = this;
    lines.forEach(function (l) { self.y -= 15; self.text(M, self.y, l, 11, true, color || C.negro, true); });
    this.y -= 4;
  };

  /** Párrafo con salto de línea automático. opts: {size, bold, color, indent, gap} */
  Doc.prototype.p = function (text, opts) {
    opts = opts || {};
    var size = opts.size || 9.5;
    var lead = size * 1.42;
    var x = M + (opts.indent || 0);
    var lines = wrap(text, size, !!opts.bold, W - M - x);
    var self = this;
    lines.forEach(function (l) {
      self.need(lead);
      self.y -= lead;
      self.text(x, self.y + 2, l, size, !!opts.bold, opts.color || C.g4, true);
    });
    this.y -= opts.gap === undefined ? 4 : opts.gap;
  };

  /** Lista de datos «etiqueta: valor» en dos columnas. */
  Doc.prototype.facts = function (rows) {
    var self = this;
    rows.forEach(function (r) {
      var lines = wrap(r[1], 9.5, false, W - 2 * M - 110);
      self.need(lines.length * 13.5 + 4);
      self.text(M, self.y - 11.5, r[0], 8.5, true, C.gris);
      lines.forEach(function (l) { self.y -= 13.5; self.text(M + 110, self.y + 2, l, 9.5, false, C.negro, true); });
      self.y -= 4;
    });
  };

  /** Fila de tarjetas de nota: [{etiqueta, nota (0–100 o null), detalle}] */
  Doc.prototype.scores = function (cards) {
    var gap = 12;
    var cw = (W - 2 * M - gap * (cards.length - 1)) / cards.length;
    var ch = 96;
    this.need(ch + 12);
    var top = this.y - 4;
    var self = this;
    cards.forEach(function (c, i) {
      var x = M + i * (cw + gap);
      self.strokeRect(x, top - ch, cw, ch, C.linea, 0.75);
      self.rect(x, top - 2.5, cw, 2.5, c.acento === 'negro' ? C.negro : C.rojo);
      self.text(x + 12, top - 20, String(c.etiqueta || '').toUpperCase(), 8, true, C.negro);
      var v = winAnsi(fmt(c.nota));
      self.text(x + 12, top - 50, v, 26, true, C.negro, true);
      self.text(x + 14 + width(v, 26, true), top - 50, '/100', 9, false, C.gris);
      var barW = cw - 24;
      self.rect(x + 12, top - 64, barW, 4, C.linea);
      if (typeof c.nota === 'number') self.rect(x + 12, top - 64, barW * Math.max(0, Math.min(100, c.nota)) / 100, 4, C.negro);
      var dl = wrap(c.detalle || '', 7.5, false, cw - 24).slice(0, 2);
      dl.forEach(function (l, k) { self.text(x + 12, top - 78 - k * 10, l, 7.5, false, C.gris, true); });
    });
    this.y = top - ch - 14;
  };

  /** Punto de una lista de mejoras: {titulo, linea, texto, arreglo, estado: NO_CUMPLE | AVISO} */
  Doc.prototype.item = function (d) {
    var x = M + 16;
    var maxW = W - M - x;
    var title = wrap(d.titulo, 10, true, maxW);
    var first = title.length * 14 + 12;
    this.need(first + 26);
    var top = this.y;
    // Marca: cuadrado rojo relleno = no cumple; contorno rojo = aviso (no solo por color: también lo dice la línea)
    if (d.estado === 'NO_CUMPLE') this.rect(M, top - 12, 7, 7, C.rojo);
    else if (d.estado === 'AVISO') this.strokeRect(M + 0.5, top - 11.5, 6, 6, C.rojo, 1);
    else this.rect(M, top - 10, 7, 2.5, C.negro);   // requisito (no es un resultado): guion negro
    var self = this;
    title.forEach(function (l) { self.y -= 14; self.text(x, self.y + 2, l, 10, true, C.negro, true); });
    if (d.linea) this.p(d.linea, { size: 8, color: C.gris, indent: 16, gap: 1 });
    if (d.texto) this.p(d.texto, { size: 9, indent: 16, gap: 1 });
    if (d.arreglo) {
      this.need(14);
      this.y -= 3;
      this.text(x, this.y - 9, 'CÓMO ARREGLARLO', 7, true, C.rojo);
      this.y -= 11;
      this.p(d.arreglo, { size: 9, indent: 16, gap: 1 });
    }
    this.y -= 6;
    this.need(8);
    this.line(M, this.y, W - M, this.y, C.linea, 0.5);
    this.y -= 8;
  };

  /** Aviso destacado en un panel gris con filete rojo a la izquierda. */
  Doc.prototype.note = function (text) {
    var lines = wrap(text, 9, false, W - 2 * M - 24);
    var h = lines.length * 12.8 + 16;
    this.need(h + 8);
    var top = this.y - 4;
    this.rect(M, top - h, W - 2 * M, h, C.panel);
    this.rect(M, top - h, 3, h, C.rojo);
    var self = this;
    lines.forEach(function (l, i) { self.text(M + 14, top - 15 - i * 12.8, l, 9, false, C.g4, true); });
    this.y = top - h - 10;
  };

  /** Pie de cada página (con «Página X de Y», que solo se sabe al final). */
  Doc.prototype.footers = function () {
    var total = this.pages.length;
    var self = this;
    this.pages.forEach(function (ops, i) {
      self.ops = ops;
      self.line(M, 44, W - M, 44, C.linea, 0.5);
      self.text(M, 30, PIE, 7, false, C.gris);
      var num = winAnsi('Página ' + (i + 1) + ' de ' + total);
      self.text(W - M - width(num, 7, true), 30, num, 7, true, C.negro, true);
    });
  };

  /** Bytes del PDF (cadena en la que cada carácter es un byte). */
  Doc.prototype.bytes = function () {
    this.footers();
    var objs = [];
    var add = function (body) { objs.push(body); return objs.length; };
    var catalog = add(null);
    var pagesId = add(null);
    var f1 = add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
    var f2 = add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
    var logoId = this.logo
      ? add('<< /Type /XObject /Subtype /Image /Width ' + this.logo.w + ' /Height ' + this.logo.h
        + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + this.logo.bytes.length + ' >>\nstream\n' + this.logo.bytes + '\nendstream')
      : 0;
    var kids = [];
    var resources = '<< /Font << /F1 ' + f1 + ' 0 R /F2 ' + f2 + ' 0 R >>' + (logoId ? ' /XObject << /Logo ' + logoId + ' 0 R >>' : '') + ' >>';
    this.pages.forEach(function (ops) {
      var content = ops.join('\n');
      var cid = add('<< /Length ' + content.length + ' >>\nstream\n' + content + '\nendstream');
      kids.push(add('<< /Type /Page /Parent ' + pagesId + ' 0 R /MediaBox [0 0 ' + n(W) + ' ' + n(H) + '] /Resources ' + resources + ' /Contents ' + cid + ' 0 R >>'));
    });
    objs[catalog - 1] = '<< /Type /Catalog /Pages ' + pagesId + ' 0 R >>';
    objs[pagesId - 1] = '<< /Type /Pages /Kids [' + kids.map(function (k) { return k + ' 0 R'; }).join(' ') + '] /Count ' + kids.length + ' >>';
    var info = add('<< /Title (' + esc(winAnsi(this.opts.titulo || 'Radar web')) + ') /Author (Proconsi - Radar web) /Producer (Radar web) >>');
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
  Doc.prototype.save = function (fileName) {
    var bin = this.bytes();
    var arr = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i) & 0xff;
    var href = URL.createObjectURL(new Blob([arr], { type: 'application/pdf' }));
    var a = document.createElement('a');
    a.href = href;
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(href); }, 2000);
  };

  /** Nombre de archivo: proconsi-radar-<tipo>-<dominio>-<fecha>.pdf */
  function fileName(kind, url) {
    var host = '';
    try { host = new URL(url).hostname.replace(/^www\./, ''); } catch (e) { host = ''; }
    return ['proconsi-radar', kind, host, new Date().toISOString().slice(0, 10)].filter(Boolean).join('-').replace(/[^a-z0-9.\-]/gi, '-') + '.pdf';
  }

  var api = {
    create: function (opts) {
      var src = (typeof document !== 'undefined' && document.querySelector('.brand__logo')) ? document.querySelector('.brand__logo').getAttribute('src') : null;
      return loadLogo(src).then(function (logo) { return new Doc(opts || {}, logo); });
    },
    createSync: function (opts, logo) { return new Doc(opts || {}, logo || null); },
    fileName: fileName,
    winAnsi: winAnsi,
    wrap: wrap,
    colors: C
  };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.RadarPdf = api;
})(typeof window !== 'undefined' ? window : this);
