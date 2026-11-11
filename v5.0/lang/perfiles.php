<?php
/**
 * Textos de los perfiles de lectura (config/perfiles.php). Plantillas fijas: no se generan con IA ni se piden
 * a ningún servicio. Cambian la explicación, nunca la nota ni el estado. Tratamiento neutro (sin tuteo).
 *
 * Por punto:
 * - marketing: significa, importa, hacer (sin jerga técnica).
 * - direccion: significa, importa (consecuencia para el negocio), hacer (la decisión). El esfuerzo y el impacto
 *   salen de la propia comprobación (estimaciones de borrador), no de aquí.
 * - desarrollo: importa y comprobar. «Qué significa» y «qué hacer» son la descripción y la recomendación
 *   técnicas de lang/es.php; el valor encontrado y el esperado salen del análisis (valor medido y umbral).
 * Si falta una variante, la pantalla usa el texto general y el punto figura en docs/PENDIENTES_PERFILES.md.
 */
return [
    'perfiles' => [
        'marketing' => ['nombre' => 'Marketing', 'descripcion' => 'Visibilidad, captación y confianza'],
        'direccion' => ['nombre' => 'Dirección', 'descripcion' => 'Estado general, riesgos y prioridades'],
        'desarrollo' => ['nombre' => 'Desarrollo web', 'descripcion' => 'Qué corregir, dónde y cómo comprobarlo'],
    ],

    // Respaldo de los puntos GEO (BaseCheck::$respaldo), redactado para cada perfil
    'respaldo' => [
        'oficial' => [
            'marketing' => 'Lo indica el propio buscador o asistente de IA en su documentación.',
            'direccion' => 'Respaldo: documentación oficial del buscador.',
            'desarrollo' => 'Evidencia: documentación oficial (Google, OpenAI, Anthropic o el bot correspondiente).',
        ],
        'experimento' => [
            'marketing' => 'Hay un estudio que lo relaciona con más citas en la IA, pero no es una regla de los buscadores.',
            'direccion' => 'Respaldo: un estudio publicado; no es un requisito oficial.',
            'desarrollo' => 'Evidencia: experimento publicado (estudio controlado); no es un requisito documentado.',
        ],
        'hipotesis' => [
            'marketing' => 'Es una práctica habitual, sin un estudio que la confirme.',
            'direccion' => 'Respaldo: práctica habitual, sin estudio que la confirme.',
            'desarrollo' => 'Evidencia: hipótesis (práctica habitual sin estudio abierto).',
        ],
    ],

    'puntos' => [
        'C01' => [
            'marketing' => [
                'significa' => 'La página da error o no se abre de forma segura (sin el candado de HTTPS).',
                'importa' => 'El navegador avisa de que no es segura y Google la muestra peor: se pierden visitas y confianza.',
                'hacer' => 'Pedir al equipo web que la página cargue sin errores y siempre con HTTPS.',
            ],
            'direccion' => [
                'significa' => 'La página no responde bien o no es segura.',
                'importa' => 'Afecta directamente a la captación y a la imagen de marca: es un punto de entrada que falla.',
                'hacer' => 'Encargar la corrección inmediata al equipo web o al proveedor de alojamiento.',
            ],
            'desarrollo' => [
                'importa' => 'Sin un 200 sobre HTTPS, los rastreadores no indexan bien la URL y el navegador muestra avisos.',
                'comprobar' => 'curl -I https://dominio/ruta (código 200, sin cadenas de redirección) y el candado en el navegador.',
            ],
        ],
        'C02' => [
            'marketing' => [
                'significa' => 'Google o los asistentes de IA tienen prohibido leer esta página, o se les pide que no la muestren.',
                'importa' => 'Si no pueden leerla, la página no aparece en los resultados ni en las respuestas de la IA.',
                'hacer' => 'Confirmar con el equipo web que la página debe ser visible y pedir que se retire el bloqueo.',
            ],
            'direccion' => [
                'significa' => 'La web impide que los buscadores o la IA la lean.',
                'importa' => 'Una página invisible para buscadores no capta tráfico, por buena que sea.',
                'hacer' => 'Decidir qué páginas deben ser públicas y encargar que se desbloqueen.',
            ],
            'desarrollo' => [
                'importa' => 'Un Disallow en robots.txt, un <meta name="robots" content="noindex"> o X-Robots-Tag: noindex excluyen la URL.',
                'comprobar' => 'Revisar /robots.txt por agente, el <meta name="robots"> del HTML y la cabecera X-Robots-Tag (curl -I).',
            ],
        ],
        'C03' => [
            'marketing' => [
                'significa' => 'El texto de la página solo aparece cuando el navegador ejecuta programas; al cargarla, llega casi vacía.',
                'importa' => 'Muchos asistentes de IA y algunos buscadores no ejecutan esos programas y no leen el contenido.',
                'hacer' => 'Pedir al equipo web que el texto principal llegue ya escrito en la página.',
            ],
            'direccion' => [
                'significa' => 'El contenido no es legible para buena parte de los robots que alimentan buscadores e IA.',
                'importa' => 'Reduce la visibilidad en buscadores y la posibilidad de que la IA cite la web.',
                'hacer' => 'Valorar con el equipo técnico un cambio de arquitectura (renderizado en servidor); es una decisión de esfuerzo alto.',
            ],
            'desarrollo' => [
                'importa' => 'Los rastreadores que no ejecutan JavaScript solo ven el HTML inicial; si el contenido se inyecta en cliente, no lo indexan.',
                'comprobar' => 'curl https://dominio/ruta y buscar el texto principal en la respuesta, o «Ver código fuente» (no el inspector).',
            ],
        ],
        'C04' => [
            'marketing' => [
                'significa' => 'Hay botones, enlaces o campos sin nombre: quien usa un lector de pantalla no sabe para qué sirven.',
                'importa' => 'Esas personas no pueden pedir información, registrarse ni comprar: son clientes que se pierden.',
                'hacer' => 'Pedir que cada botón, enlace y campo tenga un texto que diga qué hace.',
            ],
            'direccion' => [
                'significa' => 'Partes de la web no se pueden usar con tecnologías de apoyo.',
                'importa' => 'Excluye a clientes con discapacidad y expone a la empresa a reclamaciones de accesibilidad.',
                'hacer' => 'Incluir la corrección en el próximo ciclo de desarrollo.',
            ],
            'desarrollo' => [
                'importa' => 'Sin nombre accesible (texto, aria-label o <label for>), el lector de pantalla anuncia solo «botón» o «enlace».',
                'comprobar' => 'Panel de accesibilidad del inspector (Accessible name) o un lector de pantalla como NVDA.',
            ],
        ],
        'C05' => [
            'marketing' => [
                'significa' => 'Los títulos y subtítulos de la página no siguen un orden claro.',
                'importa' => 'Un texto bien ordenado se entiende mejor y ayuda a Google y a la IA a saber de qué trata.',
                'hacer' => 'Revisar que haya un título principal y que las secciones tengan subtítulos ordenados.',
            ],
            'direccion' => [
                'significa' => 'La estructura del texto es mejorable.',
                'importa' => 'Influye en la comprensión del contenido por buscadores y lectores.',
                'hacer' => 'Incluirlo en la revisión editorial de contenidos.',
            ],
            'desarrollo' => [
                'importa' => 'Un único <h1> y niveles sin saltos (h2 → h3) permiten navegar por encabezados y entender la jerarquía.',
                'comprobar' => 'Extensión de esquema de encabezados o document.querySelectorAll("h1,h2,h3,h4,h5,h6") en la consola.',
            ],
        ],
        'C06' => [
            'marketing' => [
                'significa' => 'Hay imágenes sin una descripción en texto.',
                'importa' => 'No aparecen en la búsqueda de imágenes y quien no ve la pantalla no sabe qué muestran.',
                'hacer' => 'Escribir una descripción breve para cada imagen que aporte información.',
            ],
            'direccion' => [
                'significa' => 'Las imágenes no tienen texto alternativo.',
                'importa' => 'Se pierde visibilidad en búsquedas de imágenes y accesibilidad.',
                'hacer' => 'Asignar la tarea al equipo de contenidos; el esfuerzo es bajo.',
            ],
            'desarrollo' => [
                'importa' => 'Una <img> sin atributo alt no tiene alternativa textual; las decorativas deben llevar alt="".',
                'comprobar' => 'document.querySelectorAll("img:not([alt])") en la consola; la calidad del alt requiere revisión manual.',
            ],
        ],
        'C07' => [
            'marketing' => [
                'significa' => 'La página no indica en qué idioma está escrita.',
                'importa' => 'Google la muestra mejor a quien busca en ese idioma y los lectores de pantalla la pronuncian bien.',
                'hacer' => 'Pedir al equipo web que declare el idioma de la página.',
            ],
            'direccion' => [
                'significa' => 'Falta un dato técnico básico sobre el idioma.',
                'importa' => 'Impacto moderado en visibilidad y accesibilidad; corrección muy sencilla.',
                'hacer' => 'Incluirlo como corrección rápida.',
            ],
            'desarrollo' => [
                'importa' => 'Sin <html lang="es"> (o el idioma que corresponda), el lector de pantalla usa una voz equivocada.',
                'comprobar' => 'document.documentElement.lang en la consola.',
            ],
        ],
        'C08' => [
            'marketing' => [
                'significa' => 'Parte del texto tiene poco contraste con el fondo.',
                'importa' => 'Cuesta leerlo, sobre todo en el móvil al sol: la gente abandona antes de leer el mensaje.',
                'hacer' => 'Pedir a diseño que oscurezca esos textos o aclare el fondo.',
            ],
            'direccion' => [
                'significa' => 'Hay textos difíciles de leer.',
                'importa' => 'Reduce la eficacia de los mensajes y la accesibilidad de la web.',
                'hacer' => 'Encargar a diseño un ajuste de colores.',
            ],
            'desarrollo' => [
                'importa' => 'Por debajo de 4,5:1 (texto normal) o 3:1 (texto grande), el texto no alcanza el contraste mínimo de referencia.',
                'comprobar' => 'Lighthouse (Accesibilidad) o el selector de color del inspector, que muestra la relación de contraste.',
            ],
        ],
        'C09' => [
            'marketing' => [
                'significa' => 'La página no se adapta bien al móvil o no deja ampliar el texto.',
                'importa' => 'La mayoría de las visitas llegan desde el móvil: si se ve mal, se van.',
                'hacer' => 'Pedir al equipo web que la página se adapte al móvil y permita ampliar.',
            ],
            'direccion' => [
                'significa' => 'La experiencia en móvil es deficiente.',
                'importa' => 'Afecta a la mayoría del tráfico y a la posición en Google.',
                'hacer' => 'Priorizar la corrección; el esfuerzo estimado es bajo.',
            ],
            'desarrollo' => [
                'importa' => 'Sin <meta name="viewport" content="width=device-width, initial-scale=1"> o con user-scalable=no / maximum-scale=1, falla en móvil.',
                'comprobar' => 'Revisar la etiqueta viewport del HTML y probar en el modo de dispositivo del inspector con zoom al 200 %.',
            ],
        ],
        'C10' => [
            'marketing' => [
                'significa' => 'El titular que muestra Google para esta página falta, es demasiado largo o demasiado corto.',
                'importa' => 'Ese titular decide en buena parte si la gente hace clic en el resultado.',
                'hacer' => 'Escribir un titular claro y único, de unos 30 a 60 caracteres, con lo que ofrece la página.',
            ],
            'direccion' => [
                'significa' => 'El título de la página en los buscadores es mejorable.',
                'importa' => 'Influye en cuántas personas eligen la web en los resultados.',
                'hacer' => 'Encargarlo al equipo de contenidos; el esfuerzo es bajo.',
            ],
            'desarrollo' => [
                'importa' => 'El <title> es el titular del resultado y el nombre de la pestaña; vacío o fuera de rango, Google lo reescribe.',
                'comprobar' => 'document.title en la consola o el <title> del código fuente.',
            ],
        ],
        'C11' => [
            'marketing' => [
                'significa' => 'La página no marca bien sus partes (cabecera, menú, contenido principal, pie).',
                'importa' => 'A la IA y a los lectores de pantalla les cuesta encontrar lo importante.',
                'hacer' => 'Pedir al equipo web que revise la estructura de la página.',
            ],
            'direccion' => [
                'significa' => 'La estructura interna de la página es mejorable.',
                'importa' => 'Impacto moderado en accesibilidad y en cómo la interpreta la IA.',
                'hacer' => 'Incluirlo en el mantenimiento técnico.',
            ],
            'desarrollo' => [
                'importa' => 'Elementos <header>, <nav>, <main> (uno visible) y <footer> definen las regiones para tecnologías de apoyo.',
                'comprobar' => 'Árbol de accesibilidad del inspector (landmarks) o document.querySelectorAll("main:not([hidden])").',
            ],
        ],
        'C12' => [
            'marketing' => [
                'significa' => 'La página no indica cuál es su versión principal o indica una dirección incorrecta.',
                'importa' => 'Google puede repartir la posición entre copias de la misma página.',
                'hacer' => 'Pedir al equipo web que marque la dirección principal de cada página.',
            ],
            'direccion' => [
                'significa' => 'Puede haber páginas duplicadas a ojos de Google.',
                'importa' => 'Diluye la visibilidad en buscadores.',
                'hacer' => 'Incluirlo en las correcciones técnicas de SEO.',
            ],
            'desarrollo' => [
                'importa' => 'Sin <link rel="canonical" href="…"> absoluto y coherente, Google elige la URL canónica por su cuenta.',
                'comprobar' => 'document.querySelector("link[rel=canonical]").href y la herramienta de inspección de URL de Search Console.',
            ],
        ],
        'C13' => [
            'marketing' => [
                'significa' => 'La página no incluye datos estructurados (la ficha que explica a Google quién es la empresa y qué ofrece).',
                'importa' => 'Sin ellos no hay resultados enriquecidos y la IA entiende peor la oferta.',
                'hacer' => 'Pedir que se añada la ficha de la organización y, si aplica, de productos o servicios.',
            ],
            'direccion' => [
                'significa' => 'Falta información estructurada para buscadores e IA.',
                'importa' => 'Se pierden resultados enriquecidos que mejoran la visibilidad.',
                'hacer' => 'Valorarlo en el plan de SEO; el esfuerzo es medio.',
            ],
            'desarrollo' => [
                'importa' => 'Bloques <script type="application/ld+json"> válidos de schema.org (Organization, LocalBusiness, Product…) habilitan resultados enriquecidos.',
                'comprobar' => 'Prueba de resultados enriquecidos de Google o el validador de schema.org.',
            ],
        ],
        'C14' => [
            'marketing' => [
                'significa' => 'Según datos de usuarios reales, la página carga despacio o se mueve mientras carga.',
                'importa' => 'Una web lenta hace que la gente se vaya antes de ver la oferta, y Google lo tiene en cuenta.',
                'hacer' => 'Pedir al equipo web un plan para mejorar la velocidad.',
            ],
            'direccion' => [
                'significa' => 'La web es lenta para los usuarios reales.',
                'importa' => 'Afecta a la conversión y a la posición en Google.',
                'hacer' => 'Encargar un estudio de rendimiento; el esfuerzo estimado es alto.',
            ],
            'desarrollo' => [
                'importa' => 'Datos de campo (CrUX): LCP ≤ 2,5 s, INP ≤ 200 ms y CLS ≤ 0,1 en el percentil 75.',
                'comprobar' => 'PageSpeed Insights (sección de datos de usuarios reales) o el informe de Core Web Vitals de Search Console.',
            ],
        ],
        'C15' => [
            'marketing' => [
                'significa' => 'Falta el texto que Google muestra bajo el titular, o tiene una longitud inadecuada.',
                'importa' => 'Si falta, Google improvisa uno que suele atraer menos clics.',
                'hacer' => 'Escribir una descripción atractiva de unos 70 a 160 caracteres.',
            ],
            'direccion' => [
                'significa' => 'Falta la descripción de la página en buscadores.',
                'importa' => 'Impacto menor en la tasa de clics.',
                'hacer' => 'Encargarlo al equipo de contenidos.',
            ],
            'desarrollo' => [
                'importa' => 'La <meta name="description" content="…"> alimenta el fragmento del resultado; no puntúa para posicionar.',
                'comprobar' => 'document.querySelector("meta[name=description]").content en la consola.',
            ],
        ],
        'C16' => [
            'marketing' => [
                'significa' => 'Hay enlaces que llevan a páginas que no existen o dan error.',
                'importa' => 'Frustran a las visitas y dan imagen de descuido.',
                'hacer' => 'Corregir o quitar los enlaces rotos.',
            ],
            'direccion' => [
                'significa' => 'La web tiene enlaces rotos.',
                'importa' => 'Afecta a la imagen y a la experiencia de cliente.',
                'hacer' => 'Encargar una revisión de enlaces; el esfuerzo es bajo.',
            ],
            'desarrollo' => [
                'importa' => 'Los enlaces internos que responden 4xx o 5xx desperdician rastreo y rompen la navegación.',
                'comprobar' => 'curl -I a cada destino listado en la evidencia o un rastreador de enlaces.',
            ],
        ],
        'C18' => [
            'marketing' => [
                'significa' => 'No hay un mapa del sitio para los buscadores, o está incompleto.',
                'importa' => 'Las páginas nuevas tardan más en aparecer en Google.',
                'hacer' => 'Pedir al equipo web que publique y mantenga el mapa del sitio.',
            ],
            'direccion' => [
                'significa' => 'Falta o falla el mapa del sitio.',
                'importa' => 'Retrasa la aparición de contenidos nuevos en buscadores.',
                'hacer' => 'Incluirlo en las correcciones técnicas; el esfuerzo es bajo.',
            ],
            'desarrollo' => [
                'importa' => 'Un sitemap.xml válido, declarado en robots.txt con «Sitemap:», ayuda a descubrir las URL.',
                'comprobar' => 'Abrir /sitemap.xml, validar el XML y revisar la línea Sitemap: de /robots.txt.',
            ],
        ],
        'C20' => [
            'marketing' => [
                'significa' => 'El certificado de seguridad caduca pronto o está mal, o la página carga elementos inseguros.',
                'importa' => 'El navegador puede mostrar avisos de inseguridad que espantan a las visitas.',
                'hacer' => 'Pedir al equipo web o al alojamiento que revise el certificado y los elementos inseguros.',
            ],
            'direccion' => [
                'significa' => 'Hay un problema con la seguridad de la conexión.',
                'importa' => 'Riesgo de avisos de inseguridad y pérdida de confianza.',
                'hacer' => 'Encargar la corrección al proveedor o al equipo web.',
            ],
            'desarrollo' => [
                'importa' => 'Un certificado caducado o próximo a caducar, o recursos http:// en una página https (contenido mixto), generan avisos o bloqueos.',
                'comprobar' => 'openssl s_client -connect dominio:443 -servername dominio y la pestaña Seguridad o la consola del navegador.',
            ],
        ],
        'C21' => [
            'marketing' => [
                'significa' => 'Hay vídeos, mapas u otros contenidos incrustados sin un nombre que diga qué son.',
                'importa' => 'Quien usa un lector de pantalla no sabe qué contienen.',
                'hacer' => 'Pedir que cada contenido incrustado tenga un título descriptivo.',
            ],
            'direccion' => [
                'significa' => 'Contenidos incrustados sin descripción.',
                'importa' => 'Impacto menor en accesibilidad.',
                'hacer' => 'Incluirlo como corrección rápida.',
            ],
            'desarrollo' => [
                'importa' => 'Cada <iframe> necesita un atributo title descriptivo para tener nombre accesible.',
                'comprobar' => 'document.querySelectorAll("iframe:not([title])") en la consola.',
            ],
        ],
        'C22' => [
            'marketing' => [
                'significa' => 'El código de la página repite identificadores internos que deberían ser únicos.',
                'importa' => 'Puede romper formularios y la navegación con lector de pantalla.',
                'hacer' => 'Pedir al equipo web que los corrija.',
            ],
            'direccion' => [
                'significa' => 'Hay errores internos en el código de la página.',
                'importa' => 'Pueden fallar formularios y la accesibilidad.',
                'hacer' => 'Incluirlo en el mantenimiento técnico.',
            ],
            'desarrollo' => [
                'importa' => 'Un id duplicado rompe las asociaciones <label for>, aria-labelledby y aria-describedby.',
                'comprobar' => 'Validador del W3C o un recuento de [id] repetidos en la consola.',
            ],
        ],
        'C23' => [
            'marketing' => [
                'significa' => 'Hay vídeos sin subtítulos.',
                'importa' => 'Quien no oye o ve el vídeo sin sonido, como casi todo el mundo en el móvil, no entiende el mensaje.',
                'hacer' => 'Añadir subtítulos a los vídeos.',
            ],
            'direccion' => [
                'significa' => 'Los vídeos no tienen subtítulos.',
                'importa' => 'Reduce el alcance de los vídeos y su accesibilidad.',
                'hacer' => 'Encargar los subtítulos; el esfuerzo es medio.',
            ],
            'desarrollo' => [
                'importa' => 'Los <video> necesitan <track kind="captions" srclang="es">; en los incrustados, subtítulos en la plataforma.',
                'comprobar' => 'Revisar los <track> del HTML; la calidad de los subtítulos requiere revisión manual.',
            ],
        ],
        'C24' => [
            'marketing' => [
                'significa' => 'Hay elementos que se mueven o suenan solos al entrar.',
                'importa' => 'Distraen del mensaje, molestan y a algunas personas les pueden marear.',
                'hacer' => 'Evitar la reproducción automática o permitir pararla.',
            ],
            'direccion' => [
                'significa' => 'Hay contenido que se reproduce solo.',
                'importa' => 'Empeora la experiencia y la accesibilidad.',
                'hacer' => 'Pedir a diseño que lo revise.',
            ],
            'desarrollo' => [
                'importa' => 'autoplay sin muted ni controles, o animaciones que no respetan prefers-reduced-motion, impiden pausar.',
                'comprobar' => 'Buscar autoplay en <video> y <audio>, y probar con «Reducir movimiento» activado en el sistema.',
            ],
        ],
        'C25' => [
            'marketing' => [
                'significa' => 'La página impide que Google muestre fragmentos de su texto.',
                'importa' => 'Tampoco puede citarla en sus resúmenes de IA: se pierde visibilidad.',
                'hacer' => 'Confirmar si es intencionado y, si no, pedir que se retire la restricción.',
            ],
            'direccion' => [
                'significa' => 'La web limita cómo la muestran los buscadores y la IA.',
                'importa' => 'Reduce la presencia en resultados y en respuestas de IA.',
                'hacer' => 'Decidir si la restricción es intencionada; si no, retirarla (esfuerzo bajo).',
            ],
            'desarrollo' => [
                'importa' => 'nosnippet, max-snippet:0 o data-nosnippet en el contenido principal impiden fragmentos y citas en AI Overviews.',
                'comprobar' => 'Revisar <meta name="robots">, la cabecera X-Robots-Tag y los atributos data-nosnippet.',
            ],
        ],
        'C29' => [
            'marketing' => [
                'significa' => 'El texto aporta pocos datos concretos, citas o fuentes.',
                'importa' => 'Los asistentes de IA tienden a citar textos con cifras y fuentes.',
                'hacer' => 'Reforzar el contenido con datos, cifras y referencias.',
            ],
            'direccion' => [
                'significa' => 'El contenido podría apoyarse más en datos y fuentes.',
                'importa' => 'Puede influir en las citas de la IA (basado en un estudio, no en un requisito).',
                'hacer' => 'Valorarlo en la estrategia de contenidos.',
            ],
            'desarrollo' => [
                'importa' => 'Recuento de cifras con unidad, citas atribuidas y enlaces a fuentes en el contenido principal.',
                'comprobar' => 'Revisión editorial del texto del <main>; no hay una etiqueta concreta que corregir.',
            ],
        ],
        'C30' => [
            'marketing' => [
                'significa' => 'Los datos de la empresa en el código (nombre, teléfono, dirección) no coinciden con los que se ven.',
                'importa' => 'Google desconfía de esos datos y puede no mostrarlos.',
                'hacer' => 'Revisar que los datos de contacto sean los mismos en todas partes.',
            ],
            'direccion' => [
                'significa' => 'Hay incoherencias en los datos de la empresa.',
                'importa' => 'Resta credibilidad ante buscadores.',
                'hacer' => 'Encargar que se unifiquen; el esfuerzo es bajo.',
            ],
            'desarrollo' => [
                'importa' => 'Los valores del JSON-LD (name, telephone, address) deben coincidir con el texto visible de la página.',
                'comprobar' => 'Comparar el bloque application/ld+json con el contenido visible; Prueba de resultados enriquecidos.',
            ],
        ],
        'C31' => [
            'marketing' => [
                'significa' => 'La página marca como reseñas las opiniones sobre la propia empresa.',
                'importa' => 'Google no muestra estrellas de reseñas que una empresa publica sobre sí misma.',
                'hacer' => 'No contar con esas estrellas en los resultados; las reseñas pueden seguir visibles en la página.',
            ],
            'direccion' => [
                'significa' => 'Un marcado de reseñas no aporta resultados.',
                'importa' => 'Impacto menor: es esfuerzo sin retorno en buscadores.',
                'hacer' => 'Valorar retirarlo en el mantenimiento.',
            ],
            'desarrollo' => [
                'importa' => 'Review o AggregateRating sobre la propia Organization o LocalBusiness no es elegible para fragmentos de reseñas.',
                'comprobar' => 'Revisar los tipos del JSON-LD con la Prueba de resultados enriquecidos.',
            ],
        ],
        'WEB-TITULOS' => [
            'general' => [
                'nombre' => 'Títulos repetidos',
                'significa' => 'Varias páginas del sitio comparten el mismo <title>.',
                'hacer' => 'Escribir un title distinto para cada página, que diga de qué trata.',
            ],
            'marketing' => [
                'significa' => 'Varias páginas tienen el mismo titular en Google.',
                'importa' => 'La gente no distingue cuál le interesa y Google puede tratarlas como duplicadas.',
                'hacer' => 'Escribir un titular distinto para cada página.',
            ],
            'direccion' => [
                'significa' => 'Hay páginas con títulos duplicados.',
                'importa' => 'Resta visibilidad y claridad en los resultados.',
                'hacer' => 'Encargarlo al equipo de contenidos.',
            ],
            'desarrollo' => [
                'importa' => 'Los <title> duplicados entre URL dificultan que el buscador elija la página adecuada.',
                'comprobar' => 'Columna de título del CSV de páginas del sitio.',
            ],
        ],
        'WEB-404' => [
            'general' => [
                'nombre' => 'Página de error 404',
                'significa' => 'La página de error del sitio no responde como se espera.',
                'hacer' => 'Hacer que las direcciones inexistentes respondan 404 con una página útil.',
            ],
            'marketing' => [
                'significa' => 'Cuando alguien entra en una dirección que no existe, la web no lo indica bien.',
                'importa' => 'La visita se pierde sin una salida clara hacia otras páginas.',
                'hacer' => 'Pedir una página de error útil, con enlaces a las secciones principales.',
            ],
            'direccion' => [
                'significa' => 'La gestión de páginas inexistentes es mejorable.',
                'importa' => 'Impacto menor en experiencia y en buscadores.',
                'hacer' => 'Incluirlo en el mantenimiento técnico.',
            ],
            'desarrollo' => [
                'importa' => 'Una URL inexistente debe responder con código 404 (no 200 ni redirección a la portada).',
                'comprobar' => 'curl -I https://dominio/ruta-que-no-existe.',
            ],
        ],
    ],
];
