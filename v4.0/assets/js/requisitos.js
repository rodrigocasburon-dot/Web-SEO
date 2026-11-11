/* Requisitos (requisitos.php) · descarga en PDF de la lista de comprobaciones, con assets/js/pdf.js.
   Los datos van en la propia página (#requisitos-datos), calculados por el servidor desde src/Checks/. */
(function () {
  'use strict';
  var button = document.getElementById('requisitos-pdf');
  var status = document.getElementById('requisitos-pdf-estado');
  var source = document.getElementById('requisitos-datos');
  if (!button || !source || !window.RadarPdf) return;
  var data = JSON.parse(source.textContent);

  button.addEventListener('click', function () {
    var label = button.textContent;
    button.disabled = true;
    button.textContent = 'Generando PDF…';
    window.RadarPdf.create({
      eyebrow: 'Radar web · Requisitos',
      titulo: 'Requisitos que se comprueban',
      subtitulo: ['Las ' + data.total + ' comprobaciones automáticas de cada página, agrupadas por el área a la que más restan.',
        'Documento generado el ' + new Date().toLocaleDateString('es-ES', { day: 'numeric', month: 'long', year: 'numeric' }) + '.']
    }).then(function (doc) {
      doc.h2('Cómo puntúan');
      data.puntuacion.forEach(function (t) { doc.p('• ' + t); });
      data.areas.forEach(function (a) {
        doc.h2(a.nombre + ' (' + a.puntos.length + ')');
        doc.p(a.intro, { size: 8.5, color: window.RadarPdf.colors.gris });
        a.puntos.forEach(function (x) { doc.item(x); });
      });
      doc.save('proconsi-radar-requisitos-' + new Date().toISOString().slice(0, 10) + '.pdf');
      status.textContent = 'PDF descargado.';
    }).catch(function () {
      status.textContent = 'No se ha podido generar el PDF. Inténtalo de nuevo.';
    }).then(function () {
      button.disabled = false;
      button.textContent = label;
    });
  });
})();
