// Medidas que se ejecutan dentro de la página, en el Chrome que controla PHP.
(() => {
    const limpio = (texto) => (texto || '').trim().replace(/\s+/g, ' ').slice(0, 60);
    const nombre = (e) => limpio(e.innerText || e.value || e.getAttribute('aria-label') || e.title || e.tagName);

    const visible = (e) => {
        const s = getComputedStyle(e);
        const r = e.getBoundingClientRect();
        return r.width > 1 && r.height > 1 && s.visibility !== 'hidden' && s.display !== 'none' && Number(s.opacity) > 0;
    };

    // Recorre también los componentes con shadow DOM abierto, donde viven algunos avisos de cookies.
    const todos = (raiz = document, lista = []) => {
        for (const e of raiz.querySelectorAll('*')) {
            lista.push(e);
            if (e.shadowRoot) {
                todos(e.shadowRoot, lista);
            }
        }
        return lista;
    };

    const activo = () => {
        let e = document.activeElement;
        while (e && e.shadowRoot && e.shadowRoot.activeElement) {
            e = e.shadowRoot.activeElement;
        }
        return e;
    };

    const fijo = (e) => {
        for (; e && e.nodeType === 1; e = e.parentElement) {
            if (['fixed', 'sticky'].includes(getComputedStyle(e).position)) {
                return e;
            }
        }
        return null;
    };

    const ids = new WeakMap();
    let ultimo = 0;
    const clicables = 'a[href], button, [role="button"], input[type="button"], input[type="submit"]';
    let opciones = {};

    window.__medir = {
        // Cuántos elementos visibles deberían recibir el foco.
        enfocables: () => todos().filter((e) => e.matches('a[href], button, input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])') && !e.disabled && visible(e)).length,

        // Describe el elemento activo tras pulsar Tab.
        foco: () => {
            const e = activo();
            if (!e || e === document.body || e === document.documentElement) {
                return null;
            }
            if (!ids.has(e)) {
                ids.set(e, ++ultimo);
            }
            const r = e.getBoundingClientRect();
            const puntos = [[r.left + r.width / 2, r.top + r.height / 2], [r.left + 2, r.top + 2], [r.right - 2, r.top + 2], [r.left + 2, r.bottom - 2], [r.right - 2, r.bottom - 2]];
            let tapador = null;
            const tapado = puntos.every(([x, y]) => {
                if (x < 0 || y < 0 || x >= innerWidth || y >= innerHeight) {
                    return false;
                }
                const arriba = document.elementFromPoint(x, y);
                if (!arriba || arriba === e || e.contains(arriba) || arriba.contains(e)) {
                    return false;
                }
                tapador = fijo(arriba);
                return tapador !== null && !tapador.contains(e);
            });
            return {
                id: ids.get(e),
                etiqueta: e.tagName,
                nombre: nombre(e),
                visible: visible(e),
                x: Math.round(r.left),
                y: Math.round(r.top + scrollY),
                tapado,
                tapador: tapado && tapador ? (tapador.id || tapador.tagName).slice(0, 40) : '',
            };
        },

        // Busca el aviso de cookies y clasifica sus botones.
        cookies: () => {
            opciones = {};
            const zona = /cookie|consent|gdpr|cmp|privacy|privacidad/i;
            for (const e of todos()) {
                if (!e.matches(clicables) || !visible(e)) {
                    continue;
                }
                let dentro = false;
                for (let p = e, i = 0; p && p.nodeType === 1 && i < 10; p = p.parentElement || (p.getRootNode() && p.getRootNode().host), i++) {
                    if (zona.test(`${p.id} ${p.className}`) || zona.test(p.getAttribute('aria-label') || '')) {
                        dentro = true;
                        break;
                    }
                }
                if (!dentro) {
                    continue;
                }
                const texto = nombre(e).toLowerCase();
                if (/rechaz|reject|deneg|decline|deny|no acepto|solo (las )?(necesarias|esenciales|imprescindibles|técnicas)|only (necessary|essential)/.test(texto)) {
                    opciones.rechazar ||= e;
                } else if (/acept|accept|allow|permitir|de acuerdo|entendido/.test(texto)) {
                    opciones.aceptar ||= e;
                } else if (/configur|preferenc|ajustes|personaliz|settings|manage|gestionar/.test(texto)) {
                    opciones.configurar ||= e;
                }
            }
            return { aceptar: !!opciones.aceptar, rechazar: !!opciones.rechazar, configurar: !!opciones.configurar };
        },

        pulsar: (opcion) => {
            if (opciones[opcion]) {
                opciones[opcion].click();
            }
        },

        // Mide si la página cabe en el ancho actual.
        reflow: () => {
            const raiz = document.documentElement;
            const ancho = raiz.clientWidth;
            let cortados = 0;
            let ejemplo = '';
            for (const e of document.body.querySelectorAll('*')) {
                const r = e.getBoundingClientRect();
                if (e.children.length > 0 || !limpio(e.textContent) || r.right <= ancho + 2 || r.left >= ancho || !visible(e)) {
                    continue;
                }
                let recortado = false;
                for (let p = e.parentElement; p && p !== document.body; p = p.parentElement) {
                    if (getComputedStyle(p).overflowX !== 'visible') {
                        recortado = true;
                        break;
                    }
                }
                if (!recortado) {
                    cortados++;
                    ejemplo ||= limpio(e.textContent);
                }
            }
            return { ancho, sobra: Math.max(0, raiz.scrollWidth - ancho), cortados, ejemplo };
        },

        // Prueba los botones del primer carrusel visible.
        carrusel: () => {
            const zona = /swiper|carousel|slick|splide|glide|flickity|keen-slider|owl/i;
            const carrusel = [...document.querySelectorAll('[class]')].find((e) => zona.test(e.className) && visible(e) && e.children.length > 0);
            if (!carrusel) {
                return null;
            }
            const huella = () => carrusel.scrollLeft + [...carrusel.querySelectorAll('*')].slice(0, 300).map((e) => `${e.className}|${e.style.transform}`).join();
            const zona_boton = carrusel.parentElement || carrusel;
            const boton = [...zona_boton.querySelectorAll('button, [role="button"], a[href="#"], a:not([href]), [class*="next"], [class*="arrow"], [class*="bullet"], [class*="dot"]')]
                .find((e) => (visible(e) || [...e.children].some(visible)) && !/slide/i.test(String(e.className).replace(/swiper-button|swiper-pagination/g, ''))
                    && /next|siguiente|prev|anterior|arrow|flecha|bullet|\bdot/i.test(`${e.className} ${e.getAttribute('aria-label') || ''}`));
            if (!boton) {
                return { boton: false, cambia: false };
            }
            const antes = huella();
            boton.click();
            return new Promise((fin) => setTimeout(() => fin({ boton: true, cambia: huella() !== antes, nombre: nombre(boton) }), 900));
        },

        // Provoca el error de un formulario vacío. Chrome no deja salir ningún envío de datos.
        formulario: () => {
            const form = [...document.forms].find((f) => visible(f) && (f.method || '').toLowerCase() === 'post' && f.querySelector('[required], [aria-required="true"]'));
            if (!form) {
                return null;
            }
            const invalido = form.noValidate ? null : [...form.elements].find((e) => e.willValidate && !e.checkValidity());
            if (invalido) {
                return { tipo: 'navegador', mensaje: limpio(invalido.validationMessage) };
            }
            const campos = [...form.elements].filter((e) => e.matches('input, select, textarea') && visible(e));
            const borde = () => campos.map((e) => getComputedStyle(e).borderColor).join();
            const texto = () => form.innerText;
            const antes = { borde: borde(), texto: texto() };
            form.requestSubmit();
            return new Promise((fin) => setTimeout(() => {
                const avisos = [...document.querySelectorAll('[role="alert"], [aria-live]')].map((e) => limpio(e.textContent)).filter(Boolean);
                const nuevo = texto() !== antes.texto;
                fin({ tipo: 'pagina', nuevo, avisos: avisos.slice(0, 2), invalidos: form.querySelectorAll('[aria-invalid="true"]').length, color: borde() !== antes.borde });
            }, 1000));
        },
    };
})();
