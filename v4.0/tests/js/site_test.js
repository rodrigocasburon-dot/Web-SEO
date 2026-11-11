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
// Fiabilidad de la nota media: páginas con nota sobre previstas y comprobaciones evaluadas (no cambia la media)
assert.strictEqual(agg.fiabilidad.estado, 'parcial');
assert.deepStrictEqual(agg.fiabilidad.paginas, { con_nota: 2, previstas: 4, bloqueadas: 1, errores: 1 });
assert.strictEqual(agg.fiabilidad.evaluados, 4);
assert.strictEqual(agg.fiabilidad.posibles, 4);
const full = aggregate([page('https://e.com/', 80, { C04: 'CUMPLE' }), page('https://e.com/a', 60, { C04: 'NO_CUMPLE' })], { previstas: 2 });
assert.strictEqual(full.fiabilidad.estado, 'completo');
assert.strictEqual(full.media.global, 70);
const noData = aggregate([page('https://e.com/', 80, { C04: 'CUMPLE', C08: 'SIN_DATOS' })], { previstas: 1 });
assert.strictEqual(noData.fiabilidad.estado, 'parcial');
assert.strictEqual(noData.fiabilidad.sin_datos, 1);
assert.strictEqual(noData.fiabilidad.porcentaje, 50);
assert.strictEqual(aggregate([page('https://e.com/', 80, { C04: 'CUMPLE' })], { previstas: 5 }).fiabilidad.estado, 'parcial');
const blockedHome = aggregate([Object.assign(page('https://e.com/', 0, {}), { portada: true, blocked: { reason: 'captcha' } })], { previstas: 3 });
assert.strictEqual(blockedHome.fiabilidad.estado, 'bloqueado');

// Recomendaciones agrupadas: la misma recomendación en varias páginas aparece una vez, con recuento y páginas (con tope)
const rec = (id, estado, recomendacion, severidad) => ({ id, nombre: 'n' + id, severidad: severidad || 'ALTO', estado, aplica: true, recomendacion, detalle: 'd' + id });
const sitePage = (url, checks) => ({ ok: true, url, blocked: null, notas: { seo: 50, ia: 50, accesibilidad: 50, global: 50 }, checks });
const pagesG = [];
for (let i = 0; i < 7; i++) {
  pagesG.push(sitePage('https://e.com/p' + i, [
    rec('C06', 'NO_CUMPLE', 'Añade texto alternativo.'),
    i < 3 ? rec('C10', i === 0 ? 'NO_CUMPLE' : 'AVISO', 'Mejora el título.', 'MEDIO') : rec('C10', 'CUMPLE', ''),
    i === 6 ? rec('C04', 'NO_CUMPLE', 'Pon nombre a los controles.', 'CRITICO') : rec('C04', 'CUMPLE', ''),
    rec('C16', 'NO_CUMPLE', 'Corrige los enlaces rotos.'),            // de todo el sitio: no se agrupa aquí
    rec('C21', 'NO_CUMPLE', 'Añade texto alternativo.', 'CRITICO')    // misma recomendación desde otra comprobación
  ]));
}
const g = aggregate(pagesG, { maxPaginas: 5 }).recomendaciones;
assert.deepStrictEqual(g.map((x) => x.recomendacion), ['Añade texto alternativo.', 'Mejora el título.', 'Pon nombre a los controles.']);
assert.strictEqual(g[0].paginas_total, 7, 'una página cuenta una vez aunque dos comprobaciones den la misma recomendación');
assert.deepStrictEqual(g[0].ids, ['C06', 'C21']);
assert.strictEqual(g[0].severidad, 'CRITICO', 'la gravedad del grupo es la mayor');
assert.strictEqual(g[0].paginas.length, 5, 'tope configurable de páginas listadas');
assert.strictEqual(g[0].paginas_mas, 2);
assert.strictEqual(g[0].paginas[0].url, 'https://e.com/p0');
assert.strictEqual(g[1].paginas_total, 3);
assert.strictEqual(g[1].paginas_fallan, 1);
assert.strictEqual(g[1].paginas_aviso, 2);
assert.strictEqual(g[1].paginas_mas, 0);
assert.strictEqual(g[2].paginas_total, 1);
assert.strictEqual(g.some((x) => x.ids.indexOf('C16') !== -1), false);
assert.strictEqual(aggregate(pagesG).recomendaciones[0].paginas.length, 7, 'tope por defecto: 20');
assert.deepStrictEqual(aggregate([]).recomendaciones, []);
console.log('site_test.js: OK');
