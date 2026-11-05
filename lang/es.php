<?php
// Textos en español. Las recomendaciones son un PRIMER BORRADOR para que el equipo las revise.
// Ningún texto debe afirmar cumplimiento de WCAG ni de ninguna ley.
return [
    'areas' => [
        'seo' => 'SEO',
        'ia' => 'Preparación para IA',
        'accesibilidad' => 'Accesibilidad',
    ],
    'estados' => [
        'CUMPLE' => 'Sin errores detectables automáticamente',
        'NO_CUMPLE' => 'Fallo',
        'AVISO' => 'Aviso',
        'SIN_DATOS' => 'Sin datos',
    ],
    'riesgo_alto' => 'Riesgo alto: revisar de inmediato',
    'nota_incompleta' => 'Nota incompleta: más de la mitad de las comprobaciones de esta área no tienen datos.',
    'severidades' => [
        'CRITICO' => 'Crítico',
        'ALTO' => 'Alto',
        'MEDIO' => 'Medio',
        'BAJO' => 'Bajo',
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
            'descripcion' => 'Comprueba roles ARIA, la presencia de main y el uso de onclick en elementos no interactivos.',
            'recomendacion' => 'Usa solo roles ARIA definidos en la especificación (o elimina el atributo role si no es necesario).',
            'recomendacion_aviso' => 'Envuelve el contenido principal en un elemento main y usa button o a para las acciones en lugar de onclick sobre div o span, que no son accesibles con teclado por defecto.',
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
    ],
];
