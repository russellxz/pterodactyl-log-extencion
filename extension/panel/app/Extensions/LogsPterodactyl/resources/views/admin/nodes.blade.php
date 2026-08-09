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
            La contrasena o la clave se guarda <strong>cifrada</strong> con la APP_KEY del panel y
            no se vuelve a ensenar nunca. Y no hay consola remota: solo se pueden lanzar los tres
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
                                @if($a->last_ok_at)
                                    <span class="logspterodactyl-chip">ultima conexion buena: {{ $a->last_ok_at }}</span>
                                @endif
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
                        @if($nodo['configurado'] && $a->last_error)
                            <div class="logspterodactyl-note logspterodactyl-note-error">
                                @logsicon('alert', 16)
                                <span>
                                    Ultimo fallo ({{ $a->last_checked_at }}): {{ $a->last_error }}
                                </span>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('admin.logspterodactyl.nodes.save', $nodo['id']) }}">
                            {{ csrf_field() }}

                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label>Host o IP</label>
                                    <input type="text" name="host" class="form-control"
                                           value="{{ old('host', $a->host ?? $nodo['fqdn']) }}" required>
                                </div>
                                <div class="col-md-2 form-group">
                                    <label>Puerto SSH</label>
                                    <input type="number" name="port" class="form-control" min="1" max="65535"
                                           value="{{ old('port', $a->port ?? 22) }}" required>
                                </div>
                                <div class="col-md-3 form-group">
                                    <label>Usuario</label>
                                    <input type="text" name="username" class="form-control"
                                           value="{{ old('username', $a->username ?? 'root') }}" required>
                                </div>
                                <div class="col-md-3 form-group">
                                    <label>Como entra</label>
                                    <select name="auth_type" class="form-control">
                                        <option value="password" @if(($a->auth_type ?? 'password') === 'password') selected @endif>
                                            Contrasena
                                        </option>
                                        <option value="key" @if(($a->auth_type ?? '') === 'key') selected @endif>
                                            Clave privada (mas seguro)
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>
                                    Contrasena o clave privada
                                    @if($nodo['configurado'])
                                        <span class="logspterodactyl-muted logspterodactyl-small">
                                            &mdash; ya hay una guardada. Dejalo vacio para no cambiarla.
                                        </span>
                                    @endif
                                </label>
                                <textarea name="secret" class="form-control" rows="3"
                                          placeholder="{{ $nodo['configurado'] ? 'Sin cambios' : 'La contrasena, o el contenido entero del archivo de clave privada' }}"
                                          autocomplete="new-password"></textarea>
                                <p class="text-muted logspterodactyl-small" style="margin-top:6px;">
                                    Si usas clave privada, pega el archivo entero, incluidas las lineas
                                    <code>-----BEGIN ...-----</code> y <code>-----END ...-----</code>.
                                </p>
                            </div>

                            <div class="form-group">
                                <label>Contrasena de la clave privada (si la tiene)</label>
                                <input type="password" name="passphrase" class="form-control"
                                       autocomplete="new-password" placeholder="Solo si tu clave va con contrasena">
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
                                      action="{{ route('admin.logspterodactyl.nodes.test', $nodo['id']) }}">
                                    {{ csrf_field() }}
                                    <button type="submit" class="btn btn-default">Probar conexion</button>
                                </form>

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
@endsection
