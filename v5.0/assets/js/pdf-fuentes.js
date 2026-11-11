/*
 * Radar web · carga de Poppins para los PDF (assets/js/pdf.js). Es el único archivo de los PDF que pide algo:
 * los TTF de assets/fonts/ del propio servidor (mismo origen, sin CDN). Comprime cada fuente con
 * CompressionStream('deflate') si el navegador lo permite. Si algo falla, devuelve null y el PDF usa Helvetica.
 */
(function (root) {
  'use strict';

  var FILES = { regular: 'assets/fonts/Poppins-Regular.ttf', bold: 'assets/fonts/Poppins-SemiBold.ttf' };
  var cache = null;

  function toBinary(u8) {
    var out = '';
    for (var i = 0; i < u8.length; i += 0x8000) out += String.fromCharCode.apply(null, u8.subarray(i, i + 0x8000));
    return out;
  }

  function deflate(u8) {
    if (typeof CompressionStream === 'undefined' || typeof Response === 'undefined') return Promise.resolve(null);
    try {
      var stream = new Blob([u8]).stream().pipeThrough(new CompressionStream('deflate'));
      return new Response(stream).arrayBuffer().then(function (b) { return new Uint8Array(b); }, function () { return null; });
    } catch (e) { return Promise.resolve(null); }
  }

  function one(url) {
    // Solo rutas relativas del propio sitio (lista fija de arriba)
    return root.fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.arrayBuffer();
    }).then(function (buf) {
      var u8 = new Uint8Array(buf);
      return deflate(u8).then(function (z) {
        return { raw: toBinary(u8), length1: u8.length, data: z ? toBinary(z) : null };
      });
    });
  }

  /** Promesa con {regular, bold} (bytes de cada TTF) o null si no se han podido cargar. */
  function load() {
    if (cache) return cache;
    if (typeof root.fetch !== 'function') return Promise.resolve(null);
    cache = Promise.all([one(FILES.regular), one(FILES.bold)]).then(function (r) {
      return { regular: r[0], bold: r[1] };
    }, function () { cache = null; return null; });
    return cache;
  }

  root.RadarPdfFonts = { load: load, FILES: FILES };
})(window);
