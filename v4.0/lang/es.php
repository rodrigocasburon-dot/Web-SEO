<?php
// Textos en español. Las recomendaciones son un PRIMER BORRADOR para que el equipo las revise.
// Ningún texto debe afirmar cumplimiento de WCAG ni de ninguna ley.
return [
    'areas' => [
        'seo' => 'SEO',
        // v4 (10/11/2026): mismas áreas y pesos, con otro nombre
        'ia' => 'GEO',
        'accesibilidad' => 'UX',
    ],
    'estados' => [
        'CUMPLE' => 'Sin errores detectables automáticamente',
        'NO_CUMPLE' => 'Fallo',
        'AVISO' => 'Aviso',
        'SIN_DATOS' => 'Sin datos',
    ],
    // Texto fijo y siempre visible
    'limitaciones' => 'La evaluación es automática y parcial. No sustituye una auditoría manual y no certifica el cumplimiento de WCAG ni de ninguna ley. Cuando no se detectan fallos, el resultado significa «sin errores detectables automáticamente».',
    'revision_manual' => 'Requieren revisión manual, entre otros aspectos: la calidad de los textos alternativos, el orden real de navegación con teclado, el contraste del texto sobre imágenes y la calidad de los subtítulos.',
    // Revisiones manuales RM-01…RM-10 de la lista unificada (v1.0/docs/Lista_unificada.pdf). No puntúan.
    'revisiones_manuales' => [
        'RM-01' => ['Calidad del texto alternativo', 'Que el alt describa bien cada imagen informativa.'],
        'RM-02' => ['Recorrido real con teclado', 'Orden lógico de tabulación y sin trampas de foco.'],
        'RM-03' => ['Foco no tapado', 'Que menús o barras fijas no oculten el elemento que tiene el foco (WCAG 2.4.11).'],
        'RM-04' => ['Zoom al 200 % y adaptación', 'Que nada se rompa al ampliar ni en pantallas estrechas.'],
        'RM-05' => ['Mensajes de error en formularios', 'Que los errores se expliquen con texto, no solo con color.'],
        'RM-06' => ['Alternativas al arrastre', 'Que carruseles y gestos tengan botones equivalentes.'],
        'RM-07' => ['Calidad de los subtítulos', 'Que sean correctos y estén sincronizados.'],
        'RM-08' => ['Rechazar cookies', 'Que rechazar sea tan fácil como aceptar.'],
        'RM-09' => ['Intención de búsqueda y calidad del contenido', 'Que la página responda a lo que busca la gente.'],
        'RM-10' => ['Prueba con lector de pantalla', 'Con NVDA o VoiceOver y, si es posible, con personas usuarias reales.'],
    ],
    'riesgo_alto' => 'Riesgo alto: revisar de inmediato',
    'nota_incompleta' => 'Nota incompleta: más de la mitad de las comprobaciones de esta área no tienen datos.',
    // Glosario del informe (orden alfabético). Definiciones breves; ninguna afirma cumplimiento.
    'glosario' => [
        'Accesibilidad' => 'Posibilidad de que cualquier persona, incluidas las que tienen alguna discapacidad, perciba, entienda y use la página, también con tecnologías de apoyo como los lectores de pantalla.',
        'Aviso' => 'Resultado mejorable o que conviene confirmar. No resta puntos.',
        'Canonical (URL canónica)' => 'Etiqueta link rel="canonical" que indica a los buscadores la versión preferida de una página cuando varias URL muestran el mismo contenido.',
        'Contenido mixto' => 'Recursos que se cargan por http en una página servida por https. Los navegadores bloquean los activos (scripts, hojas de estilo, iframes) y piden por https o bloquean los pasivos (imágenes, audio, vídeo).',
        'Contraste' =>'Relación de luminosidad entre el texto y su fondo, expresada como n:1. Se recomienda al menos 4,5:1 para texto normal y 3:1 para texto grande.',
        'Core Web Vitals' => 'Métricas de Google sobre la experiencia de uso: LCP (tiempo hasta mostrar el elemento principal), INP (rapidez de respuesta a las interacciones) y CLS (desplazamientos inesperados del contenido).',
        'CrUX' => 'Chrome User Experience Report: datos agregados de visitas reales con Chrome que publica Google. Solo existen para páginas con tráfico suficiente.',
        'Datos estructurados (JSON-LD)' => 'Información legible por máquinas, normalmente con el vocabulario de schema.org, que describe el contenido de la página (organización, producto, artículo…).',
        'Enlace roto' => 'Enlace cuyo destino no existe o responde con un error, por ejemplo 404 (página no encontrada).',
        'Esfuerzo' =>'Estimación orientativa del trabajo necesario para corregir el problema: alto, medio o bajo.',
        'Evidencia' => 'Dato o fragmento del HTML que justifica un resultado: selector CSS, extracto y número de línea.',
        'Fallo' => 'La comprobación no se supera. Es el único resultado que resta puntos.',
        'Gravedad' => 'Peso de un fallo en la nota: crítica 15 puntos, alta 8, media 4 y baja 1, multiplicados por 1 + ln(k) cuando el fallo aparece k veces.',
        'Impacto' => 'Estimación orientativa del beneficio de corregir el problema: alto, medio o bajo.',
        'Lighthouse' => 'Herramienta de auditoría de Google que utiliza PageSpeed Insights. Aquí se usa para medir el contraste con un navegador real.',
        'No aplica' => 'La página no tiene elementos de ese tipo (por ejemplo, ninguna imagen). No resta puntos.',
        'noindex' => 'Indicación, en la etiqueta meta robots o en la cabecera X-Robots-Tag, que pide a los buscadores no mostrar la página en sus resultados.',
        'Nombre accesible' => 'Texto con el que las tecnologías de apoyo anuncian un enlace, un botón o un campo de formulario.',
        'Percentil 75 (p75)' => 'Valor que alcanza o mejora el 75 % de las visitas. Es el criterio de Google para evaluar las Core Web Vitals.',
        'Preparación para IA' => 'Grado en que buscadores y asistentes basados en inteligencia artificial pueden rastrear, leer e interpretar la página: acceso de sus rastreadores, contenido en el HTML y datos estructurados. No mide si la citan realmente.',
        'Revisión manual' => 'Aspectos que una herramienta automática no puede valorar y que debe comprobar una persona.',
        'robots.txt' => 'Archivo en la raíz del sitio que indica a los rastreadores qué rutas pueden visitar.',
        'SEO' => 'Optimización para buscadores: prácticas para que la página se rastree, se indexe y se muestre correctamente en los resultados de búsqueda.',
        'Sin datos' => 'No se ha podido medir (bloqueo, recurso que no es HTML, PageSpeed Insights sin datos…). No resta puntos; si afecta a más de la mitad de las comprobaciones de un área, su nota se marca como incompleta.',
        'Sitemap' => 'Archivo, normalmente XML, con la lista de URL de un sitio que se quieren indexar. Se declara en robots.txt.',
        'Texto alternativo (alt)' =>'Atributo de las imágenes con el texto que las sustituye cuando no se ven, por ejemplo con un lector de pantalla. Debe estar vacío (alt="") si la imagen es decorativa.',
        'Viewport' => 'Etiqueta meta que adapta la página al ancho de la pantalla del móvil. No debe impedir la ampliación (zoom).',
        'WCAG' => 'Pautas de Accesibilidad para el Contenido Web del W3C. Esta herramienta detecta solo una parte de los fallos que describen y no certifica su cumplimiento.',
    ],
    // Concuerdan con «gravedad» (gravedad crítica, alta…)
    'severidades' => [
        'CRITICO' => 'Crítica',
        'ALTO' => 'Alta',
        'MEDIO' => 'Media',
        'BAJO' => 'Baja',
    ],
    'niveles' => [
        'ALTO' => 'Alto',
        'MEDIO' => 'Medio',
        'BAJO' => 'Bajo',
    ],
    'checks' => [
        'C01' => [
            'nombre' => 'Estado y HTTPS',
            'descripcion' => 'Comprueba que la URL final responde con código 200 y se sirve por HTTPS.',
            'recomendacion' => 'Sirve la página por HTTPS con un certificado válido, redirige todo el tráfico http a https con una redirección 301 y corrige el error del servidor para que la URL responda con código 200.',
        ],
        'C02' => [
            'nombre' => 'Acceso de bots e indexación',
            'descripcion' => 'Comprueba que robots.txt permite el acceso a Googlebot y a los bots de búsqueda de IA, y que la página no declara noindex.',
            'recomendacion' => 'Permite en robots.txt el acceso de Googlebot y de los rastreadores de búsqueda de IA a esta URL y elimina noindex de la etiqueta meta robots y de la cabecera X-Robots-Tag si la página debe aparecer en buscadores y asistentes de IA.',
            'recomendacion_aviso' => 'Confirma que la configuración es intencionada. Bloquear solo a los rastreadores de entrenamiento de IA es una decisión legítima de política de contenidos y no impide aparecer en buscadores; un noindex intencionado excluye la página de los resultados.',
        ],
        'C03' => [
            'nombre' => 'Contenido en HTML crudo',
            'descripcion' => 'Cuenta las palabras del contenido principal presentes en el HTML inicial, antes de ejecutar JavaScript.',
            'recomendacion' => 'Incluye el contenido principal en el HTML que envía el servidor (renderizado en servidor o generación estática), de modo que rastreadores y asistentes de IA que no ejecutan JavaScript puedan leerlo.',
            'recomendacion_aviso' => 'La página parece construirse con JavaScript en el navegador. Valora el renderizado en servidor, la generación estática o el prerenderizado para que el contenido esté en el HTML inicial.',
        ],
        'C04' => [
            'nombre' => 'Nombres accesibles',
            'descripcion' => 'Comprueba que enlaces, botones y campos de formulario tienen un nombre que las tecnologías de apoyo puedan anunciar.',
            'recomendacion' => 'Da a cada enlace y botón un texto visible o, si solo contiene un icono, un aria-label descriptivo; asocia cada campo de formulario a un elemento label (for/id) o envuélvelo en él. El placeholder no sustituye a la etiqueta.',
        ],
        'C05' => [
            'nombre' => 'Encabezados',
            'descripcion' => 'Comprueba que hay un único h1 y que los niveles de encabezado no se saltan.',
            'recomendacion' => 'Usa un único h1 que describa el contenido de la página y organiza las secciones con h2, h3… sin saltar niveles.',
            'recomendacion_aviso' => 'Revisa la jerarquía de encabezados para que no salte niveles (por ejemplo, de h1 a h3); elige el nivel por estructura, no por el tamaño visual.',
        ],
        'C06' => [
            'nombre' => 'Texto alternativo en imágenes',
            'descripcion' => 'Comprueba que cada imagen tiene atributo alt (vacío si es decorativa). La calidad del texto no se evalúa.',
            'recomendacion' => 'Añade el atributo alt a todas las imágenes: un texto que transmita la misma información si la imagen es informativa, o alt="" si es decorativa.',
        ],
        'C07' => [
            'nombre' => 'Idioma de la página',
            'descripcion' => 'Comprueba que el elemento html declara el idioma con un código válido.',
            'recomendacion' => 'Declara el idioma principal en la etiqueta html con un código válido, por ejemplo <html lang="es"> o <html lang="es-ES">.',
        ],
        'C08' => [
            'nombre' => 'Contraste de color',
            'descripcion' => 'Comprueba con Lighthouse (PageSpeed Insights) que el texto tiene contraste suficiente con su fondo. No evalúa el texto sobre imágenes ni degradados.',
            'recomendacion' => 'Aumenta el contraste entre el texto y su fondo hasta al menos 4,5:1 (3:1 en texto grande) y revisa manualmente el texto situado sobre imágenes o degradados, que la herramienta no puede medir.',
        ],
        'C14' => [
            'nombre' => 'Core Web Vitals (usuarios reales)',
            'descripcion' => 'Evalúa LCP, INP y CLS de usuarios reales de Chrome (percentil 75) para esta URL, según PageSpeed Insights.',
            'recomendacion' => 'Mejora la métrica deficiente: para LCP, optimiza el elemento principal (imagen o texto) y el tiempo de respuesta del servidor; para INP, reduce el JavaScript que bloquea la interacción; para CLS, reserva espacio a imágenes, anuncios e incrustaciones.',
            'recomendacion_aviso' => 'Revisa las métricas mejorables con el informe de PageSpeed Insights y prioriza la que más se aleje del umbral «bueno».',
        ],
        'C09' => [
            'nombre' => 'Zoom y adaptación a móvil',
            'descripcion' => 'Comprueba que existe la meta viewport y que no impide ampliar la página.',
            'recomendacion' => 'Incluye <meta name="viewport" content="width=device-width, initial-scale=1"> y elimina user-scalable=no para no impedir el zoom.',
            'recomendacion_aviso' => 'Usa width=device-width y evita maximum-scale inferior a 2, que limita la ampliación en algunos navegadores.',
        ],
        'C10' => [
            'nombre' => 'Etiqueta title',
            'descripcion' => 'Comprueba que hay un único title no vacío y revisa su longitud.',
            'recomendacion' => 'Define un único elemento title que describa la página de forma específica.',
            'recomendacion_aviso' => 'Ajusta el title a una longitud de entre 35 y 60 caracteres, con lo más relevante al principio, para que no se corte en los resultados de búsqueda.',
        ],
        'C11' => [
            'nombre' => 'HTML semántico',
            'descripcion' => 'Comprueba roles ARIA, que haya un único main y el uso de onclick en elementos no interactivos.',
            'recomendacion' => 'Usa solo roles ARIA definidos en la especificación (o elimina el atributo role si no es necesario).',
            'recomendacion_aviso' => 'Usa un único elemento main para el contenido principal: añádelo si falta y, si hay copias por dispositivo, marca con el atributo hidden las que no se muestran o deja una sola. Para las acciones, usa button o a en lugar de onclick sobre div o span, que no son accesibles con teclado por defecto.',
        ],
        'C12' => [
            'nombre' => 'URL canónica',
            'descripcion' => 'Comprueba que existe link rel="canonical" y que es coherente con la URL analizada.',
            'recomendacion' => 'Añade <link rel="canonical" href="..."> con la URL absoluta preferida de esta página. Si apunta a otra página a propósito, confirma que el contenido es realmente equivalente.',
            'recomendacion_aviso' => 'Unifica el canonical con la versión de la URL que quieres indexar (esquema, www y parámetros) y redirige las demás variantes a ella.',
        ],
        'C13' => [
            'nombre' => 'Datos estructurados',
            'descripcion' => 'Comprueba que hay JSON-LD con sintaxis válida y @type definido.',
            'recomendacion' => 'Añade datos estructurados JSON-LD con sintaxis válida y @type definido (por ejemplo Organization, WebPage, Article o Product) y revísalos con un validador de datos estructurados.',
        ],
        'C15' => [
            'nombre' => 'Meta description',
            'descripcion' => 'Comprueba que existe una meta description y revisa su longitud.',
            'recomendacion' => 'Redacta una meta description específica para la página que resuma su contenido.',
            'recomendacion_aviso' => 'Ajusta la meta description a una longitud de entre 120 y 155 caracteres para que no se corte en los resultados de búsqueda.',
        ],
        'C16' => [
            'nombre' => 'Enlaces rotos',
            'descripcion' => 'Comprueba hasta 20 enlaces de la página y detecta los que responden con error 4xx o apuntan a un dominio inexistente.',
            'recomendacion' => 'Corrige o elimina los enlaces rotos: actualiza la dirección si el contenido se ha movido (y, si el destino es de tu sitio, añade una redirección 301) o quita el enlace si el contenido ya no existe.',
            'recomendacion_aviso' => 'Revisa a mano los enlaces que no se han podido confirmar: pueden ser errores temporales del servidor de destino o enlaces que ya no funcionan.',
        ],
        'C18' => [
            'nombre' => 'Sitemap',
            'descripcion' => 'Comprueba que hay un sitemap accesible, declarado en robots.txt o en /sitemap.xml, y que incluye la URL analizada.',
            'recomendacion' => 'Publica un sitemap XML que incluya esta URL con su dirección canónica y decláralo en robots.txt con una línea «Sitemap:» seguida de su URL absoluta.',
            'recomendacion_aviso' => 'Usa en el sitemap exactamente la misma URL que la página (esquema, www y barra final). Si el sitemap es muy grande y no se ha podido revisar entero, comprueba a mano que incluye esta URL.',
        ],
        'C20' => [
            'nombre' => 'Certificado y contenido mixto',
            'descripcion' => 'En páginas HTTPS, comprueba que el certificado se ha verificado y no caduca pronto, y que el HTML no carga recursos por http (contenido mixto).',
            'recomendacion' => 'Carga por https todos los recursos activos (scripts, hojas de estilo, iframes) y envía los formularios a direcciones https: cambia las URL http:// por https:// o por rutas relativas. Como refuerzo, añade la directiva upgrade-insecure-requests a la cabecera Content-Security-Policy.',
            'recomendacion_aviso' => 'Cambia a https las URL de imágenes, audio y vídeo, y renueva el certificado antes de que caduque, preferiblemente con renovación automática.',
        ],
        // v3.0: traídas de la v1.0
        'C21' => [
            'nombre' => 'Título en iframes',
            'descripcion' => 'Comprueba que cada iframe visible (mapas, vídeos, formularios incrustados) tiene title para que un lector de pantalla diga qué contiene.',
            'recomendacion' => 'Añade a cada iframe un atributo title que describa su contenido, por ejemplo title="Mapa de cómo llegar".',
        ],
        'C22' => [
            'nombre' => 'IDs repetidos',
            'descripcion' => 'Comprueba que ningún id se repite, sobre todo los que usan etiquetas de formulario, atributos ARIA o enlaces internos.',
            'recomendacion' => 'Haz único cada id que se usa en label for, aria-labelledby, aria-describedby, aria-controls o enlaces #id: con dos iguales, la relación apunta al primero.',
            'recomendacion_aviso' => 'Haz único cada id; aunque ahora nada los use, una relación futura apuntaría al elemento equivocado.',
        ],
        'C23' => [
            'nombre' => 'Subtítulos en vídeo',
            'descripcion' => 'Comprueba que cada etiqueta video declara una pista de subtítulos (track). Los vídeos de YouTube o Vimeo incrustados no se pueden comprobar.',
            'recomendacion' => 'Añade subtítulos a cada vídeo con <track kind="captions" src="subtitulos.vtt" srclang="es" label="Español">.',
            'recomendacion_aviso' => 'Si el vídeo de fondo tiene sonido o transmite información, añádele subtítulos; si es solo decorativo, puede quedarse así.',
        ],
        'C24' => [
            'nombre' => 'Reproducción automática y movimiento',
            'descripcion' => 'Busca audio o vídeo que empiezan solos con sonido y elementos marquee o blink.',
            'recomendacion' => 'Evita que el audio empiece solo; si lo hace, que dure menos de 3 segundos o que haya un botón visible para pararlo. Sustituye marquee y blink por contenido estático.',
            'recomendacion_aviso' => 'Evita que el audio empiece solo; si lo hace, que dure menos de 3 segundos o que haya un botón visible para pararlo. Sustituye marquee y blink por contenido estático.',
        ],
    ],

    // Función 09: fragmentos de corrección (antes / después). Se rellenan con el código real de la evidencia
    // (src/FixSnippets.php). Los textos en MAYÚSCULAS son marcadores que debe sustituir el equipo.
    // {texto}, {idioma} y {url} se sustituyen al generar el fragmento.
    'correcciones' => [
        'borrador' => 'Primer borrador generado automáticamente a partir del código encontrado en la página: debe revisarlo el equipo antes de aplicarlo.',
        'C04' => [
            'atributo' => 'aria-label',
            'textos' => ['enlace' => 'NOMBRE DEL ENLACE', 'boton' => 'NOMBRE DEL BOTÓN', 'campo' => 'NOMBRE DEL CAMPO'],
            'nota' => 'Sustituye el texto en mayúsculas por un nombre que diga para qué sirve el elemento. En los campos de formulario es preferible una etiqueta <label> visible.',
        ],
        'C06' => [
            'atributo' => 'alt',
            'texto' => 'DESCRIPCIÓN DE LA IMAGEN',
            'nota' => 'Describe el contenido o la función de la imagen; si es decorativa, deja el atributo vacío: alt="".',
        ],
        'C07' => [
            'atributo' => 'lang',
            'idioma' => 'IDIOMA',
            'nota' => 'Sustituye IDIOMA por el código del idioma del contenido (por ejemplo es, es-ES o en).',
            'nota_formato' => 'Se corrige el formato del código de idioma: guion en lugar de guion bajo.',
        ],
        'C09' => [
            'nuevo' => '<meta name="viewport" content="width=device-width, initial-scale=1">',
            'nota' => 'Va dentro de <head>. No impidas el zoom: sin user-scalable=no ni maximum-scale menor que 2.',
        ],
        'C10' => [
            'nuevo' => '<title>TÍTULO DE LA PÁGINA (35-60 CARACTERES)</title>',
            'nota' => 'Va dentro de <head>, una sola vez. Escribe un título que identifique la página.',
        ],
        'C12' => [
            'nuevo' => '<link rel="canonical" href="{url}">',
            'nota' => 'Va dentro de <head>. Úsalo solo si esta URL es la versión preferida de la página; si no, pon la URL de la versión preferida.',
        ],
        'C15' => [
            'nuevo' => '<meta name="description" content="DESCRIPCIÓN DE LA PÁGINA (120-155 CARACTERES)">',
            'texto' => 'DESCRIPCIÓN DE LA PÁGINA (120-155 CARACTERES)',
            'nota' => 'Va dentro de <head>. Resume el contenido de esta página en concreto.',
        ],
        'C20' => [
            'nota' => 'Comprueba antes que el recurso se sirve por https en esa misma dirección; si no, alójalo en el propio sitio o usa una ruta relativa.',
        ],
    ],

    // v4 · Vista por departamento (decisión del 10/11/2026). No cambia ninguna nota: solo qué se enseña primero y cómo.
    'departamentos' => [
        'general' => [
            'nombre' => 'Informe completo',
            'intro' => 'Todo el informe, sin filtrar. Elige un departamento para ver solo lo que le toca, explicado a su medida.',
            'responsable' => null,
        ],
        'marketing' => [
            'nombre' => 'Marketing y contenidos',
            'intro' => 'Lo que afecta a cómo te encuentran Google y los asistentes de IA, sin tecnicismos. «Te toca» es lo que se arregla desde el gestor de contenidos (textos, títulos, imágenes, vídeos); lo demás, pásaselo al equipo indicado.',
            'responsable' => 'contenido',
        ],
        'web' => [
            'nombre' => 'Desarrollo web',
            'intro' => 'Lo técnico: servidor, código, rendimiento y accesibilidad del HTML. Cada punto tiene su evidencia y su corrección en el informe completo, más abajo.',
            'responsable' => 'desarrollo',
        ],
        'diseno' => [
            'nombre' => 'Diseño y UX',
            'intro' => 'Lo que notan las personas al usar la web: contraste, móvil, zoom y cosas que se mueven solas.',
            'responsable' => 'diseno',
        ],
        'direccion' => [
            'nombre' => 'Dirección',
            'intro' => 'La foto general en un minuto: la nota, el riesgo, las cinco cosas más importantes y a qué equipo le toca cada una.',
            'responsable' => null,
        ],
    ],
    'responsables' => [
        'contenido' => 'Marketing y contenidos',
        'desarrollo' => 'Desarrollo web',
        'diseno' => 'Diseño y UX',
    ],
    // Quién suele arreglarlo y por qué importa, en lenguaje llano (para Marketing y Dirección)
    'enfoque' => [
        'C01' => ['desarrollo', 'Si la página da error o no usa HTTPS, Google la muestra peor y el navegador avisa de que no es segura: se pierden visitas y confianza.'],
        'C02' => ['desarrollo', 'Si se bloquea a Google o a los bots de IA, la página no sale en sus resultados ni en sus respuestas.'],
        'C03' => ['desarrollo', 'Si el texto solo aparece al ejecutar JavaScript, algunos buscadores y casi todos los asistentes de IA no lo leen.'],
        'C04' => ['desarrollo', 'Botones y enlaces sin nombre son invisibles para quien usa un lector de pantalla: no puede navegar, pedir información ni comprar.'],
        'C05' => ['contenido', 'Los títulos de sección (H1, H2…) ordenan el texto: ayudan a Google, a la IA y a las personas a entender de qué va la página.'],
        'C06' => ['contenido', 'Las imágenes sin texto alternativo no se entienden con lector de pantalla y no aparecen en la búsqueda de imágenes.'],
        'C07' => ['desarrollo', 'Indicar el idioma ayuda a Google a enseñar la página a quien busca en ese idioma y a los lectores de pantalla a pronunciarla bien.'],
        'C08' => ['diseno', 'El texto con poco contraste cuesta leerlo, sobre todo en el móvil al sol o con la vista cansada.'],
        'C09' => ['diseno', 'Si no se adapta al móvil o no deja ampliar, lo tienen difícil la mayoría de las visitas, que llegan desde el móvil.'],
        'C10' => ['contenido', 'El title es el titular azul que sale en Google: decide en buena parte si la gente hace clic.'],
        'C11' => ['desarrollo', 'Una estructura clara (cabecera, menú, contenido principal) ayuda a la IA y a los lectores de pantalla a encontrar lo importante.'],
        'C12' => ['desarrollo', 'Si Google no sabe cuál es la versión buena de una página, puede repartir su posición entre copias.'],
        'C13' => ['desarrollo', 'Los datos estructurados permiten resultados enriquecidos (estrellas, precios, preguntas) y que la IA entienda quién eres y qué ofreces.'],
        'C14' => ['desarrollo', 'Una página lenta o que se mueve mientras carga hace que la gente se vaya, y Google lo tiene en cuenta.'],
        'C15' => ['contenido', 'La meta description es el texto bajo el titular en Google: si falta, Google improvisa uno que suele atraer menos clics.'],
        'C16' => ['contenido', 'Los enlaces rotos frustran a las visitas y hacen perder valor en Google.'],
        'C18' => ['desarrollo', 'El sitemap le dice a Google qué páginas existen; sin él, las nuevas tardan más en aparecer.'],
        'C20' => ['desarrollo', 'Un certificado caducado o recursos sin HTTPS hacen que el navegador muestre avisos de inseguridad.'],
        'C21' => ['desarrollo', 'Los vídeos o mapas incrustados sin título no se entienden con lector de pantalla.'],
        'C22' => ['desarrollo', 'Identificadores repetidos en el código rompen formularios y la navegación con lector de pantalla.'],
        'C23' => ['contenido', 'Los vídeos sin subtítulos dejan fuera a personas sordas y a quien los ve sin sonido, que en el móvil es casi todo el mundo.'],
        'C24' => ['diseno', 'Lo que se mueve o suena solo distrae, molesta y a algunas personas les puede marear.'],
    ],
];
