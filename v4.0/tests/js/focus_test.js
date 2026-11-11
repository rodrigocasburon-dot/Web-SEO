// v4 · Vista por departamento: node tests/js/focus_test.js
const assert = require('assert');
const { tasks } = require('../../assets/js/focus.js');
const enfoque = { C10: ['contenido', 'x'], C04: ['desarrollo', 'y'], C08: ['diseno', 'z'], C06: ['contenido', 'w'] };
const checks = [
  { id: 'C10', estado: 'AVISO', severidad: 'ALTO' },
  { id: 'C04', estado: 'NO_CUMPLE', severidad: 'CRITICO' },
  { id: 'C06', estado: 'NO_CUMPLE', severidad: 'ALTO' },
  { id: 'C08', estado: 'CUMPLE', severidad: 'CRITICO' },
  { id: 'C99', estado: 'NO_CUMPLE', severidad: 'BAJO', aplica: false }
];
const t = tasks(checks, [{ id: 'C06' }, { id: 'C04' }], enfoque);
assert.deepStrictEqual(t.todos.map((x) => x.check.id), ['C06', 'C04', 'C10'], 'fallos antes que avisos, por prioridad; sin CUMPLE ni lo que no aplica');
assert.deepStrictEqual(t.porEquipo.contenido.map((x) => x.check.id), ['C06', 'C10']);
assert.deepStrictEqual(t.porEquipo.desarrollo.map((x) => x.check.id), ['C04']);
assert.strictEqual(t.porEquipo.diseno, undefined);
assert.strictEqual(t.todos[0].porque, 'w');
console.log('focus_test.js: OK');
