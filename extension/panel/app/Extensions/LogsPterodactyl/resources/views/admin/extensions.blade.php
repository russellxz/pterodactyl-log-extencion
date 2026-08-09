@extends('logspterodactyl::admin._layout')

@section('logspterodactyl-title', 'Otras extensiones')
@section('logspterodactyl-heading', 'Otras extensiones del panel')
@section('logspterodactyl-subheading', 'copias de seguridad antes de actualizar, y volver a ponerlas despues')

@section('logspterodactyl-content')
    @php
        $formatear = function (int $bytes): string {
            $u = ['B', 'KB', 'MB', 'GB'];
            $i = 0;
            while ($bytes >= 1024 && $i < 3) { $bytes /= 1024; $i++; }
            return round($bytes, $i === 0 ? 0 : 1) . ' ' . $u[$i];
        };
    @endphp

    @if(!$zipDisponible)
        <div class="logspterodactyl-note logspterodactyl-note-error">
            @logsicon('alert', 16)
            <span>
                Falta la extension <strong>zip</strong> de PHP y sin ella no se pueden crear las
                copias. Instalala con <code>apt install php8.3-zip</code> (o la version de PHP que
                uses) y reinicia PHP-FPM.
            </span>
        </div>
    @endif

    @if($error)
        <div class="logspterodactyl-note logspterodactyl-note-error">
            @logsicon('alert', 16)
            <span>No se pudieron leer las extensiones: {{ $error }}</span>
        </div>
    @endif

    <div class="row">
        <div class="col-xs-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Extensiones detectadas ({{ count($detectadas) }})</h3>
                </div>
                <div class="box-body">
                    <p class="text-muted">
                        Actualizar Pterodactyl descomprime el paquete oficial encima de la carpeta del
                        panel y trae su propio <code>config/app.php</code>. Las carpetas propias de cada
                        extension sobreviven, pero <strong>su linea de proveedor desaparece</strong> y el
                        panel deja de cargarlas; y si la extension reemplazaba archivos del panel, esos si
                        se pisan. De ahi el clasico "he actualizado y se me han ido los addons".
                    </p>

                    @if(count($detectadas) === 0)
                        <p class="logspterodactyl-empty">
                            No se ha encontrado ninguna otra extension instalada.
                        </p>
                    @else
                        <form method="POST" action="{{ route('admin.logspterodactyl.extensions.backup') }}">
                            {{ csrf_field() }}

                            <div class="table-responsive">
                                <table class="table table-hover logspterodactyl-table">
                                    <thead>
                                        <tr>
                                            <th style="width:34px;"></th>
                                            <th>Extension</th>
                                            <th>Como esta instalada</th>
                                            <th>Carpetas</th>
                                            <th>Tamano</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($detectadas as $extension)
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="extensiones[]"
                                                       value="{{ $extension['key'] }}" checked>
                                            </td>
                                            <td>
                                                <strong>{{ $extension['name'] }}</strong>
                                                @if(($extension['providers'] ?? []) !== [])
                                                    <div class="logspterodactyl-muted logspterodactyl-small">
                                                        registra un proveedor en config/app.php
                                                    </div>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="logspterodactyl-chip">{{ $extension['type'] }}</span>
                                                <div class="logspterodactyl-muted logspterodactyl-small">{{ $extension['detail'] }}</div>
                                            </td>
                                            <td>
                                                @foreach($extension['paths'] as $ruta)
                                                    <div class="logspterodactyl-small"><code>{{ $ruta }}</code></div>
                                                @endforeach
                                            </td>
                                            <td>{{ $formatear((int) $extension['size']) }}</td>
                                            <td>
                                                <button type="button" class="btn btn-xs btn-default logspterodactyl-quitar"
                                                        data-key="{{ $extension['key'] }}"
                                                        data-name="{{ $extension['name'] }}">Quitar</button>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <button type="submit" class="btn btn-primary" @if(!$zipDisponible) disabled @endif>
                                Hacer copia de las marcadas
                            </button>
                            <span class="logspterodactyl-muted logspterodactyl-small" style="margin-left:8px;">
                                Se guardan en <code>{{ $carpeta }}</code>
                            </span>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($proveedores !== [])
        <div class="row">
            <div class="col-xs-12">
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Lineas de proveedor que hay ahora en config/app.php</h3>
                    </div>
                    <div class="box-body">
                        <p class="text-muted">
                            Guarda esto en algun sitio. Es lo primero que se pierde al actualizar el panel
                            y lo que hace que una extension "desaparezca" aunque sus archivos sigan ahi.
                        </p>
