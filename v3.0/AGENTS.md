# AGENTS.md — reglas para agentes de código (Codex y otros)

> Copia de las reglas de `CLAUDE.md`. Si cambian, actualizar los dos archivos.


Herramienta web interna en PHP que analiza una URL y devuelve un informe de SEO,
preparación para IA y accesibilidad. Desarrollo en WAMP; despliegue posterior en el
servidor de la empresa. Se trabaja por fases: al terminar cada una, verificar, ejecutar
pruebas, commit y esperar confirmación antes de seguir.

Convenciones: comentarios en español; variables y funciones en inglés; textos de la
interfaz en español. Compatibilidad PHP 8.0 (sin enums, readonly ni nada de 8.1+) hasta
conocer la versión del servidor.

## RESTRICCIONES

- Nunca afirmar que una web «cumple WCAG» ni «cumple la ley». Si no hay fallos, decir «sin errores detectables automáticamente».
- No inventar datos ni evidencias; mostrar solo lo extraído.
- Separar lo medido de lo recomendado; las recomendaciones no penalizan.
- Sin cuentas, login, cookies propias ni analítica. Única base de datos: historial de análisis en MySQL
  (Fase 4, aprobado por el grupo): URL, fecha, notas y fallos por gravedad. v3.0 (decisión del grupo del 9/11/2026):
  también el informe completo, con evidencias y extractos de la web, para volver a verlo desde el historial; lo guarda
  siempre el servidor con datos que ha calculado él. Todo se borra a los 12 meses. Desactivado si no hay `historial.dsn`.
- Una sola URL por petición. v3.0: el «modo sitio» analiza una MUESTRA de páginas (máx. `site_max_pages`), cada una en su propia petición firmada; no rastrea el dominio entero. No analizar backlinks, duplicados ni la cita real por motores de IA.
- Señalar siempre qué requiere revisión manual: calidad del ALT, orden real del teclado, contraste sobre imágenes y calidad de subtítulos.

## PUNTUACIÓN

- Estados permitidos: CUMPLE, NO_CUMPLE, AVISO, SIN_DATOS. Solo NO_CUMPLE penaliza. Cada comprobación se evalúa una sola vez.
- Cada área (SEO, Preparación para IA, Accesibilidad) parte de 100.
- Penalización por gravedad (P): Crítico 15, Alto 8, Medio 4, Bajo 1.
- Puntuación del área = máx(0, 100 − Σ(w × P)), con w = 1 + ln(k), donde k = número de veces que aparece el mismo fallo en la URL.
- Un fallo resta una sola vez; su penalización se reparte entre áreas con la matriz de `config/weights.php`.
- Matriz (SEO / IA / Accesibilidad), aprobada por el grupo el 9/11/2026:
  C01 .7/.3/0; C02 .4/.6/0; C03 .4/.6/0; C04 0/0/1; C05 .3/.3/.4; C06 .2/.2/.6; C07 .4/0/.6;
  C08 0/0/1; C09 .5/0/.5; C10 .8/0/.2; C11 .3/.3/.4; C12 1/0/0; C13 1/0/0; C14 1/0/0; C15 1/0/0.
  Fase 8: C16 .7/.3/0; C18 .6/.4/0; C20 .7/.3/0.
  v3.0 (traídas de la v1.0): C21 0/0/1; C22 0/0/1; C23 0/0/1; C24 0/0/1.
- Nota global = 0,35 × SEO + 0,30 × IA + 0,35 × Accesibilidad.
- Si un área baja de 50 o hay al menos dos fallos críticos: «Riesgo alto: revisar de inmediato».
- SIN_DATOS y comprobaciones que no aplican no penalizan y se muestran aparte. Si más de la mitad de las comprobaciones de un área son SIN_DATOS, la nota se marca como incompleta.
- El área de IA se llama «Preparación para IA».
- Prueba obligatoria: 2 fallos críticos distintos + 1 fallo alto repetido 5 veces ⇒ área de 49,1.

## REGLAS DE CALIDAD

- Una URL real produce un informe completo sin intervención manual.
- Se ve y funciona bien en móvil, tableta y escritorio (mobile first).
- La propia herramienta supera sus comprobaciones de accesibilidad: contraste, etiquetas, orden de teclado y foco visible.
- Todas las pruebas de `tests/run.php` pasan (sin necesidad de internet).
- Ningún texto del informe afirma cumplimiento legal ni WCAG.
- Todo lo que se imprima (URL, extractos, textos de la web analizada) se escapa con `htmlspecialchars`.
- Seguridad: solo http/https; rechazar IPs privadas/reservadas (`filter_var`); fijar IP con `CURLOPT_RESOLVE`; redirecciones manuales revalidadas (máx. 5); 10 s de espera; 2 MB de respuesta; límite de uso por IP en `storage/`; no guardar análisis salvo el historial de notas.
- User-Agent propio e identificable. 403, captcha o login se informan como bloqueo, no como fallo del sitio.
- Sin framework, sin Composer, sin librerías ni CDN: PHP estándar con cURL, DOM, OpenSSL y mbstring.
- Cada comprobación es una clase independiente en `src/Checks/` que devuelve: id, pilares, estado, severidad, valor_medido, umbral, criterio, evidencia, recomendacion, impacto, esfuerzo, requiere_revision_manual. Textos de recomendación en `lang/es.php`.
- Marcar siempre lo supuesto y lo desconocido; ante ambigüedad que cambie el resultado, preguntar.
