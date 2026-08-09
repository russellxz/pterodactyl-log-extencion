/*!
 * LogsPterodactyl - aviso de instalacion en el area de cliente
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

    var config = window.LogsPterodactylConfig || {};
    var ENDPOINT = (config.endpoint || '/api/logspterodactyl').replace(/\/$/, '');
    var POLL_MS = 10000;

    var state = {
        server: null,
        timer: null,
        card: null,
        cardMode: null,
        busy: false,
        wasInstalling: false,
        reloaded: false,
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
        unlock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
        close: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        spinner: '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
    };

    function icon(name, size) {
        return (
            '<svg class="logspterodactyl-icon" xmlns="http://www.w3.org/2000/svg" width="' +
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

    // Cuando el cliente cierra el aviso de "instalacion detenida" no tiene
    // ninguna gracia que le vuelva a salir al recargar la pagina, asi que se
    // recuerda por servidor y parada mientras dure la pestana.
    function dismissKey(server, stoppedId) {
        return 'logspterodactyl:dismissed:' + server + ':' + (stoppedId || '-');
    }

    function dismiss(server, stoppedId) {
        state.dismissed[dismissKey(server, stoppedId)] = true;

        try {
            window.sessionStorage.setItem(dismissKey(server, stoppedId), '1');
        } catch (e) {
            // Navegador sin sessionStorage o en modo privado: da igual.
        }
    }

    function isDismissed(server, stoppedId) {
        if (state.dismissed[dismissKey(server, stoppedId)]) {
            return true;
        }

        try {
            return window.sessionStorage.getItem(dismissKey(server, stoppedId)) === '1';
        } catch (e) {
            return false;
        }
    }

    // --- Tarjeta ------------------------------------------------------------

    function build(data) {
        return data.mode.indexOf('stopped') === 0 ? buildStopped(data) : buildStuck(data);
    }

    function shell(inner) {
        var wrapper = document.createElement('div');
        wrapper.className = 'logspterodactyl-overlay';
        wrapper.setAttribute('role', 'status');
        wrapper.innerHTML = '<div class="logspterodactyl-card">' +
            '  <button type="button" class="logspterodactyl-dismiss" title="Ocultar este aviso" aria-label="Ocultar este aviso">' +
            icon('close', 16) + '</button>' + inner + '</div>';
        return wrapper;
    }

    var CHECKLIST =
        '<ul class="logspterodactyl-checks">' +
        '  <li>' + icon('key', 15) + '<span><strong>Token</strong> mal escrito o caducado.</span></li>' +
        '  <li>' + icon('user', 15) + '<span><strong>Nombre de usuario</strong> incorrecto.</span></li>' +
        '  <li>' + icon('lock', 15) + '<span><strong>Repositorio privado</strong> sin un token con permiso de lectura.</span></li>' +
        '  <li>' + icon('link', 15) + '<span><strong>Version o enlace</strong> de descarga que no existe.</span></li>' +
        '</ul>';

    // Tarjeta 1: la instalacion se esta alargando y se ofrece pararla.
    function buildStuck(data) {
        var wrapper = shell(
            '  <div class="logspterodactyl-head">' +
            '    <span class="logspterodactyl-badge">' + icon('alert', 18) + '</span>' +
            '    <div>' +
            '      <h3 class="logspterodactyl-title">La instalacion esta tardando mas de lo normal</h3>' +
            '      <p class="logspterodactyl-subtitle">' + icon('clock', 14) +
            '        <span>Lleva <strong class="logspterodactyl-minutes">' + data.minutes + '</strong> minutos. Lo habitual son unos pocos.</span>' +
            '      </p>' +
            '    </div>' +
            '  </div>' +
            (data.nodeIssue
                ? NODE_NOTE
                : '  <p class="logspterodactyl-lead">' +
                  '    Antes de detenerla, revisa los datos que pusiste al crear el servidor.' +
                  '    Casi siempre la instalacion se queda atascada por uno de estos motivos:' +
                  '  </p>' + CHECKLIST) +
            '  <div class="logspterodactyl-actions">' +
            '    <button type="button" class="logspterodactyl-btn logspterodactyl-btn-danger">' +
            icon('stop', 16) + '<span>Parar la instalacion</span></button>' +
            '    <span class="logspterodactyl-hint">Se para y tu servidor pasa a otro puerto. No se borra nada.</span>' +
            '  </div>' +
            '  <div class="logspterodactyl-result" hidden></div>'
        );

        wrapper.querySelector('.logspterodactyl-dismiss').addEventListener('click', function () {
            dismiss(data.serverId, data.attemptId);
            remove();
        });

        wrapper.querySelector('.logspterodactyl-btn-danger').addEventListener('click', function () {
            confirmCancel(wrapper, data);
        });

        return wrapper;
    }

    // Cuando el problema esta en el nodo no se le pide al cliente que revise
    // nada: sus datos pueden estar perfectos y lo unico que se conseguiria es
    // que rompiera una configuracion que ya iba bien.
    var NODE_NOTE =
        '<p class="logspterodactyl-lead">' +
        '<strong>Esta vez no es cosa tuya.</strong> El servidor no ha podido empezar a ' +
        'instalarse por un problema en la maquina donde esta alojado, no por los datos ' +
        'que pusiste. Nuestro equipo ya lo tiene localizado.' +
        '</p>' +
        '<p class="logspterodactyl-hint">No hace falta que cambies nada. Si en un rato ' +
        'sigue igual, escribe al soporte y te lo miramos.</p>';

    // Tarjeta 2: la instalacion se detuvo. El servidor ya esta desbloqueado
    // (marcado como instalado) y con su puerto nuevo, asi que aqui solo se
    // explica que hacer: revisar los datos y volver a instalar.
    function buildStopped(data) {
        if (data.nodeIssue) {
            var aviso = shell(
                '  <div class="logspterodactyl-head">' +
                '    <span class="logspterodactyl-badge">' + icon('alert', 18) + '</span>' +
                '    <div>' +
                '      <h3 class="logspterodactyl-title">Instalacion detenida</h3>' +
                '      <p class="logspterodactyl-subtitle">' + icon('unlock', 14) +
                '        <span>Tu servidor ya esta desbloqueado y puedes entrar.</span>' +
                '      </p>' +
                '    </div>' +
                '  </div>' +
                (data.address
                    ? '  <p class="logspterodactyl-lead">Tu servidor esta ahora en <strong>' +
                      escapeHtml(data.address) + '</strong>.</p>'
                    : '') +
                NODE_NOTE
            );

            aviso.querySelector('.logspterodactyl-dismiss').addEventListener('click', function () {
                dismiss(data.serverId, data.stoppedId);
                remove();
            });

            return aviso;
        }

        var wrapper = shell(
            '  <div class="logspterodactyl-head">' +
            '    <span class="logspterodactyl-badge logspterodactyl-badge-ok">' + icon('check', 18) + '</span>' +
            '    <div>' +
            '      <h3 class="logspterodactyl-title">Instalacion detenida</h3>' +
            '      <p class="logspterodactyl-subtitle">' + icon('unlock', 14) +
            '        <span>Tu servidor ya esta desbloqueado: puedes entrar y revisar la configuracion.</span>' +
            '      </p>' +
            '    </div>' +
            '  </div>' +
            (data.address
                ? '  <p class="logspterodactyl-lead">Tu servidor esta ahora en <strong>' +
                  escapeHtml(data.address) + '</strong>.</p>'
                : '') +
            '  <p class="logspterodactyl-lead">Repasa esto antes de volver a instalar:</p>' + CHECKLIST +
            '  <p class="logspterodactyl-hint">Cuando lo tengas corregido, entra en ' +
            '<strong>Ajustes</strong> y pulsa <strong>Reinstalar servidor</strong>.</p>'
        );

        wrapper.querySelector('.logspterodactyl-dismiss').addEventListener('click', function () {
            dismiss(data.serverId, data.stoppedId);
            remove();
        });

        return wrapper;
    }

    function confirmCancel(wrapper, data) {
        var actions = wrapper.querySelector('.logspterodactyl-actions');

        if (actions.getAttribute('data-confirming') === '1') {
            return;
        }

        actions.setAttribute('data-confirming', '1');
        actions.innerHTML =
            '<span class="logspterodactyl-confirm-text">¿Seguro que quieres detenerla?</span>' +
            '<button type="button" class="logspterodactyl-btn logspterodactyl-btn-danger logspterodactyl-confirm-yes">' +
            icon('check', 16) +
            '<span>Si, parar</span></button>' +
            '<button type="button" class="logspterodactyl-btn logspterodactyl-btn-ghost logspterodactyl-confirm-no">' +
            '<span>Cancelar</span></button>';

        actions.querySelector('.logspterodactyl-confirm-no').addEventListener('click', function () {
            actions.removeAttribute('data-confirming');
            actions.innerHTML =
                '<button type="button" class="logspterodactyl-btn logspterodactyl-btn-danger">' +
                icon('stop', 16) +
                '<span>Parar la instalacion</span></button>' +
                '<span class="logspterodactyl-hint">No se borra nada.</span>';
            actions.querySelector('.logspterodactyl-btn-danger').addEventListener('click', function () {
                confirmCancel(wrapper, data);
            });
        });

        actions.querySelector('.logspterodactyl-confirm-yes').addEventListener('click', function () {
            doCancel(wrapper, data);
        });
    }

    function doCancel(wrapper, data) {
        if (state.busy) {
            return;
        }

        state.busy = true;

        var actions = wrapper.querySelector('.logspterodactyl-actions');
        actions.innerHTML =
            '<button type="button" class="logspterodactyl-btn logspterodactyl-btn-danger" disabled>' +
            '<span class="logspterodactyl-spin">' + icon('spinner', 16) + '</span>' +
            '<span>Parando...</span></button>';

        request('POST', '/server/' + encodeURIComponent(data.serverId) + '/cancel-install', function (status, payload) {
            state.busy = false;
            var result = wrapper.querySelector('.logspterodactyl-result');
            result.hidden = false;

            if (status >= 200 && status < 300 && payload && payload.ok) {
                actions.innerHTML = '';
                result.className = 'logspterodactyl-result logspterodactyl-result-ok';
                result.innerHTML =
                    icon('check', 16) +
                    '<span>' +
                    escapeHtml(payload.message || 'Instalacion detenida.') +
                    (payload.port_changed && payload.new_allocation
                        ? ' Tu nueva direccion es <strong>' + escapeHtml(payload.new_allocation) + '</strong>.'
                        : '') +
                    (payload.unblocked === false
                        ? ''
                        : ' Ya puedes entrar en tu servidor y revisar la configuracion.') +
                    '</span>';

                if (payload.warnings && payload.warnings.length) {
                    result.innerHTML += '<span class="logspterodactyl-hint">' +
                        escapeHtml(payload.warnings.join(' ')) + '</span>';
                }

                state.wasInstalling = true;

                // La aplicacion del panel guarda el estado del servidor en
                // memoria, asi que hay que recargar para que deje de mostrar
                // la pantalla de "instalando".
                window.setTimeout(function () {
                    window.location.reload();
                }, 3500);
                return;
            }

            result.className = 'logspterodactyl-result logspterodactyl-result-error';
            result.innerHTML =
                icon('alert', 16) +
                '<span>' +
                escapeHtml((payload && payload.error) || 'No se pudo detener la instalacion. Intentalo de nuevo en unos minutos.') +
                '</span>';

            actions.innerHTML =
                '<button type="button" class="logspterodactyl-btn logspterodactyl-btn-danger">' +
                icon('stop', 16) +
                '<span>Reintentar</span></button>';
            actions.querySelector('.logspterodactyl-btn-danger').addEventListener('click', function () {
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
        // Si ya hay tarjeta y es del mismo tipo, solo se refrescan los minutos.
        if (state.card && state.cardMode === data.mode) {
            var minutes = state.card.querySelector('.logspterodactyl-minutes');
            if (minutes) {
                minutes.textContent = data.minutes;
            }
            return;
        }

        // Si cambio el tipo (de "tardando" a "fallo") se dibuja la otra.
        if (state.card) {
            remove();
        }

        state.cardMode = data.mode;
        state.card = build(data);
        document.body.appendChild(state.card);

        // Pequeno retardo para que la animacion de entrada se vea.
        window.requestAnimationFrame(function () {
            if (state.card) {
                state.card.classList.add('logspterodactyl-visible');
            }
        });
    }

    function remove() {
        if (!state.card) {
            return;
        }

        var card = state.card;
        state.card = null;
        state.cardMode = null;
        card.classList.remove('logspterodactyl-visible');
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
            state.wasInstalling = false;
            state.reloaded = false;
            remove();
        }

        if (!server) {
            return;
        }

        request('GET', '/server/' + encodeURIComponent(server) + '/install-status', function (status, payload) {
            if (status !== 200 || !payload) {
                return;
            }

            if (payload.installing) {
                state.wasInstalling = true;

                // El descarte va atado al intento concreto: si el cliente
                // cierra el aviso y luego vuelve a instalar, el aviso de la
                // instalacion nueva tiene que salir igualmente.
                if (!payload.can_cancel || isDismissed(server, 'i' + (payload.attempt_id || 0))) {
                    remove();
                    return;
                }

                show({
                    serverId: server,
                    attemptId: 'i' + (payload.attempt_id || 0),
                    minutes: payload.minutes,
                    nodeIssue: !!payload.node_issue,
                    mode: payload.node_issue ? 'stuck-node' : 'stuck',
                });
                return;
            }

            // Ya no esta instalando. Si la pagina se cargo cuando si lo estaba,
            // la aplicacion del panel sigue ensenando "Running Installer"
            // porque guarda el estado en memoria: hay que recargar.
            if (state.wasInstalling && !state.reloaded) {
                state.reloaded = true;
                window.location.reload();
                return;
            }

            // La instalacion se paro hace poco: se recuerda que hay que
            // revisar los datos y donde esta ahora el servidor.
            if (payload.stopped || payload.failed) {
                if (isDismissed(server, payload.stopped_id)) {
                    remove();
                    return;
                }

                show({
                    serverId: server,
                    stoppedId: payload.stopped_id,
                    mode: payload.node_issue ? 'stopped-node' : 'stopped',
                    nodeIssue: !!payload.node_issue,
                    address: payload.address,
                });
                return;
            }

            remove();
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