@foreach($proveedores as $linea)
<pre style="margin:0 0 6px;">{{ $linea }}</pre>
@endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Copias guardadas ({{ count($copias) }})</h3>
                </div>
                <div class="box-body no-padding">
                    @if(count($copias) === 0)
                        <p class="logspterodactyl-empty">
                            Todavia no hay ninguna copia. Hazla antes de actualizar el panel.
                        </p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover logspterodactyl-table">
                                <thead>
                                    <tr>
                                        <th>Archivo</th>
                                        <th>Fecha</th>
                                        <th>Contenido</th>
                                        <th>Tamano</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach($copias as $copia)
                                    <tr>
                                        <td><code>{{ $copia['file'] }}</code></td>
                                        <td>{{ $copia['created_at'] }}</td>
                                        <td>
                                            @forelse($copia['contenido'] as $item)
                                                <span class="logspterodactyl-chip">{{ $item['name'] ?? '?' }}</span>
                                            @empty
                                                <span class="logspterodactyl-muted logspterodactyl-small">sin manifiesto</span>
                                            @endforelse
                                        </td>
                                        <td>{{ $formatear((int) $copia['size']) }}</td>
                                        <td>
                                            <a class="btn btn-xs btn-primary"
                                               href="{{ route('admin.logspterodactyl.extensions.download', $copia['file']) }}">
                                                Descargar
                                            </a>

                                            <form method="POST" style="display:inline;"
                                                  action="{{ route('admin.logspterodactyl.extensions.restore', $copia['file']) }}"
                                                  onsubmit="return confirm('Se volveran a escribir los archivos de esas extensiones encima de los que haya ahora. ¿Continuar?');">
                                                {{ csrf_field() }}
                                                <button type="submit" class="btn btn-xs btn-warning">Restaurar</button>
                                            </form>

                                            <form method="POST" style="display:inline;"
                                                  action="{{ route('admin.logspterodactyl.extensions.delete', $copia['file']) }}"
                                                  onsubmit="return confirm('¿Borrar esta copia?');">
                                                {{ csrf_field() }}
                                                <button type="submit" class="btn btn-xs btn-default">Borrar</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="logspterodactyl-note logspterodactyl-note-info">
        @logsicon('shield', 16)
        <span>
            <strong>Como usarlo al actualizar el panel.</strong> Haz la copia aqui <em>antes</em> de
            lanzar la actualizacion. Cuando termine, vuelve a esta pantalla y pulsa
            <strong>Restaurar</strong>: los archivos vuelven a su sitio exacto. Si alguna extension
            registraba un proveedor, la lista de arriba te dice que linea hay que volver a poner en
            <code>config/app.php</code>; eso no se toca solo a proposito, porque escribir a ciegas en
            ese archivo es la mejor forma de dejar el panel en blanco.
        </span>
    </div>

    <form id="logspterodactyl-quitar-form" method="POST" style="display:none;">
        {{ csrf_field() }}
        <input type="hidden" name="confirmar" id="logspterodactyl-quitar-confirmar">
    </form>

    <script>
        (function () {
            var plantilla = @json(route('admin.logspterodactyl.extensions.remove', '__KEY__'));
            var form = document.getElementById('logspterodactyl-quitar-form');
            var campo = document.getElementById('logspterodactyl-quitar-confirmar');
            var botones = document.querySelectorAll('.logspterodactyl-quitar');

            for (var i = 0; i < botones.length; i++) {
                botones[i].addEventListener('click', function () {
                    var key = this.getAttribute('data-key');
                    var nombre = this.getAttribute('data-name');

                    if (!window.confirm(
                        'Se van a borrar las carpetas de "' + nombre + '" del panel.\n\n' +
                        'Antes se guarda una copia que podras descargar y restaurar.\n' +
                        'Lo que esa extension haya cambiado dentro de archivos del panel NO se deshace.\n\n' +
                        '¿Continuar?'
                    )) {
                        return;
                    }

                    campo.value = key;
                    form.action = plantilla.replace('__KEY__', encodeURIComponent(key));
                    form.submit();
                });
            }
        })();
    </script>
@endsection
