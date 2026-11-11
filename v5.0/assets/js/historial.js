/**
 * Historial · comparar dos auditorías. El botón «Comparar» solo se activa con dos análisis marcados; el texto de
 * ayuda dice cuántos faltan (se anuncia: role="status"). Con alguna marca, la barra queda fija al pie de la pantalla
 * para no tener que volver a subir. Sin JavaScript el formulario sigue funcionando y index.php avisa si no hay dos.
 */
(function () {
  'use strict';
  var form = document.getElementById('comparar');
  if (!form) return;
  var button = form.querySelector('button[type="submit"]');
  var help = document.getElementById('comparar-help');
  var initial = help.textContent;
  var boxes = Array.prototype.slice.call(document.querySelectorAll('input[form="comparar"][name="comparar[]"]'));

  function checked() {
    return boxes.filter(function (b) { return b.checked; });
  }

  function sync() {
    var n = checked().length;
    button.disabled = n !== 2;
    button.setAttribute('aria-disabled', n !== 2 ? 'true' : 'false');
    form.classList.toggle('is-active', n > 0);
    help.textContent = n === 0 ? initial
      : n === 1 ? 'Has marcado un análisis. Marca otro para poder comparar.'
      : 'Dos análisis marcados: pulsa «Comparar los dos marcados».';
  }

  boxes.forEach(function (b) {
    b.addEventListener('change', function () {
      // Como mucho dos: al marcar un tercero se desmarca el primero que se marcó
      if (b.checked) {
        var on = checked();
        if (on.length > 2) {
          var oldest = on.filter(function (x) { return x !== b; }).sort(function (x, y) { return x._t - y._t; })[0];
          oldest.checked = false;
        }
        b._t = Date.now();
      }
      sync();
    });
  });

  form.addEventListener('submit', function (ev) {
    if (checked().length !== 2) {
      ev.preventDefault();
      sync();
    }
  });

  sync();
})();
