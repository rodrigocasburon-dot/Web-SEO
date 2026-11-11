/* Pruebas de AnalyzerCompare (función 10). Escribe en #resultado un JSON {ok, fallos}. */
(function () {
  'use strict';
  var ok = 0;
  var fallos = [];
  function same(expected, actual, name) {
    var e = JSON.stringify(expected);
    var a = JSON.stringify(actual);
    if (e === a) ok++;
    else fallos.push(name + ': esperado ' + e + ', obtenido ' + a);
  }
  function check(name, fn) {
    try { fn(); } catch (err) { fallos.push(name + ': excepción ' + err.message); }
  }
  var X = window.AnalyzerCompare;

  function score(seo, ia, acc, global, incompleteAcc) {
    return {
      areas: {
        seo: { redondeada: seo, incompleta: false },
        ia: { redondeada: ia, incompleta: false },
        accesibilidad: { redondeada: acc, incompleta: !!incompleteAcc }
      },
      global: { redondeada: global, incompleta: !!incompleteAcc }
    };
  }

  check('notas: diferencia URL 2 − URL 1 sobre la nota mostrada', function () {
    var rows = X.scoreRows({ puntuacion: score(100, 77.4, 48.3, 74.1) }, { puntuacion: score(92, 77.4, 100, 90.8, true) });
    same(['seo', 'ia', 'accesibilidad', 'global'], rows.map(function (r) { return r.clave; }), 'orden');
    same(-8, rows[0].diferencia, 'baja');
    same(0, rows[1].diferencia, 'igual');
    same(51.7, rows[2].diferencia, 'sube, sin error de coma flotante');
    same(16.7, rows[3].diferencia, 'global');
    same([false, true], [rows[2].incompleta_a, rows[2].incompleta_b], 'nota incompleta');
  });

  check('notas: acceso bloqueado → sin cifra ni diferencia', function () {
    var rows = X.scoreRows({ blocked: { message: '403' }, puntuacion: score(100, 100, 100, 100) }, { puntuacion: score(90, 90, 90, 90) });
    same(null, rows[0].a, 'sin nota');
    same(90, rows[0].b, 'la otra sí');
    same(null, rows[0].diferencia, 'sin diferencia');
  });

  check('comprobaciones: unión ordenada, estado, casos y diferencias', function () {
    var a = { checks: [
      { id: 'C04', nombre: 'Nombres accesibles', severidad: 'ALTO', estado: 'NO_CUMPLE', ocurrencias: 7 },
      { id: 'C01', nombre: 'HTTP', estado: 'CUMPLE' },
      { id: 'C12', nombre: 'Canonical', estado: 'AVISO', ocurrencias: 1 },
      { id: 'C13', nombre: 'Datos', estado: 'CUMPLE', aplica: false }
    ] };
    var b = { checks: [
      { id: 'C01', nombre: 'HTTP', estado: 'CUMPLE' },
      { id: 'C04', nombre: 'Nombres accesibles', estado: 'NO_CUMPLE', ocurrencias: 2 },
      { id: 'C12', nombre: 'Canonical', estado: 'AVISO', ocurrencias: 3 },
      { id: 'C14', nombre: 'CWV', estado: 'SIN_DATOS' }
    ] };
    var rows = X.checkRows(a, b);
    same(['C01', 'C04', 'C12', 'C13', 'C14'], rows.map(function (r) { return r.id; }), 'unión ordenada');
    same(false, rows[0].distinto, 'mismo estado');
    same([{ estado: 'NO_CUMPLE', casos: 7 }, { estado: 'NO_CUMPLE', casos: 2 }, true], [rows[1].a, rows[1].b, rows[1].distinto], 'fallo con distintos casos');
    same(false, rows[2].distinto, 'avisos: los casos no cuentan (no restan)');
    same(['NA', 'SIN_DATOS', true], [rows[3].a.estado, rows[3].b.estado, rows[3].distinto], 'no aplica frente a ausente');
    same('CWV', rows[4].nombre, 'nombre de la otra URL');
    same('ALTO', rows[1].severidad, 'gravedad');
  });

  check('recuento por estado', function () {
    same({ NO_CUMPLE: 1, AVISO: 1, SIN_DATOS: 1, NA: 1, CUMPLE: 1 }, X.counts({ checks: [
      { id: 'C01', estado: 'NO_CUMPLE' }, { id: 'C02', estado: 'AVISO' }, { id: 'C03', estado: 'SIN_DATOS' },
      { id: 'C04', estado: 'CUMPLE', aplica: false }, { id: 'C05', estado: 'CUMPLE' }
    ] }), 'recuento');
    same(0, X.counts(null).NO_CUMPLE, 'sin datos');
  });

  check('textos', function () {
    same('+12,3', X.diffText(12.3), 'positiva');
    same('−5,0', X.diffText(-5), 'negativa con signo menos');
    same('0,0', X.diffText(0), 'cero');
    same('—', X.diffText(null), 'sin diferencia');
    var i18n = { estados: { NO_CUMPLE: 'Fallo', CUMPLE: 'Sin errores detectables automáticamente' } };
    same('Fallo (7 casos)', X.stateText({ estado: 'NO_CUMPLE', casos: 7 }, i18n), 'fallo con casos');
    same('Fallo', X.stateText({ estado: 'NO_CUMPLE', casos: 1 }, i18n), 'un caso');
    same('No aplica', X.stateText({ estado: 'NA', casos: 0 }, i18n), 'no aplica');
  });

  document.getElementById('resultado').textContent = JSON.stringify({ ok: ok, fallos: fallos });
})();
