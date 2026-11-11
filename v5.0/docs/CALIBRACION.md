# Calibración y normalización de las notas

Estado a 11/11/2026. Propuesta aprobada por el grupo: normalizar las tres áreas y calibrarlas frente a la realidad y
a herramientas del sector, con GEO como prioridad de la empresa.

## 1. Normalización (aplicada, versión n1)

Cada área resta Σ(w × P × reparto) multiplicado por un factor:

```
factor del área = referencia / resta máxima del área
nota del área   = máx(0, 100 − Σ(w × P × reparto) × factor)
```

| Área | Resta máxima (catálogo) | Referencia n1 | Factor |
|---|---|---|---|
| SEO | 73,1 | 60 | 0,82 |
| GEO | 45,5 | 60 | 1,32 |
| UX | 61,4 | 60 | 0,98 |

- **Resta máxima:** lo que restaría el área si todas sus comprobaciones fallaran una vez con su gravedad por defecto
  (C29 y C31 solo avisan y no cuentan). `tests/scorer_test.php` comprueba que `config/normalizacion.php` coincide con
  el catálogo: si se añade o cambia una comprobación, la prueba falla y hay que recalcularla con
  `php tests/calibracion.php --maximos`.
- **Referencia 60:** punto de partida (la media de las tres restas máximas). Con ella, si todo falla una vez, las tres
  áreas quedan en 40. La calibración puede ajustarla por área.
- **Escala original:** `Scorer::raw()` calcula sin normalizar (pruebas del cálculo base y comparaciones).
- **Prueba obligatoria:** 2 críticos distintos + 1 alto ×5 da 49,1 en la escala original y, con n1, SEO 58,2,
  GEO 32,9 y UX 50,3.
- **Versión:** la puntuación incluye `normalizacion.version` y los factores. Las notas guardadas en el historial antes
  de n1 no son comparables con las nuevas.

No cambian los estados, las gravedades, el reparto, el orden de las prioridades ni la lista de fallos.

## 2. Calibración frente a herramientas del sector

| Área del Radar | Referencia externa | Por qué |
|---|---|---|
| SEO | Lighthouse, categoría SEO | Es la herramienta de Google que usan PageSpeed Insights y la mayoría de auditores. |
| UX | Lighthouse, categoría Accesibilidad | Pondera sus auditorías según el impacto de axe-core. |
| GEO | **No hay referencia externa** | Ninguna herramienta pública y reproducible mide la preparación para buscadores con IA. Se calibra con evaluadores (apartado 3). |

Procedimiento (`tests/calibracion.php`, con internet, fuera de `tests/run.php`):

1. `--medir tests/calibracion/corpus.txt salida.json --clave`: analiza cada URL con el Radar (escala original, con C08
   y C14 de PageSpeed) y guarda las categorías de Lighthouse. La clave de PageSpeed se toma de la configuración del
   servidor y nunca se imprime ni se guarda.
2. `--ajustar salida.json`: para SEO y UX calcula la correlación de Spearman con Lighthouse, el error medio con la
   referencia 60 y la referencia que minimiza el error medio.

Criterios de aceptación (fijados antes de medir):

- Spearman ≥ 0,7: el Radar ordena las webs como la referencia. Si no se alcanza, **no se ajusta la referencia**:
  el problema es de qué se mide, no de la escala, y se revisan gravedades o comprobaciones.
- Error medio ≤ 10 puntos con la referencia ajustada.
- La referencia ajustada solo se aplica si mejora el error en al menos 3 puntos frente a 60 (evita sobreajustar a un
  corpus de 30 URLs).

Limitación conocida: Lighthouse no mide lo mismo que el Radar (por ejemplo, el Radar comprueba robots.txt para bots de
IA y Lighthouse no). Se busca coherencia de escala y de orden, no notas idénticas.

## 3. GEO: calibración con evaluadores

Sin herramienta de referencia, GEO mantiene la referencia 60 hasta que haya valoraciones humanas:

- 2 o 3 personas puntúan a ciegas de 0 a 100 la preparación para IA de las URLs del corpus y señalan los 3 problemas
  principales.
- Deben coincidir entre sí (Spearman ≥ 0,7) antes de comparar con el Radar.
- Mismos criterios de aceptación que en el apartado 2.

## 4. Corpus

`tests/calibracion/corpus.txt`: 30 portadas públicas de empresas, administraciones y medios en España.
Supuesto: representan el tipo de web que se analizará. Conviene sustituir parte por webs del sector de los clientes y
añadir páginas interiores.

## 5. Estado

- [x] Normalización n1 aplicada y probada.
- [x] Herramienta de calibración y corpus.
- [ ] Medición del corpus con el Radar: en curso (sin Lighthouse, se repetirá con `--clave` cuando PageSpeed responda).
- [ ] Medición con Lighthouse: pendiente. PageSpeed sin clave devuelve error de cuota (`PSI_QUOTA`) y con la
      configuración local también; hay que revisar la clave de PageSpeed del servidor o su cuota.
- [ ] Ajuste de SEO y UX con los criterios del apartado 2.
- [ ] Valoraciones humanas para GEO.
