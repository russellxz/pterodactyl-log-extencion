@extends('logspterodactyl::admin._layout')

@section('logspterodactyl-title', 'Nodos')
@section('logspterodactyl-heading', 'Acceso a los nodos')
@section('logspterodactyl-subheading', 'para soltar el bloqueo de instalacion de wings sin entrar a mano')

@section('logspterodactyl-content')
    @if(!$disponible)
        <div class="logspterodactyl-note logspterodactyl-note-error">
            @logsicon('alert', 16)
            <span>
                Falta <strong>phpseclib</strong> en el panel, que es lo que usa la extension para
                conectarse por SSH. Viene de serie con Pterodactyl, asi que basta con ejecutar
                <code>composer install</code> en la carpeta del panel.
            </span>
        </div>
    @endif

    <div class="logspterodactyl-note logspterodactyl-note-info">
        @logsicon('shield', 16)
        <span>
            <strong>Para que sirve esto.</strong> Wings guarda en su memoria un "estoy instalando"
            por servidor y no lo suelta hasta que el contenedor de instalacion termina. Si ese
            contenedor se queda colgado, rechaza cualquier instalacion nueva de ese servidor y no
            hay forma de arreglarlo desde el panel: su API no tiene ninguna orden para cancelar una
            instalacion. Con el acceso puesto aqui, la extension entra en la maquina y mata ese
            contenedor (solo ese: los archivos del servidor viven en otro sitio y no se tocan).
            <br><br>
            La contrasena se guarda <strong>cifrada</strong> con la APP_KEY del panel y no se
            vuelve a ensenar nunca. Y no hay consola remota: solo se pueden lanzar los tres
            comandos concretos que necesita esto.
        </span>
    </div>

    @foreach($nodos as $nodo)
        @php $a = $nodo['acceso']; @endphp
        <div class="row">
            <div class="col-xs-12">
                <div class="box {{ $nodo['configurado'] ? 'box-primary' : '' }}">
                    <div class="box-header with-border">
                        <h3 class="box-title">
                            {{ $nodo['name'] }}
                            <span class="logspterodactyl-muted logspterodactyl-small">{{ $nodo['fqdn'] }}</span>
                        </h3>
                        <div class="box-tools">
                            @if($nodo['configurado'])
                                @if($a->auto_fix)
                                    <span class="logspterodactyl-chip">se arregla solo</span>
                                @endif
                                @if(!$a->enabled)
                                    <span class="logspterodactyl-chip">desactivado</span>
                                @endif
                            @else
                                <span class="logspterodactyl-muted logspterodactyl-small">sin configurar</span>
                            @endif
                        </div>
                    </div>

                    <div class="box-body">
                        @if($nodo['configurado'])
                            {{-- Semaforo de conexion. Se rellena al pulsar el boton. --}}
                            <div class="logspterodactyl-estado" data-node="{{ $nodo['id'] }}">
                                <button type="button" class="btn btn-primary logspterodactyl-comprobar"
                                        data-url="{{ route('admin.logspterodactyl.nodes.check', $nodo['id']) }}"
                                        @if(!$disponible) disabled @endif>
                                    @logsicon('activity', 14) Comprobar conexion
                                </button>
                                <span class="logspterodactyl-resultado logspterodactyl-muted">
                                    @if($a->last_ok_at)
                                        Ultima conexion buena: {{ $a->last_ok_at }}
                                    @elseif($a->last_error)
                                        Ultimo intento fallido: {{ $a->last_error }}
                                    @else
                                        Todavia no se ha probado.
                                    @endif
                                </span>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('admin.logspterodactyl.nodes.save', $nodo['id']) }}">
                            {{ csrf_field() }}

                            <div class="row">
                                <div class="col-md-5 form-group">
                                    <label>IP o dominio del nodo</label>
                                    <input type="text" name="host" class="form-control"
                                           value="{{ old('host', $a->host ?? $nodo['fqdn']) }}" required>
                                </div>
                                <div class="col-md-3 form-group">
                                    <label>Puerto SSH</label>
                                    <input type="number" name="port" class="form-control" min="1" max="65535"
                                           value="{{ old('port', $a->port ?? 22) }}" required>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label>Usuario</label>
                                    <input type="text" name="username" class="form-control"
                                           value="{{ old('username', $a->username ?? 'root') }}" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>
                                    Contrasena SSH
                                    @if($nodo['configurado'])
                                        <span class="logspterodactyl-muted logspterodactyl-small">
                                            &mdash; ya hay una guardada. Dejalo vacio para no cambiarla.
                                        </span>
                                    @endif
                                </label>
                                <input type="password" name="secret" class="form-control"
                                       autocomplete="new-password"
                                       placeholder="{{ $nodo['configurado'] ? 'Sin cambios' : 'La contrasena de ese usuario en el nodo' }}">
                            </div>

                            <div class="form-group">
                                <label class="logspterodactyl-switch">
                                    <input type="checkbox" name="enabled" value="1"
                                           @if(!$nodo['configurado'] || $a->enabled) checked @endif>
                                    <span>Acceso activo</span>
                                </label>
                                <label class="logspterodactyl-switch">
                                    <input type="checkbox" name="auto_fix" value="1"
                                           @if($nodo['configurado'] && $a->auto_fix) checked @endif>
                                    <span>
                                        Arreglarlo solo: cuando se detecte que el nodo rechaza una instalacion
                                        por tener el contenedor anterior colgado, entrar y eliminarlo
                                    </span>
                                </label>
                            </div>

                            <button type="submit" class="btn btn-primary" @if(!$disponible) disabled @endif>
                                Guardar acceso
                            </button>
                        </form>

                        @if($nodo['configurado'])
                            <hr>
                            <div>
                                <form method="POST" style="display:inline;"
                                      action="{{ route('admin.logspterodactyl.nodes.forget', $nodo['id']) }}"
                                      onsubmit="return confirm('Se olvidara la huella guardada de esta maquina. Hazlo solo si TU has reinstalado la VPS o cambiado su clave de servidor. ¿Continuar?');">
                                    {{ csrf_field() }}
                                    <button type="submit" class="btn btn-warning">Olvidar huella</button>
                                </form>

                                <form method="POST" style="display:inline;"
                                      action="{{ route('admin.logspterodactyl.nodes.restart', $nodo['id']) }}"
                                      onsubmit="return confirm('Se reiniciara wings en este nodo. Los servidores siguen encendidos, pero la consola se corta unos segundos. ¿Continuar?');">
                                    {{ csrf_field() }}
                                    <button type="submit" class="btn btn-default">Reiniciar wings</button>
                                </form>

                                <form method="POST" style="display:inline; float:right;"
                                      action="{{ route('admin.logspterodactyl.nodes.delete', $nodo['id']) }}"
                                      onsubmit="return confirm('¿Borrar el acceso guardado de este nodo?');">
                                    {{ csrf_field() }}
                                    <button type="submit" class="btn btn-default">Borrar acceso</button>
                                </form>
                            </div>

                            <p class="logspterodactyl-muted logspterodactyl-small" style="margin-top:12px;">
                                Huella guardada:
                                <code>{{ $a->fingerprint ?: 'todavia ninguna (se guarda en la primera conexion)' }}</code>
                            </p>
                            <p class="logspterodactyl-muted logspterodactyl-small">
                                Si reinstalas la VPS, su clave de servidor cambia y la conexion se rechaza
                                (es el mismo aviso de "REMOTE HOST IDENTIFICATION HAS CHANGED" de siempre).
                                Pulsa <strong>Olvidar huella</strong> y ya esta: no hace falta tocar ningun
                                <code>known_hosts</code> a mano.
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if($nodos === [])
        <p class="logspterodactyl-empty">No hay ningun nodo dado de alta en el panel.</p>
    @endif

    <script>
        (function () {
            var botones = document.querySelectorAll('.logspterodactyl-comprobar');

            function pintar(caja, clase, texto) {
                var salida = caja.querySelector('.logspterodactyl-resultado');
                salida.className = 'logspterodactyl-resultado ' + clase;
                salida.innerHTML = texto;
            }

            function escapar(v) {
                return String(v == null ? '' : v)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            for (var i = 0; i < botones.length; i++) {
                botones[i].addEventListener('click', function () {
                    var boton = this;
                    var caja = boton.closest('.logspterodactyl-estado');

                    boton.disabled = true;
                    pintar(caja, 'logspterodactyl-muted', 'Conectando con el nodo...');

                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', boton.getAttribute('data-url'), true);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                    xhr.onload = function () {
                        boton.disabled = false;
                        var d = null;
                        try { d = JSON.parse(xhr.responseText); } catch (e) { d = null; }

                        if (!d) {
                            pintar(caja, 'logspterodactyl-estado-mal',
                                'No se pudo comprobar (respuesta del panel: ' + xhr.status + ').');
                            return;
                        }

                        if (!d.ok) {
                            pintar(caja, 'logspterodactyl-estado-mal',
                                '<strong>Sin conexion.</strong> ' + escapar(d.mensaje));
                            return;
                        }

                        var detalle = '<strong>Conectado.</strong> ' + escapar(d.mensaje)
                            + ' <span class="logspterodactyl-small">Docker: ' + escapar(d.docker || 'no responde')
                            + ' &middot; Wings: ' + escapar(d.wings || '?')
                            + ' &middot; comprobado a las ' + escapar(d.revisado) + '</span>';

                        if (d.avisos && d.avisos.length) {
                            detalle += '<div class="logspterodactyl-small">Ojo: ' + escapar(d.avisos.join('. ')) + '.</div>';
                        }

                        pintar(caja, d.avisos && d.avisos.length
                            ? 'logspterodactyl-estado-regular'
                            : 'logspterodactyl-estado-bien', detalle);
                    };

                    xhr.onerror = function () {
                        boton.disabled = false;
                        pintar(caja, 'logspterodactyl-estado-mal', 'No se pudo hablar con el panel.');
                    };

                    xhr.send(null);
                });
            }
        })();
    </script>
@endsection
