/*!
 * DNS Reverse - pantalla del area de cliente
 *
 * Anade "DNS Reverse" a la barra del servidor y dibuja su propia pantalla
 * encima del panel. No toca React ni obliga a recompilar nada: es JavaScript
 * suelto, igual que la otra extension de este repositorio.
 *
 * La version anterior de esta extension SI metia componentes de React dentro
 * de resources/scripts y exigia un "yarn build". Por eso desaparecia cada vez
 * que se actualizaba el panel (y a veces dejaba el panel en blanco si el tema
 * no compilaba). Aqui eso no puede pasar.
 *
 * Los iconos van como SVG escrito aqui dentro, no con clases de Font Awesome,
 * porque el tema Arix no carga Font Awesome y saldrian huecos vacios.
 */
(function () {
    'use strict';

    var config = window.DnsReverseConfig || {};
    var ENDPOINT = (config.endpoint || '/api/dnsreverse').replace(/\/$/, '');
    var RUTA = 'dnsreverse';

    var estado = {
        servidor: null,
        datos: null,
        abierto: false,
        cargando: false,
        enviando: false,
        ultimaRuta: null,
        raiz: null,
        // Aviso que sobrevive al refresco de la lista, para que el cliente
        // llegue a leer que su dominio se creo bien.
        aviso: null,
        ultimoAviso: null,
    };

    // --- Iconos -------------------------------------------------------------

    var ICONOS = {
        globe: '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        lock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        plus: '<path d="M5 12h14"/><path d="M12 5v14"/>',
        trash: '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        external: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        close: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        alert: '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        check: '<path d="M20 6 9 17l-5-5"/>',
        info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        server: '<rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><path d="M6 6h.01"/><path d="M6 18h.01"/>',
        spinner: '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
    };

    function icono(nombre, tamano) {
        return (
            '<svg class="dnsrev-icon" xmlns="http://www.w3.org/2000/svg" width="' + (tamano || 16) +
            '" height="' + (tamano || 16) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            (ICONOS[nombre] || '') + '</svg>'
        );
    }

    // --- Utilidades ---------------------------------------------------------

    function servidorActual() {
        var coincide = window.location.pathname.match(/\/server\/([a-zA-Z0-9-]{4,40})(\/|$)/);
        return coincide ? coincide[1] : null;
    }

    function csrf() {
        if (config.csrf) {
            return config.csrf;
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function peticion(metodo, ruta, cuerpo, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open(metodo, ENDPOINT + ruta, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        if (metodo !== 'GET') {
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
        }

        xhr.onload = function () {
            var datos = null;
            try {
                datos = JSON.parse(xhr.responseText);
            } catch (e) {
                datos = null;
            }
            callback(xhr.status, datos);
        };

        xhr.onerror = function () {
            callback(0, null);
        };

        xhr.send(cuerpo ? JSON.stringify(cuerpo) : null);
    }

    function escapar(valor) {
        return String(valor === null || valor === undefined ? '' : valor)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // --- Entrada en la barra del servidor -----------------------------------

    /**
     * Busca la barra de navegacion del servidor (Consola, Archivos, Bases de
     * datos...) para colgar ahi la entrada.
     *
     * No se busca por clases: cada tema usa las suyas y Arix las cambia. Lo que
     * se hace es mirar todos los enlaces que apuntan a una seccion del servidor
     * y quedarse con el elemento que agrupa a mas de ellos, que es la barra
     * pase lo que pase con el maquetado.
     */
    /**
     * ¿Este elemento se ve de verdad en pantalla?
     *
     * offsetParent en null cubre display:none y todo lo que cuelgue de algo
     * oculto; el tamano cubre los que estan a 0 o con visibility:hidden.
     */
    /**
     * Deja constancia en la consola del navegador (F12) de donde se ha puesto
     * la entrada. Si a alguien no le sale, esto dice si el problema es que el
     * archivo no llega a cargarse o que no se reconocio el menu del tema.
     */
    function avisar(mensaje) {
        if (estado.ultimoAviso === mensaje) {
            return;
        }

        estado.ultimoAviso = mensaje;

        try {
            window.console.info('[DNS Reverse] ' + mensaje);
        } catch (e) {
            // Sin consola no pasa nada.
        }
    }

    function esVisible(elemento) {
        if (!elemento || elemento.offsetParent === null) {
            return false;
        }

        var caja = elemento.getBoundingClientRect();

        return caja.width > 0 && caja.height > 0;
    }

    function barraDelServidor() {
        // Cualquier enlace a una seccion del servidor vale, se llame como se
        // llame. Los temas cambian los nombres de las secciones, asi que no se
        // exige una lista concreta: basta con /server/<id>/<algo>.
        var seccion = /^\/server\/[^/]+\/[^/]+\/?$/;
        var candidatos = [];
        var conteos = [];

        var enlaces = document.querySelectorAll('a[href*="/server/"]');

        for (var i = 0; i < enlaces.length; i++) {
            var ruta = enlaces[i].getAttribute('href') || '';

            // Se admite tanto la ruta relativa como la absoluta con dominio.
            try {
                ruta = new URL(ruta, window.location.origin).pathname;
            } catch (e) {
                // Enlace raro: se usa tal cual.
            }

            if (!seccion.test(ruta) || ruta.indexOf('/' + RUTA) !== -1) {
                continue;
            }

            // Un enlace escondido no sirve: muchos temas llevan ademas del
            // menu normal un desplegable para movil con los mismos enlaces,
            // oculto en pantalla grande. Si metieramos la entrada ahi, el
            // cliente no la veria nunca.
            if (!esVisible(enlaces[i])) {
                continue;
            }

            // Se sube un nivel si el enlace va envuelto (algunos temas meten
            // cada entrada dentro de un <li> o de un <div>). Se busca el
            // ancestro que agrupe a varios enlaces de seccion.
            var padre = enlaces[i].parentElement;

            for (var salto = 0; salto < 3 && padre; salto++) {
                if (padre.querySelectorAll('a[href*="/server/"]').length > 1) {
                    break;
                }

                padre = padre.parentElement;
            }

            if (!padre) {
                continue;
            }

            var indice = candidatos.indexOf(padre);

            if (indice === -1) {
                candidatos.push(padre);
                conteos.push(1);
            } else {
                conteos[indice]++;
            }
        }

        if (!candidatos.length) {
            return null;
        }

        var mejor = 0;

        for (var j = 1; j < candidatos.length; j++) {
            if (conteos[j] > conteos[mejor]) {
                mejor = j;
            }
        }

        // Un solo enlace no es una barra de navegacion: probablemente sea un
        // enlace suelto de otra parte de la pagina.
        return conteos[mejor] >= 2 ? candidatos[mejor] : null;
    }

    function ponerEntrada() {
        var servidor = servidorActual();

        if (!servidor || document.querySelector('[data-dnsrev-nav]')) {
            return;
        }

        var barra = barraDelServidor();

        if (!barra) {
            // Todavia no esta dibujada (el panel es una aplicacion de una sola
            // pagina y tarda un poco) o el tema la monta de otra forma. Se
            // vuelve a intentar en el siguiente repaso, cada 700 ms.
            avisar('todavia no se encuentra la barra del servidor, reintentando');

            return;
        }

        avisar('entrada anadida a la barra del servidor');

        var referencia = barra.querySelector('a[href*="/server/"]');
        var enlace = document.createElement('a');

        enlace.setAttribute('data-dnsrev-nav', '1');
        enlace.setAttribute('href', '/server/' + servidor + '/' + RUTA);
        enlace.className = referencia ? referencia.className : '';
        enlace.innerHTML = '<span class="dnsrev-nav-inner">' + icono('globe', 15) + '<span>DNS Reverse</span></span>';

        enlace.addEventListener('click', function (evento) {
            evento.preventDefault();
            abrir();
        });

        barra.appendChild(enlace);

        // Si la barra elegida resulta estar escondida (algunos temas llevan un
        // segundo menu para movil con los mismos enlaces), se retira y en el
        // siguiente repaso se busca otra. Asi la entrada no se queda puesta en
        // un sitio donde el cliente no la va a ver nunca.
        if (!esVisible(enlace)) {
            if (enlace.parentNode) {
                enlace.parentNode.removeChild(enlace);
            }

            avisar('la barra encontrada estaba oculta, se busca otra');
        }
    }

    // --- Pantalla -----------------------------------------------------------

    function abrir() {
        if (estado.abierto) {
            return;
        }

        estado.abierto = true;
        estado.servidor = servidorActual();

        var raiz = document.createElement('div');
        raiz.className = 'dnsrev-overlay';
        raiz.innerHTML =
            '<div class="dnsrev-sheet" role="dialog" aria-label="DNS Reverse">' +
            '  <div class="dnsrev-header">' +
            '    <div class="dnsrev-header-title">' + icono('globe', 20) +
            '      <div><h2>DNS Reverse</h2><p>Pon un dominio propio a tu servidor</p></div>' +
            '    </div>' +
            '    <button type="button" class="dnsrev-close" aria-label="Cerrar">' + icono('close', 18) + '</button>' +
            '  </div>' +
            '  <div class="dnsrev-body"><div class="dnsrev-loading">' + icono('spinner', 22) + '<span>Cargando...</span></div></div>' +
            '</div>';

        document.body.appendChild(raiz);
        document.body.classList.add('dnsrev-open');
        estado.raiz = raiz;

        raiz.querySelector('.dnsrev-close').addEventListener('click', cerrar);
        raiz.addEventListener('click', function (evento) {
            if (evento.target === raiz) {
                cerrar();
            }
        });

        document.addEventListener('keydown', alPulsarTecla);

        // La URL cambia para que se pueda compartir y para que el boton de
        // atras del navegador cierre la pantalla en vez de salir del panel.
        try {
            window.history.pushState({ dnsrev: true }, '', '/server/' + estado.servidor + '/' + RUTA);
        } catch (e) {
            // Navegador sin history API: la pantalla funciona igual.
        }

        cargar();
    }

    function cerrar() {
        if (!estado.abierto) {
            return;
        }

        estado.abierto = false;
        document.removeEventListener('keydown', alPulsarTecla);
        document.body.classList.remove('dnsrev-open');

        if (estado.raiz && estado.raiz.parentNode) {
            estado.raiz.parentNode.removeChild(estado.raiz);
        }

        estado.raiz = null;

        // Si la URL sigue siendo la nuestra, se vuelve a la del servidor.
        if (window.location.pathname.indexOf('/' + RUTA) !== -1) {
            try {
                window.history.back();
            } catch (e) {
                window.location.pathname = '/server/' + estado.servidor;
            }
        }
    }

    function alPulsarTecla(evento) {
        if (evento.key === 'Escape') {
            cerrar();
        }
    }

    function cuerpo() {
        return estado.raiz ? estado.raiz.querySelector('.dnsrev-body') : null;
    }

    function cargar() {
        if (!estado.servidor) {
            return;
        }

        estado.cargando = true;

        peticion('GET', '/server/' + encodeURIComponent(estado.servidor), null, function (codigo, datos) {
            estado.cargando = false;

            if (codigo === 403) {
                pintarError('No tienes permiso para gestionar los DNS de este servidor.');
                return;
            }

            if (codigo !== 200 || !datos) {
                pintarError('No se pudo cargar la informacion. Vuelve a intentarlo en unos segundos.');
                return;
            }

            estado.datos = datos;
            pintar();
        });
    }

    function pintarError(mensaje) {
        var destino = cuerpo();

        if (destino) {
            destino.innerHTML = '<div class="dnsrev-alert dnsrev-alert-error">' + icono('alert', 18) +
                '<span>' + escapar(mensaje) + '</span></div>';
        }
    }

    function pintar() {
        var destino = cuerpo();

        if (!destino || !estado.datos) {
            return;
        }

        var cfg = estado.datos.config;

        if (!cfg.types.length) {
            destino.innerHTML = '<div class="dnsrev-alert dnsrev-alert-info">' + icono('info', 18) +
                '<span>Este tipo de servidor no admite DNS. Si crees que es un error, escribe al soporte.</span></div>';
            return;
        }

        var html = '';

        // Un aviso de la accion anterior (por ejemplo "dominio creado") se
        // pinta arriba del todo y se queda hasta la siguiente accion, para que
        // no se lo lleve el refresco de la lista.
        if (estado.aviso) {
            html += '<div class="dnsrev-form-result dnsrev-result-' + estado.aviso.tipo + '" style="margin-bottom:16px;">' +
                icono(estado.aviso.tipo === 'ok' ? 'check' : 'alert', 16) +
                '<span>' + escapar(estado.aviso.texto) + '</span></div>';
        }

        html += bloqueAyuda(cfg);
        html += bloqueListado();

        if (cfg.can_manage) {
            if (cfg.remaining > 0) {
                html += bloqueFormulario(cfg);
            } else {
                html += '<div class="dnsrev-alert dnsrev-alert-info">' + icono('info', 18) +
                    '<span>Has usado los <strong>' + cfg.limit + '</strong> DNS de este servidor. ' +
                    'Borra uno para crear otro, o pide al soporte que te suba el limite.</span></div>';
            }
        }

        destino.innerHTML = html;
        engancharEventos();
    }

    // --- Trozos de la pantalla ---------------------------------------------

    function bloqueAyuda(cfg) {
        // La primera vez (cuando todavia no tiene ningun dominio) la ayuda sale
        // desplegada: es justo cuando hace falta leerla. Despues va plegada
        // para no molestar.
        var abierta = (estado.datos.records || []).length === 0 ? ' open' : '';

        return '' +
            '<details class="dnsrev-help"' + abierta + '>' +
            '  <summary>' + icono('info', 16) + '<span>¿Como funciona esto? (leelo la primera vez)</span></summary>' +
            '  <div class="dnsrev-help-body">' +
            '    <p>Aqui pones un <strong>dominio bonito</strong> a tu servidor, para que la gente entre por ' +
            '       <code>tunombre.com</code> en vez de por una IP con puerto.</p>' +
            '    <h4>Tienes dos formas de conseguir el dominio</h4>' +
            '    <ul>' +
            (cfg.domains.length
                ? '      <li><strong>Subdominio nuestro:</strong> eliges un nombre y te lo damos hecho. ' +
                  'No tienes que comprar nada ni configurar nada: en un minuto esta funcionando.</li>'
                : '') +
            (cfg.allow_custom_domains
                ? '      <li><strong>Tu propio dominio:</strong> si ya compraste uno, lo apuntas a nuestra IP ' +
                  'y lo pones aqui. Te decimos exactamente que registro tienes que crear.</li>'
                : '') +
            '    </ul>' +
            '    <h4>Y dos formas de tener el candado (HTTPS)</h4>' +
            '    <ul>' +
            '      <li><strong>Certificado automatico (Let\'s Encrypt):</strong> se pide solo y no tienes que ' +
            '          hacer nada. Dura 90 dias y se renueva solo antes de caducar. ' +
            '          <em>Requisito:</em> el dominio tiene que apuntar directamente a nuestra IP, con la ' +
            '          <strong>nube gris</strong> en Cloudflare (opcion "DNS only"). Si la dejas naranja, ' +
            '          la comprobacion no llega a nuestro servidor y falla.</li>' +
            '      <li><strong>Certificado de origen:</strong> es el que genera Cloudflare desde ' +
            '          <em>SSL/TLS &rarr; Origin Server</em>. Dura 15 anos, pero <strong>solo vale si el ' +
            '          trafico pasa por Cloudflare</strong>, o sea con la <strong>nube naranja</strong> puesta. ' +
            '          Es la opcion recomendada si usas Cloudflare.</li>' +
            '    </ul>' +
            '    <p class="dnsrev-help-tip">' + icono('alert', 14) +
            '      <span>Resumen facil: <strong>nube gris = Let\'s Encrypt</strong>, ' +
            '      <strong>nube naranja = certificado de origen</strong>. Mezclarlos es lo que provoca el ' +
            '      famoso error 526 de Cloudflare.</span></p>' +
            '  </div>' +
            '</details>';
    }

    function bloqueListado() {
        var registros = estado.datos.records || [];
        var cfg = estado.datos.config;

        var html = '<div class="dnsrev-section">' +
            '<div class="dnsrev-section-head">' +
            '  <h3>Tus DNS</h3>' +
            '  <span class="dnsrev-counter">' + registros.length + ' de ' + cfg.limit + '</span>' +
            '</div>';

        if (!registros.length) {
            html += '<div class="dnsrev-empty">' + icono('globe', 26) +
                '<p>Todavia no tienes ningun dominio. Crea el primero abajo.</p></div>';
            html += '</div>';
            return html;
        }

        html += '<div class="dnsrev-list">';

        registros.forEach(function (registro) {
            html += '<div class="dnsrev-card">' +
                '  <div class="dnsrev-card-main">' +
                '    <div class="dnsrev-card-domain">' +
                (registro.type === 'srv'
                    ? '<strong>' + escapar(registro.domain) + '</strong>'
                    : '<a href="' + escapar(registro.url) + '" target="_blank" rel="noopener noreferrer">' +
                      '<strong>' + escapar(registro.domain) + '</strong>' + icono('external', 13) + '</a>') +
                '    </div>' +
                '    <div class="dnsrev-card-meta">' +
                '      <span>' + icono('server', 13) + escapar(registro.address || '-') + '</span>' +
                '      <span>' + icono('lock', 13) + escapar(registro.ssl_label) + '</span>' +
                '      <span class="dnsrev-tag">' + escapar(registro.type_label) + '</span>' +
                '    </div>' +
                (registro.error ? '<div class="dnsrev-card-error">' + escapar(registro.error) + '</div>' : '') +
                '  </div>' +
                (cfg.can_manage
                    ? '  <button type="button" class="dnsrev-btn dnsrev-btn-danger" data-borrar="' + registro.id +
                      '" data-dominio="' + escapar(registro.domain) + '">' + icono('trash', 15) + '<span>Borrar</span></button>'
                    : '') +
                '</div>';
        });

        html += '</div></div>';

        return html;
    }

    function bloqueFormulario(cfg) {
        var tipos = [];

        if (cfg.types.indexOf('subdomain') !== -1 && cfg.domains.some(function (d) { return d.allow_subdomain; })) {
            tipos.push({ valor: 'subdomain', texto: 'Subdominio nuestro (lo mas facil)' });
        }

        if (cfg.types.indexOf('domain') !== -1 && cfg.allow_custom_domains) {
            tipos.push({ valor: 'domain', texto: 'Mi propio dominio' });
        }

        if (cfg.types.indexOf('srv') !== -1 && cfg.domains.some(function (d) { return d.allow_srv; })) {
            tipos.push({ valor: 'srv', texto: 'Minecraft SRV (entrar sin escribir el puerto)' });
        }

        if (!tipos.length) {
            return '<div class="dnsrev-alert dnsrev-alert-info">' + icono('info', 18) +
                '<span>Ahora mismo no hay ninguna opcion disponible para este servidor. Escribe al soporte.</span></div>';
        }

        var html = '<div class="dnsrev-section">' +
            '<div class="dnsrev-section-head"><h3>Crear un DNS nuevo</h3></div>' +
            '<form class="dnsrev-form" id="dnsrevForm">';

        // --- Tipo ---
        html += campo('Que quieres hacer', selector('type', tipos));

        // --- Nombre + dominio base ---
        html += '<div class="dnsrev-row" data-solo="subdomain,srv">' +
            campo('Nombre que quieres', '<input type="text" name="name_sub" class="dnsrev-input" ' +
                'placeholder="mipagina" autocomplete="off" spellcheck="false">',
                'Solo letras, numeros y guiones. Se vera como <code><span id="dnsrevPreview">mipagina.' +
                escapar(cfg.domains.length ? cfg.domains[0].domain : 'dominio.com') + '</span></code>') +
            campo('Dominio', selector('domain_id', cfg.domains.map(function (d) {
                return { valor: String(d.id), texto: '.' + d.domain };
            }))) +
            '</div>' +
            '<div class="dnsrev-note" id="dnsrevAvisoManual" hidden></div>';

        html += '<div data-solo="domain">' +
            campo('Tu dominio', '<input type="text" name="name_full" class="dnsrev-input" ' +
                'placeholder="mipagina.com" autocomplete="off" spellcheck="false">',
                'Escribelo en minusculas, sin <code>http://</code> y sin barras.') +
            '<div class="dnsrev-note">' + icono('info', 15) +
            '<div><strong>Antes de darle a crear</strong><p>' + escapar(cfg.dns_instructions) + '</p></div></div>' +
            '</div>';

        // --- Puerto ---
        html += campo('Puerto de tu servidor', selector('allocation_id', cfg.allocations.map(function (a) {
            return { valor: String(a.id), texto: a.label + (a.default ? ' (principal)' : '') };
        })), 'Es el puerto al que se enviaran las visitas de ese dominio.');

        // --- Certificado ---
        html += '<div data-oculto="srv">' +
            campo('Candado (HTTPS)', '<div class="dnsrev-choices" id="dnsrevSsl"></div>') +
            '</div>';

        html += '<div class="dnsrev-cert-fields" hidden>' +
            campo('Certificado', '<textarea name="ssl_cert" class="dnsrev-input dnsrev-mono" rows="5" ' +
                'placeholder="-----BEGIN CERTIFICATE-----"></textarea>') +
            campo('Clave privada', '<textarea name="ssl_key" class="dnsrev-input dnsrev-mono" rows="5" ' +
                'placeholder="-----BEGIN PRIVATE KEY-----"></textarea>',
                'Se manda a tu servidor y se guarda ahi. Nadie mas la ve.') +
            '</div>';

        html += '<div class="dnsrev-form-result" hidden></div>';

        html += '<div class="dnsrev-form-actions">' +
            '<button type="submit" class="dnsrev-btn dnsrev-btn-primary">' + icono('plus', 16) +
            '<span>Crear DNS</span></button>' +
            '<span class="dnsrev-hint">Te quedan ' + cfg.remaining + ' de ' + cfg.limit + '.</span>' +
            '</div>';

        html += '</form></div>';

        return html;
    }

    function campo(etiqueta, control, ayuda) {
        return '<div class="dnsrev-field">' +
            '<label>' + etiqueta + '</label>' + control +
            (ayuda ? '<p class="dnsrev-help-text">' + ayuda + '</p>' : '') +
            '</div>';
    }

    function selector(nombre, opciones) {
        var html = '<select name="' + nombre + '" class="dnsrev-input">';

        opciones.forEach(function (opcion) {
            html += '<option value="' + escapar(opcion.valor) + '">' + escapar(opcion.texto) + '</option>';
        });

        return html + '</select>';
    }

    // --- Eventos del formulario --------------------------------------------

    function engancharEventos() {
        var raiz = estado.raiz;

        if (!raiz) {
            return;
        }

        raiz.querySelectorAll('[data-borrar]').forEach(function (boton) {
            boton.addEventListener('click', function () {
                borrar(boton.getAttribute('data-borrar'), boton.getAttribute('data-dominio'), boton);
            });
        });

        var formulario = raiz.querySelector('#dnsrevForm');

        if (!formulario) {
            return;
        }

        var tipo = formulario.elements.type;
        var nombreSub = formulario.elements.name_sub;
        var dominio = formulario.elements.domain_id;

        function refrescar() {
            var elegido = tipo.value;

            raiz.querySelectorAll('[data-solo]').forEach(function (bloque) {
                var lista = bloque.getAttribute('data-solo').split(',');
                bloque.hidden = lista.indexOf(elegido) === -1;
            });

            raiz.querySelectorAll('[data-oculto]').forEach(function (bloque) {
                var lista = bloque.getAttribute('data-oculto').split(',');
                bloque.hidden = lista.indexOf(elegido) !== -1;
            });

            filtrarDominios(elegido);
            pintarOpcionesSsl(elegido);
            avisoDnsManual();
            refrescarVistaPrevia();
        }

        /**
         * Un dominio puede admitir subdominios pero no SRV (o al reves). Se
         * quitan de la lista los que no valen para lo que ha elegido, para que
         * no llegue a pedir algo que el panel le va a rechazar.
         */
        function filtrarDominios(elegido) {
            if (!dominio) {
                return;
            }

            var cfg = estado.datos.config;
            var primeraValida = null;

            for (var i = 0; i < dominio.options.length; i++) {
                var opcion = dominio.options[i];
                var ficha = cfg.domains.filter(function (d) { return String(d.id) === opcion.value; })[0];
                var vale = !ficha || (elegido === 'srv' ? ficha.allow_srv : ficha.allow_subdomain);

                opcion.hidden = !vale;
                opcion.disabled = !vale;

                if (vale && primeraValida === null) {
                    primeraValida = opcion.value;
                }
            }

            // Si el que estaba elegido ya no vale, se pasa al primero que si.
            var actual = dominio.options[dominio.selectedIndex];

            if ((!actual || actual.disabled) && primeraValida !== null) {
                dominio.value = primeraValida;
            }
        }

        /**
         * Si el dominio no tiene token de Cloudflare guardado, el registro DNS
         * no se creara solo. Mas vale decirselo antes que dejarle esperando a
         * que su pagina cargue.
         */
        function avisoDnsManual() {
            var caja = raiz.querySelector('#dnsrevAvisoManual');

            if (!caja) {
                return;
            }

            var cfg = estado.datos.config;
            var elegido = tipo.value;
            var ficha = null;

            if (dominio) {
                ficha = cfg.domains.filter(function (d) { return String(d.id) === dominio.value; })[0] || null;
            }

            var manual = elegido !== 'domain' && ficha && !ficha.automatic_dns;

            caja.hidden = !manual;

            if (manual) {
                caja.innerHTML = icono('alert', 15) +
                    '<div><strong>Este dominio se configura a mano</strong>' +
                    '<p>Se preparara tu servidor al momento, pero el registro DNS lo tiene que crear ' +
                    'un administrador. Puede tardar un rato en funcionar.</p></div>';
            }
        }

        function refrescarVistaPrevia() {
            var vista = raiz.querySelector('#dnsrevPreview');

            if (!vista || !dominio) {
                return;
            }

            var base = dominio.options[dominio.selectedIndex];
            var nombre = (nombreSub.value || 'minombre').toLowerCase().replace(/[^a-z0-9-]/g, '');

            vista.textContent = nombre + (base ? base.text : '');
        }

        tipo.addEventListener('change', refrescar);

        if (nombreSub) {
            nombreSub.addEventListener('input', refrescarVistaPrevia);
        }

        if (dominio) {
            dominio.addEventListener('change', function () {
                refrescarVistaPrevia();
                pintarOpcionesSsl(tipo.value);
                avisoDnsManual();
            });
        }

        formulario.addEventListener('submit', function (evento) {
            evento.preventDefault();
            enviar(formulario);
        });

        refrescar();
    }

    /**
     * Las opciones de certificado dependen del tipo y del dominio elegido: no
     * tiene sentido ofrecer "certificado de origen" si ese dominio no tiene
     * uno puesto, ni "Let's Encrypt" si el administrador lo desactivo.
     */
    function pintarOpcionesSsl(tipoElegido) {
        var contenedor = estado.raiz ? estado.raiz.querySelector('#dnsrevSsl') : null;

        if (!contenedor) {
            return;
        }

        var cfg = estado.datos.config;
        var formulario = estado.raiz.querySelector('#dnsrevForm');
        var dominioElegido = null;

        if (formulario && formulario.elements.domain_id) {
            var id = parseInt(formulario.elements.domain_id.value, 10);
            dominioElegido = cfg.domains.filter(function (d) { return d.id === id; })[0] || null;
        }

        var opciones = [];

        if (tipoElegido === 'subdomain' && dominioElegido && dominioElegido.has_origin_cert) {
            opciones.push({
                valor: 'origin',
                titulo: 'Certificado de origen (recomendado)',
                texto: 'Ya lo tenemos puesto nosotros. Tu no tienes que hacer nada y no caduca en anos.',
            });
        }

        if (tipoElegido === 'domain') {
            opciones.push({
                valor: 'origin',
                titulo: 'Certificado de origen de Cloudflare',
                texto: 'Lo generas tu en Cloudflare (SSL/TLS &rarr; Origin Server) y lo pegas aqui. ' +
                    'Necesita la nube naranja puesta.',
            });
        }

        if (cfg.letsencrypt && (!dominioElegido || dominioElegido.allow_letsencrypt) && tipoElegido !== 'srv') {
            opciones.push({
                valor: 'letsencrypt',
                titulo: 'Certificado automatico (Let\'s Encrypt)',
                texto: 'Se pide solo y se renueva solo. El dominio tiene que apuntar directo a nuestra IP, ' +
                    'con la nube gris ("DNS only") en Cloudflare.',
            });
        }

        opciones.push({
            valor: 'none',
            titulo: 'Sin candado (solo http)',
            texto: 'La pagina cargara sin HTTPS y el navegador la marcara como no segura. Solo para pruebas.',
        });

        var html = '';

        opciones.forEach(function (opcion, indice) {
            html += '<label class="dnsrev-choice">' +
                '<input type="radio" name="ssl_mode" value="' + opcion.valor + '"' + (indice === 0 ? ' checked' : '') + '>' +
                '<span class="dnsrev-choice-body">' +
                '<strong>' + opcion.titulo + '</strong>' +
                '<span>' + opcion.texto + '</span>' +
                '</span></label>';
        });

        contenedor.innerHTML = html;

        contenedor.querySelectorAll('input[name="ssl_mode"]').forEach(function (radio) {
            radio.addEventListener('change', alCambiarSsl);
        });

        alCambiarSsl();
    }

    /**
     * Los campos para pegar el certificado solo se ensenan cuando hacen falta:
     * si el dominio ya trae uno puesto, el cliente no tiene que ver nada.
     */
    function alCambiarSsl() {
        var raiz = estado.raiz;

        if (!raiz) {
            return;
        }

        var formulario = raiz.querySelector('#dnsrevForm');
        var campos = raiz.querySelector('.dnsrev-cert-fields');

        if (!formulario || !campos) {
            return;
        }

        var modo = formulario.querySelector('input[name="ssl_mode"]:checked');
        var tipo = formulario.elements.type.value;
        var cfg = estado.datos.config;
        var dominioElegido = null;

        if (formulario.elements.domain_id) {
            var id = parseInt(formulario.elements.domain_id.value, 10);
            dominioElegido = cfg.domains.filter(function (d) { return d.id === id; })[0] || null;
        }

        var hayCertPuesto = tipo === 'subdomain' && dominioElegido && dominioElegido.has_origin_cert;

        campos.hidden = !(modo && modo.value === 'origin' && !hayCertPuesto);
    }

    // --- Acciones -----------------------------------------------------------

    function enviar(formulario) {
        if (estado.enviando) {
            return;
        }

        var tipo = formulario.elements.type.value;
        var modo = formulario.querySelector('input[name="ssl_mode"]:checked');

        var cuerpoPeticion = {
            type: tipo,
            name: tipo === 'domain'
                ? (formulario.elements.name_full.value || '').trim().toLowerCase()
                : (formulario.elements.name_sub.value || '').trim().toLowerCase(),
            domain_id: formulario.elements.domain_id ? formulario.elements.domain_id.value : 0,
            allocation_id: formulario.elements.allocation_id.value,
            ssl_mode: tipo === 'srv' ? 'none' : (modo ? modo.value : 'none'),
            ssl_cert: formulario.elements.ssl_cert ? formulario.elements.ssl_cert.value : '',
            ssl_key: formulario.elements.ssl_key ? formulario.elements.ssl_key.value : '',
        };

        if (!cuerpoPeticion.name) {
            mostrarResultado(false, 'Escribe el nombre del dominio.');
            return;
        }

        estado.aviso = null;
        estado.enviando = true;

        var boton = formulario.querySelector('button[type="submit"]');
        var originalBoton = boton.innerHTML;
        boton.disabled = true;
        boton.innerHTML = '<span class="dnsrev-spin">' + icono('spinner', 16) + '</span><span>Creando...</span>';

        mostrarResultado(null, 'Creando el dominio y pidiendo el certificado. Esto puede tardar hasta un minuto, no cierres la ventana.');

        peticion('POST', '/server/' + encodeURIComponent(estado.servidor), cuerpoPeticion, function (codigo, datos) {
            estado.enviando = false;
            boton.disabled = false;
            boton.innerHTML = originalBoton;

            if (codigo >= 200 && codigo < 300 && datos && datos.ok) {
                var creado = (datos.record && datos.record.domain) ? datos.record.domain : 'Tu dominio';

                estado.aviso = {
                    tipo: 'ok',
                    texto: creado + ' ya esta configurado. Si acabas de crear el registro DNS, puede tardar '
                        + 'unos minutos en verse desde todos los sitios.',
                };

                mostrarResultado(true, 'Listo, ya esta creado.');
                window.setTimeout(cargar, 900);
                return;
            }

            mostrarResultado(false, (datos && datos.error) || 'No se pudo crear el DNS. Intentalo de nuevo en unos minutos.');
        });
    }

    function mostrarResultado(bien, mensaje) {
        var caja = estado.raiz ? estado.raiz.querySelector('.dnsrev-form-result') : null;

        if (!caja) {
            return;
        }

        caja.hidden = false;
        caja.className = 'dnsrev-form-result ' +
            (bien === true ? 'dnsrev-result-ok' : bien === false ? 'dnsrev-result-error' : 'dnsrev-result-info');
        caja.innerHTML = icono(bien === true ? 'check' : bien === false ? 'alert' : 'info', 16) +
            '<span>' + escapar(mensaje) + '</span>';
    }

    function borrar(id, dominio, boton) {
        if (!window.confirm('¿Seguro que quieres borrar ' + dominio + '?\n\nSe quitara el registro DNS y la configuracion del servidor. Tu servidor y tus archivos no se tocan.')) {
            return;
        }

        estado.aviso = null;
        boton.disabled = true;
        boton.innerHTML = '<span class="dnsrev-spin">' + icono('spinner', 15) + '</span><span>Borrando...</span>';

        peticion('DELETE', '/server/' + encodeURIComponent(estado.servidor) + '/' + encodeURIComponent(id), null, function (codigo, datos) {
            if (codigo >= 200 && codigo < 300) {
                var avisos = (datos && datos.warnings) || [];

                estado.aviso = {
                    tipo: avisos.length ? 'error' : 'ok',
                    texto: avisos.length
                        ? dominio + ' se borro, pero: ' + avisos.join(' ')
                        : dominio + ' se ha borrado.',
                };

                cargar();
                return;
            }

            boton.disabled = false;
            boton.innerHTML = icono('trash', 15) + '<span>Borrar</span>';
            window.alert((datos && datos.error) || 'No se pudo borrar. Intentalo de nuevo.');
        });
    }

    // --- Arranque -----------------------------------------------------------

    function repasar() {
        var ruta = window.location.pathname;

        if (ruta === estado.ultimaRuta) {
            ponerEntrada();
            return;
        }

        estado.ultimaRuta = ruta;
        estado.servidor = servidorActual();

        ponerEntrada();

        var esNuestra = new RegExp('/server/[^/]+/' + RUTA + '/?$').test(ruta);

        if (esNuestra && !estado.abierto) {
            abrir();
        } else if (!esNuestra && estado.abierto) {
            // El cliente le dio a "atras": se cierra sin volver a tocar la URL.
            estado.abierto = false;
            document.body.classList.remove('dnsrev-open');

            if (estado.raiz && estado.raiz.parentNode) {
                estado.raiz.parentNode.removeChild(estado.raiz);
            }

            estado.raiz = null;
        }
    }

    function arrancar() {
        if (!document.body) {
            return;
        }

        repasar();

        // El panel es una aplicacion de una sola pagina: la URL cambia sin
        // recargar y la barra del servidor se vuelve a dibujar sola.
        window.setInterval(repasar, 700);
        window.addEventListener('popstate', repasar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', arrancar);
    } else {
        arrancar();
    }
})();
