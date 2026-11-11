-- Migración OPCIONAL: columnas de fiabilidad en el historial (indicador de fiabilidad de las notas).
-- Ejecutar UNA vez, a mano, desde phpMyAdmin o la consola de MySQL, sobre una base radar_web ya existente.
-- La herramienta NO la ejecuta sola. Sin estas columnas todo funciona igual: la fiabilidad se sigue
-- calculando y mostrando en el informe (y queda dentro del informe guardado), pero no aparece en la
-- lista del historial ni en la tabla de análisis anteriores.
-- Las filas ya guardadas quedan con NULL (se muestran sin indicador).

ALTER TABLE radar_web.historial
    ADD COLUMN fiab_estado VARCHAR(10) NULL,
    ADD COLUMN fiab_evaluados SMALLINT UNSIGNED NULL,
    ADD COLUMN fiab_posibles SMALLINT UNSIGNED NULL;

-- Para deshacerla:
-- ALTER TABLE radar_web.historial DROP COLUMN fiab_estado, DROP COLUMN fiab_evaluados, DROP COLUMN fiab_posibles;
