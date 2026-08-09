/*!
 * ArixLog - aviso de instalacion en el area de cliente
 *
 * Muestra una tarjeta con instrucciones y un boton para detener la
 * instalacion cuando un servidor lleva demasiado tiempo instalando.
 *
 * No toca React ni recompila nada del panel: es JavaScript suelto que dibuja
 * su propia tarjeta sobre la pantalla. Por eso funciona igual con el panel
 * original y con temas como Arix, que es justo lo que suele romperse cuando
 * una extension se mete en resources/scripts y obliga a un "yarn build".
 */
(function () {
    'use strict';

    var config = window.ArixLogConfig || {};
    var ENDPOINT = (config.endpoint || '/api/arixlog').replace(/\/$/, '');
    var POLL_MS = 20000;

    var state = {
        server: null,
        timer: null,
        card: null,
        busy: false,
        dismissed: {},
        lastPath: null,
    };

    // --- Iconos (SVG en linea, sin emojis y sin dependencias externas) ------

    var ICONS = {
        alert:
            '<path d="M12 9v4"/><path d="M12 17h.01"/>' +
            '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>',
        key: '<circle cx="7.5" cy="15.5" r="3.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
        user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        lock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        stop: '<circle cx="12" cy="12" r="10"/><rect width="6" height="6" x="9" y="9" rx="1"/>',
        clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        check: '<path d="M20 6 9 17l-5-5"/>',
        close: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        spinner: '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
    };

    function icon(name, size) {
        return (
            '<svg class="arixlog-icon" xmlns="http://www.w3.org/2000/svg" width="' +
            (size || 16) +
            '" height="' +
            (size || 16) +
            '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' +
            'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            (ICONS[name] || '') +
            '</svg>'
        );
    }

    // --- Utilidades ---------------------------------------------------------

    function currentServer() {
        var match = window.location.pathname.match(/\/server\/([a-zA-Z0-9-]{4,40})(\/|$)/);
        return match ? match[1] : null;
    }

    function request(method, path, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open(method, ENDPOINT + path, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        if (method !== 'GET') {
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', config.csrf || csrfFromPage());
        }

        xhr.onload = function () {
            var payload = null;
            try {
                payload = JSON.parse(xhr.responseText);
            } catch (e) {
                payload = null;
            }
            callback(xhr.status, payload);
        };

        xhr.onerror = function () {
            callback(0, null);
        };

        xhr.send(null);
    }

    function csrfFromPage() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // --- Tarjeta ------------------------------------------------------------

    function build(data) {
        var wrapper = document.createElement('div');
        wrapper.className = 'arixlog-overlay';
        wrapper.setAttribute('role', 'status');
        wrapper.innerHTML =
            '<div class="arixlog-card">' +
            '  <button type="button" class="arixlog-dismiss" title="Ocultar este aviso" aria-label="Ocultar este aviso">' +
            icon('close', 16) +
            '  </button>' +
            '  <div class="arixlog-head">' +
            '    <span class="arixlog-badge">' + icon('alert', 18) + '</span>' +
            '    <div>' +
            '      <h3 class="arixlog-title">La instalacion esta tardando mas de lo normal</h3>' +
            '      <p class="arixlog-subtitle">' +
            icon('clock', 14) +
            '        <span>Lleva <strong class="arixlog-minutes">' + data.minutes + '</strong> minutos. Lo habitual son unos pocos.</span>' +
            '      </p>' +
            '    </div>' +
            '  </div>' +
            '  <p class="arixlog-lead">' +
            '    Antes de detenerla, revisa los datos que pusiste al crear el servidor.' +
            '    Casi siempre la instalacion se queda atascada por uno de estos motivos:' +
            '  </p>' +
            '  <ul class="arixlog-checks">' +
            '    <li>' + icon('key', 15) + '<span><strong>Token</strong> mal escrito o caducado.</span></li>' +
            '    <li>' + icon('user', 15) + '<span><strong>Nombre de usuario</strong> incorrecto.</span></li>' +
            '    <li>' + icon('lock', 15) + '<span><strong>Repositorio privado</strong> sin un token con permiso de lectura.</span></li>' +
            '    <li>' + icon('link', 15) + '<span><strong>Version o enlace</strong> de descarga que no existe.</span></li>' +
            '  </ul>' +
            '  <div class="arixlog-actions">' +
            '    <button type="button" class="arixlog-btn arixlog-btn-danger">' +
            icon('stop', 16) +
            '      <span>Detener la instalacion</span>' +
            '    </button>' +
            '    <span class="arixlog-hint">Podras corregir los datos y volver a instalar.</span>' +
            '  </div>' +
            '  <div class="arixlog-result" hidden></div>' +
            '</div>';

        wrapper.querySelector('.arixlog-dismiss').addEventListener('click', function () {
            state.dismissed[data.serverId] = true;
            remove();
        });

        wrapper.querySelector('.arixlog-btn-danger').addEventListener('click', function () {
            confirmCancel(wrapper, data);
        });

        return wrapper;
    }

    function confirmCancel(wrapper, data) {
        var actions = wrapper.querySelector('.arixlog-actions');

        if (actions.getAttribute('data-confirming') === '1') {
            return;
        }

        actions.setAttribute('data-confirming', '1');
        actions.innerHTML =
            '<span class="arixlog-confirm-text">¿Seguro que quieres detenerla?</span>' +
            '<button type="button" class="arixlog-btn arixlog-btn-danger arixlog-confirm-yes">' +
            icon('check', 16) +
            '<span>Si, detener</span></button>' +
            '<button type="button" class="arixlog-btn arixlog-btn-ghost arixlog-confirm-no">' +
            '<span>Cancelar</span></button>';

        actions.querySelector('.arixlog-confirm-no').addEventListener('click', function () {
            actions.removeAttribute('data-confirming');
            actions.innerHTML =
                '<button type="button" class="arixlog-btn arixlog-btn-danger">' +
                icon('stop', 16) +
                '<span>Detener la instalacion</span></button>' +
                '<span class="arixlog-hint">Podras corregir los datos y volver a instalar.</span>';
            actions.querySelector('.arixlog-btn-danger').addEventListener('click', function () {
                confirmCancel(wrapper, data);
            });
        });

        actions.querySelector('.arixlog-confirm-yes').addEventListener('click', function () {
            doCancel(wrapper, data);
        });
    }

    function doCancel(wrapper, data) {
        if (state.busy) {
            return;
        }

        state.busy = true;

        var actions = wrapper.querySelector('.arixlog-actions');
        actions.innerHTML =
            '<button type="button" class="arixlog-btn arixlog-btn-danger" disabled>' +
            '<span class="arixlog-spin">' + icon('spinner', 16) + '</span>' +
            '<span>Deteniendo...</span></button>';

        request('POST', '/server/' + encodeURIComponent(data.serverId) + '/cancel-install', function (status, payload) {
            state.busy = false;
            var result = wrapper.querySelector('.arixlog-result');
            result.hidden = false;

            if (status >= 200 && status < 300 && payload && payload.ok) {
                actions.innerHTML = '';
                result.className = 'arixlog-result arixlog-result-ok';
                result.innerHTML =
                    icon('check', 16) +
                    '<span>' +
                    escapeHtml(payload.message || 'Instalacion detenida.') +
                    (payload.port_changed && payload.new_allocation
                        ? ' Tu nueva direccion es <strong>' + escapeHtml(payload.new_allocation) + '</strong>.'
                        : '') +
                    '</span>';

                window.setTimeout(function () {
                    window.location.reload();
                }, 4000);
                return;
            }

            result.className = 'arixlog-result arixlog-result-error';
            result.innerHTML =
                icon('alert', 16) +
                '<span>' +
                escapeHtml((payload && payload.error) || 'No se pudo detener la instalacion. Intentalo de nuevo en unos minutos.') +
                '</span>';

            actions.innerHTML =
                '<button type="button" class="arixlog-btn arixlog-btn-danger">' +
                icon('stop', 16) +
                '<span>Reintentar</span></button>';
            actions.querySelector('.arixlog-btn-danger').addEventListener('click', function () {
                doCancel(wrapper, data);
            });
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function show(data) {
        if (state.card) {
            var minutes = state.card.querySelector('.arixlog-minutes');
            if (minutes) {
                minutes.textContent = data.minutes;
            }
            return;
        }

        state.card = build(data);
        document.body.appendChild(state.card);

        // Pequeno retardo para que la animacion de entrada se vea.
        window.requestAnimationFrame(function () {
            if (state.card) {
                state.card.classList.add('arixlog-visible');
            }
        });
    }

    function remove() {
        if (!state.card) {
            return;
        }

        var card = state.card;
        state.card = null;
        card.classList.remove('arixlog-visible');
        window.setTimeout(function () {
            if (card.parentNode) {
                card.parentNode.removeChild(card);
            }
        }, 200);
    }

    // --- Ciclo de comprobacion ---------------------------------------------

    function check() {
        var server = currentServer();

        // Al cambiar de servidor (el panel es una SPA) se limpia la tarjeta.
        if (server !== state.server) {
            state.server = server;
            remove();
        }

        if (!server) {
            return;
        }

        if (state.dismissed[server]) {
            return;
        }

        request('GET', '/server/' + encodeURIComponent(server) + '/install-status', function (status, payload) {
            if (status !== 200 || !payload) {
                return;
            }

            if (!payload.installing || !payload.can_cancel) {
                remove();
                return;
            }

            show({ serverId: server, minutes: payload.minutes });
        });
    }

    function start() {
        if (!document.body) {
            return;
        }

        check();
        state.timer = window.setInterval(function () {
            // Con la pestana en segundo plano no tiene sentido preguntar.
            if (document.hidden) {
                return;
            }
            check();
        }, POLL_MS);

        // El panel es una aplicacion de una sola pagina: la URL cambia sin
        // recargar, asi que hay que vigilar la navegacion.
        window.setInterval(function () {
            if (window.location.pathname !== state.lastPath) {
                state.lastPath = window.location.pathname;
                check();
            }
        }, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
