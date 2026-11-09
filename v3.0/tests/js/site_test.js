// v3.0 · Agregado del modo sitio: node tests/js/site_test.js
const assert = require('assert');
const { aggregate } = require('../../assets/js/site.js');
const page = (url, global, estados, extra) => Object.assign({
  ok: true, url, blocked: null,
  notas: { seo: global, ia: global, accesibilidad: global, global },
  checks: Object.keys(estados).map((id) => ({ id, nombre: id, severidad: 'ALTO', estado: estados[id], aplica: true }))
}, extra || {});
const agg = aggregate([
  page('https://e.com/', 80, { C04: 'NO_CUMPLE', C10: 'CUMPLE' }),
  page('https://e.com/a', 60, { C04: 'NO_CUMPLE', C10: 'AVISO' }),
  page('https://www.e.com/a/', 10, { C04: 'NO_CUMPLE' }),                       // duplicada tras redirección
  page('https://e.com/b', 0, { C04: 'NO_CUMPLE' }, { blocked: { reason: 'captcha' } }),
  { ok: false, url: 'https://e.com/c', error: { message: 'x' } }
]);
assert.strictEqual(agg.analizadas, 2);
assert.strictEqual(agg.bloqueadas, 1);
assert.strictEqual(agg.errores, 1);
assert.strictEqual(agg.media.global, 70);
assert.strictEqual(agg.minima.global, 60);
assert.strictEqual(agg.checks[0].id, 'C04');
assert.strictEqual(agg.checks[0].fallan, 2);
assert.strictEqual(agg.checks.find((c) => c.id === 'C10').avisos, 1);
assert.strictEqual(agg.peores[0].url, 'https://e.com/a');
assert.strictEqual(aggregate([]).media.global, null);
// Títulos repetidos entre páginas (WEB-08 de la v1.0)
const t = aggregate([
  page('https://e.com/', 90, {}, { titulo: 'Inicio' }),
  page('https://e.com/a', 90, {}, { titulo: 'Servicios | E' }),
  page('https://e.com/b', 90, {}, { titulo: 'servicios | e' }),
  page('https://e.com/c', 90, {}, { titulo: '' })
]);
assert.strictEqual(t.titulos_repetidos.length, 1);
assert.deepStrictEqual(t.titulos_repetidos[0].urls, ['https://e.com/a', 'https://e.com/b']);
assert.strictEqual(t.con_titulo, 3);
console.log('site_test.js: OK');
